<?php

namespace App\Http\Controllers\Web\Observations;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use App\Models\User;
use App\Support\ObservationFilters;
use App\Support\ObservationLabels;
use App\Support\ObservationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ObservationController extends Controller
{
    private const WITH = ['branch', 'reportingDepartment', 'responsibleDepartment', 'category', 'catalogItem', 'riskLevel', 'ticket'];

    /** The one register query: eager loads + visibility scope + filters. Used by the page and the CSV export. */
    private function query(ObservationFilters $filters, User $user): Builder
    {
        $query = ObservationScope::apply(Observation::query(), $user)->with(self::WITH);

        return $filters->apply($query)->orderByDesc('observations.id');
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Observation::class);

        $filters = ObservationFilters::fromRequest($request);

        return view('dashboard.observations.index', [
            'observations' => $this->query($filters, $request->user())->paginate(30)->withQueryString(),
            'filters' => $filters,
            'range' => $filters->range,
            'reportingDepartments' => Department::where('is_reporting', true)->orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
            'areas' => Area::where('active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'branches' => Branch::where('active', true)->orderBy('branch_name')->get(),
            'categories' => ObservationCategory::where('active', true)
                ->when($filters->get('reporting_department_id'), fn ($q, $id) => $q->where('department_id', $id))
                ->orderBy('sort_order')->orderBy('id')->get(),
            'riskLevels' => RiskLevel::where('active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', Observation::class);

        $query = $this->query(ObservationFilters::fromRequest($request), $request->user());

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Arabic
            fputcsv($out, [
                'المرجع', 'التاريخ', 'الفرع', 'الإدارة', 'الفئة/البند', 'الملاحظة',
                'الريسك', 'المسؤولة', 'الحالة', 'التيكت', 'متأخر؟',
            ]);

            foreach ($query->lazyById(500, 'observations.id', 'id') as $o) {
                fputcsv($out, [
                    $o->reference,
                    $o->event_date?->toDateString(),
                    $o->branch?->branch_name,
                    $o->reportingDepartment?->name,
                    $o->catalogItem ? dt($o->catalogItem->label_ar, $o->catalogItem->label_en) : ($o->category ? dt($o->category->name_ar, $o->category->name_en) : ''),
                    $o->title,
                    $o->riskLevel ? dt($o->riskLevel->name_ar, $o->riskLevel->name_en) : '',
                    $o->responsibleDepartment?->name,
                    ObservationLabels::status($o->status),
                    $o->ticket?->reference,
                    $o->ticket?->isOverdue() ? 'متأخر' : '',
                ]);
            }

            fclose($out);
        }, 'observations-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
