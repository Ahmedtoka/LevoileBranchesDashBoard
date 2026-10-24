<?php

namespace App\Http\Controllers\Web\Observations;

use App\Exceptions\TicketTransitionDenied;
use App\Http\Controllers\Api\ObservationController as ApiObservationController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Observations\Web\CloseNoActionRequest;
use App\Http\Requests\Observations\Web\RaiseRiskRequest;
use App\Http\Requests\Observations\Web\RerouteRequest;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\User;
use App\Services\Observations\ObservationService;
use App\Support\ClassificationPresenter;
use App\Support\ObservationFilters;
use App\Support\ObservationLabels;
use App\Support\ObservationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
        $user = $request->user();

        // Area / store managers only get their own branches (and those branches' areas) in the dropdowns.
        $branchIds = $user->scopedBranchIds();
        $branches = Branch::where('active', true)->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds));
        $areas = Area::where('active', true)->when($branchIds !== null, fn ($q) => $q->whereIn('id', Branch::whereIn('id', $branchIds)->whereNotNull('area_id')->select('area_id')));

        return view('dashboard.observations.index', [
            'observations' => $this->query($filters, $user)->paginate(30)->withQueryString(),
            'filters' => $filters,
            'range' => $filters->range,
            'reportingDepartments' => Department::where('is_reporting', true)->orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
            'areas' => $areas->orderBy('sort_order')->orderBy('name')->get(),
            'branches' => $branches->orderBy('branch_name')->get(),
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
                    self::cell($o->branch?->branch_name),
                    self::cell($o->reportingDepartment?->name),
                    self::cell($o->catalogItem ? dt($o->catalogItem->label_ar, $o->catalogItem->label_en) : ($o->category ? dt($o->category->name_ar, $o->category->name_en) : '')),
                    self::cell($o->title),
                    $o->riskLevel ? dt($o->riskLevel->name_ar, $o->riskLevel->name_en) : '',
                    self::cell($o->responsibleDepartment?->name),
                    ObservationLabels::status($o->status),
                    $o->ticket?->reference,
                    $o->ticket?->isOverdue() ? 'متأخر' : '',
                ]);
            }

            fclose($out);
        }, 'observations-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * GET /observations/new: the laptop visit page (spec 9.3). A vanilla-JS app (public/js/observations-visit.js)
     * that calls Plan B's API mounted under /web-api with the session. The first meta is inlined, read from the
     * API controller itself so there is one meta.
     */
    public function create(Request $request)
    {
        Gate::authorize('create', ObservationVisit::class);

        return view('dashboard.observations.new', [
            'meta' => app(ApiObservationController::class)->meta($request)->getData(true),
            'config' => [
                'urls' => [
                    'api' => url('/web-api'),
                    'register' => route('observations.index'),
                    'observations' => url('/observations'),
                    'tickets' => url('/tickets'),
                ],
                'csrf' => csrf_token(),
                'today' => now()->toDateString(),
                'locale' => app()->getLocale(),
            ],
        ]);
    }

    /** Statuses the before/after page covers: still being worked on, or verified. */
    public const BEFORE_AFTER_STATUSES = ['open', 'in_progress', 'waiting_verification', 'verified_closed', 'reopened'];

    /**
     * GET /observations/before-after ("كنا وبقينا", spec 10): one branch, its open and verified observations
     * with the before / after photos side by side. Needs ?branch_id=; without it only the branch select shows.
     */
    public function beforeAfter(Request $request)
    {
        Gate::authorize('viewAny', Observation::class);

        $user = $request->user();
        $scoped = $user->scopedBranchIds();
        $branches = Branch::where('active', true)
            ->when($scoped !== null, fn ($q) => $q->whereIn('id', $scoped))
            ->orderBy('branch_name')->get();

        $branchId = $request->query('branch_id');
        $branchId = is_scalar($branchId) && ctype_digit((string) $branchId) ? (int) $branchId : null;
        abort_if($branchId !== null && ! $branches->contains('id', $branchId), 403);

        $status = $request->query('status');
        $status = is_string($status) && in_array($status, self::BEFORE_AFTER_STATUSES, true) ? $status : null;

        $observations = null;
        if ($branchId !== null) {
            $observations = ObservationScope::apply(Observation::query(), $user)
                ->where('observations.branch_id', $branchId)
                ->whereIn('observations.status', $status ? [$status] : self::BEFORE_AFTER_STATUSES)
                ->with(['catalogItem', 'category', 'riskLevel', 'ticket.updates', 'verifications.verifier', 'photos'])
                ->orderByDesc('observations.event_date')->orderByDesc('observations.id')
                ->paginate(30)->withQueryString();
        }

        return view('dashboard.observations.before-after', [
            'branches' => $branches,
            'branchId' => $branchId,
            'status' => $status,
            'statuses' => self::BEFORE_AFTER_STATUSES,
            'observations' => $observations,
        ]);
    }

    public function show(Observation $observation)
    {
        Gate::authorize('view', $observation);

        $observation->load([
            'visit', 'branch', 'reportingDepartment', 'responsibleDepartment', 'category', 'catalogItem',
            'riskLevel', 'escalatedFrom', 'creator', 'closer', 'classification',
            'photos' => fn ($q) => $q->orderBy('id'),
            'verifications' => fn ($q) => $q->with('verifier')->orderByDesc('verified_at')->orderByDesc('id'),
            'ticket' => fn ($q) => $q->with(['department', 'assignee']),
            'tickets' => fn ($q) => $q->with(['department', 'assignee', 'updates.user'])->orderByDesc('id'),
        ]);

        // Every ticket of the observation, newest first (a reopen creates a new one); the current one even if unlinked.
        $tickets = $observation->tickets;
        if ($observation->ticket && ! $tickets->contains('id', $observation->ticket->id)) {
            $tickets = $tickets->prepend($observation->ticket->load('updates.user'))->sortByDesc('id')->values();
        }

        $risk = $observation->riskLevel;
        $canRaise = $risk !== null && $risk->slug !== 'critical';

        return view('dashboard.observations.show', [
            'observation' => $observation,
            'ticket' => $observation->ticket,
            'tickets' => $tickets,
            'ccDepartments' => Department::whereIn('id', array_filter((array) $observation->cc_department_ids))->orderBy('name')->get(),
            'extraValues' => $this->extraValues($observation),
            'photoGroups' => $observation->photos->groupBy('kind'),
            'classificationColumns' => ClassificationPresenter::columns($observation->classification),
            'rerouteDepartments' => Department::query()
                ->when($observation->responsible_department_id, fn ($q, $id) => $q->whereKeyNot($id))
                ->orderBy('name')->get(),
            'raiseLevels' => $canRaise
                ? RiskLevel::where('active', true)->where('sort_order', '<', $risk->sort_order)->orderBy('sort_order')->get()
                : collect(),
            'canClose' => ! in_array($observation->status, ObservationService::FINAL_STATUSES, true),
        ]);
    }

    public function reroute(RerouteRequest $request, Observation $observation, ObservationService $service): RedirectResponse
    {
        $service->reroute($observation, (int) $request->validated('responsible_department_id'), $request->user(), $request->validated('reason'));

        return redirect()->route('observations.show', $observation)
            ->with('status', dt('تمت إعادة توجيه الملاحظة.', 'The observation was rerouted.'));
    }

    public function raiseRisk(RaiseRiskRequest $request, Observation $observation, ObservationService $service): RedirectResponse
    {
        $to = RiskLevel::bySlug($request->validated('risk_level_slug'));
        if ($to === null) {
            throw ValidationException::withMessages(['risk_level_slug' => dt('درجة الخطورة غير موجودة.', 'The risk level does not exist.')]);
        }

        $service->raiseRisk($observation, $to, $request->user(), $request->validated('reason'));

        return redirect()->route('observations.show', $observation)
            ->with('status', dt('تم رفع درجة الخطورة.', 'The risk level was raised.'));
    }

    public function closeNoAction(CloseNoActionRequest $request, Observation $observation, ObservationService $service): RedirectResponse
    {
        try {
            $service->closeNoAction($observation, $request->user(), $request->validated('reason'));
        } catch (TicketTransitionDenied $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('observations.show', $observation)
            ->with('status', dt('تم إغلاق الملاحظة بدون إجراء.', 'The observation was closed without action.'));
    }

    /**
     * extra_values as label => text, labels taken from the catalog item's extra_fields when available.
     *
     * @return array<string, string>
     */
    private function extraValues(Observation $observation): array
    {
        $labels = [];
        foreach ((array) ($observation->catalogItem?->extra_fields ?? []) as $field) {
            if (is_array($field) && isset($field['key'])) {
                $ar = (string) ($field['label_ar'] ?? $field['key']);
                $labels[$field['key']] = dt($ar, (string) ($field['label_en'] ?? $ar));
            }
        }

        $rows = [];
        foreach ((array) ($observation->extra_values ?? []) as $key => $value) {
            $text = is_scalar($value) || $value === null
                ? (is_bool($value) ? ($value ? dt('نعم', 'Yes') : dt('لا', 'No')) : (string) $value)
                : json_encode($value, JSON_UNESCAPED_UNICODE);
            $rows[$labels[$key] ?? (string) $key] = $text;
        }

        return $rows;
    }

    /** Neutralise spreadsheet formulas in free-text CSV cells (=, +, -, @ at the start). */
    private static function cell(?string $value): ?string
    {
        return $value !== null && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
