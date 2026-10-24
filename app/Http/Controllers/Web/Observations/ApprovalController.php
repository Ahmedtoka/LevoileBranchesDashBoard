<?php

namespace App\Http\Controllers\Web\Observations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Observations\UpdateObservationRequest;
use App\Http\Resources\Observations\ObservationVisitItem;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Services\Observations\ObservationService;
use App\Support\ObservationScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * /observations/visits/to-approve (Plan H): the reporting department's manager (or ops / super) reviews a
 * submitted visit's points, runs "فحص", corrects responsible / risk per point and approves (= tickets).
 */
class ApprovalController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private ObservationService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $departmentIds = ObservationScope::approvableDepartmentIds($user);
        if ($departmentIds === []) {
            abort(403);
        }

        $visits = ObservationVisit::query()
            ->where('status', 'submitted')
            ->when($departmentIds !== null, fn ($q) => $q->whereIn('department_id', $departmentIds))
            ->when($request->filled('department_id') && $departmentIds === null, fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->with(['department:id,name', 'branch:id,branch_name', 'user:id,name'])
            ->withCount(ObservationVisitItem::counts())
            ->orderBy('submitted_at')->orderBy('id')
            ->get();

        return view('dashboard.observations.approval.index', [
            'visits' => $visits,
            'departments' => $departmentIds === null ? Department::where('is_reporting', true)->orderBy('name')->get() : collect(),
            'departmentId' => $request->integer('department_id') ?: null,
        ]);
    }

    public function show(Request $request, ObservationVisit $visit)
    {
        $this->authorize('supervise', $visit);

        $visit->load(['department:id,name', 'branch:id,branch_name', 'user:id,name', 'approver:id,name'])->loadCount(ObservationVisitItem::counts());
        $observations = $visit->observations()
            ->with(['riskLevel', 'responsibleDepartment:id,name', 'category', 'catalogItem', 'ticket', 'photos', 'classification'])
            ->orderBy('id')->get();

        return view('dashboard.observations.approval.show', [
            'visit' => $visit,
            'observations' => $observations,
            'editable' => $visit->status === 'submitted',
            'allDepartments' => Department::orderBy('name')->get(['id', 'name']),
            'riskLevels' => RiskLevel::where('active', true)->orderBy('sort_order')->get(),
            'categories' => ObservationCategory::where('department_id', $visit->department_id)->where('active', true)->orderBy('sort_order')->orderBy('id')->get(),
            'kinds' => Observation::KINDS,
        ]);
    }

    /** "فحص": run the classifier on the visit's free points; the rows take the suggestions. */
    public function classify(Request $request, ObservationVisit $visit)
    {
        $this->authorize('classify', $visit);

        $result = $this->service->classifyVisit($visit, $request->user());

        return back()->with('status', $result['status'] === 'ok'
            ? dt('تم الفحص: راجع الإدارة المسؤولة ودرجة الخطورة لكل نقطة ثم اعتمد الزيارة.', 'Checked: review the responsible department and risk of each point, then approve the visit.')
            : $result['message']);
    }

    public function updateObservation(UpdateObservationRequest $request, ObservationVisit $visit, Observation $observation)
    {
        abort_unless((int) $observation->observation_visit_id === (int) $visit->id, 404);
        $this->authorize('updateClassification', $observation);

        $this->service->updateClassification($observation, $request->validated(), $request->user());

        return back()->with('status', dt('تم تحديث النقطة', 'Point updated').' '.$observation->reference.'.');
    }

    /** "اعتماد الزيارة": one ticket per issue; refused (errors flashed) while an issue has no responsible department. */
    public function approve(Request $request, ObservationVisit $visit)
    {
        $this->authorize('approve', $visit);

        try {
            $result = $this->service->approveVisit($visit, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->with('status', dt('لا يمكن الاعتماد: حدد الإدارة المسؤولة لكل نقطة أولاً.', 'Cannot approve: set the responsible department of every point first.'));
        }

        $count = count($result['created_tickets']);

        return redirect()->route('obs.approval.show', $visit)
            ->with('status', dt('تم اعتماد الزيارة وإنشاء', 'Visit approved,').' '.$count.' '.dt('طلب.', 'ticket(s) created.'));
    }
}
