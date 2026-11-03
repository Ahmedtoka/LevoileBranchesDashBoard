<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationVisit;
use App\Models\User;
use App\Services\Observations\ObservationService;
use App\Support\CctvDashboard;
use App\Support\CctvReport;
use App\Support\ObservationLabels;
use App\Support\ObservationMetrics;
use App\Support\ObservationScope;
use App\Support\ProblemRouting;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CCTV department's menu (owner request 2026-11-03): the weekly sheet's summary (By Store / By EMP),
 * recording one violation and the violations list as the sheet's columns. Branch staff records live in HR.
 * Violations are ordinary observations of the cctv department, so tickets and approval apply as everywhere.
 */
class CctvController extends Controller
{
    /** Statuses a CCTV violation can be filtered on in the list. */
    private const LIST_STATUSES = ['open', 'in_progress', 'waiting_verification', 'verified_closed', 'reopened', 'closed_no_action'];

    public function summary(Request $request)
    {
        $user = $request->user();
        abort_unless(CctvReport::canView($user), 403);

        // ?branch_id= (one of the user's branches), ?month=YYYY-MM (default: the latest month with data), ?week=1-4.
        $branches = $this->branches($request);
        $branchId = $request->integer('branch_id') ?: null;
        abort_if($branchId !== null && ! $branches->contains('id', $branchId), 403);
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month'))
            ? Carbon::createFromFormat('!Y-m', (string) $request->query('month'))->startOfMonth()
            : CctvDashboard::defaultMonth($user, $branchId);
        $week = in_array($request->integer('week'), range(1, CctvDashboard::WEEKS), true) ? $request->integer('week') : null;

        $d = new CctvDashboard($user, $month, $week, $branchId);
        $base = $d->base();
        $prevBase = $d->previousBase();

        return view('dashboard.cctv.summary', [
            'd' => $d,
            'branches' => $branches,
            'branch' => $branchId ? $branches->firstWhere('id', $branchId) : null,
            'categories' => CctvReport::categories(),
            'byCategory' => CctvDashboard::byCategory($base),
            'prevByCategory' => CctvDashboard::byCategory($prevBase),
            'prevByBranch' => CctvDashboard::byBranch($prevBase),
            'prevByEmployee' => CctvDashboard::byEmployee($prevBase),
            'byStore' => CctvReport::byStore($base),
            'byEmployee' => CctvReport::byEmployee($base),
            'topBranches' => $branchId ? [] : $d->topBranches(5),
            'topViolations' => $d->topViolations(5),
            'topEmployees' => $d->topEmployees(5),
            'weeks' => $d->weeks(),
            'days' => $d->byDay(),
            'canRecord' => CctvReport::canRecord($user),
        ]);
    }

    public function index(Request $request)
    {
        abort_unless(CctvReport::canView($request->user()), 403);
        [$from, $to] = $this->period($request);
        $filtered = $this->listQuery($request, $from, $to);
        $perPage = in_array($request->integer('per_page'), [25, 50, 100, 200], true) ? $request->integer('per_page') : 50;

        return view('dashboard.cctv.index', [
            'from' => $from, 'to' => $to,
            'month' => $this->listMonth($request),
            'week' => in_array($request->integer('week'), range(1, CctvDashboard::WEEKS), true) ? $request->integer('week') : null,
            'violations' => $this->sorted($request, clone $filtered)->paginate($perPage)->withQueryString(),
            'stats' => $this->listStats($filtered),
            'perPage' => $perPage,
            'categories' => CctvReport::categories(),
            'items' => ObservationCatalogItem::whereIn('category_id', CctvReport::categories()->pluck('id'))->orderBy('sort_order')->orderBy('id')->get(['id', 'category_id', 'label_ar']),
            'statuses' => self::LIST_STATUSES,
            'branches' => $this->branches($request),
            'employees' => Employee::with('branch')->where(fn ($q) => $q->whereNotNull('branch_id')->orWhereHas('observations'))->orderBy('name')->get(),
            'departments' => ProblemRouting::departments(),
            'canRecord' => CctvReport::canRecord($request->user()),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(CctvReport::canView($request->user()), 403);
        [$from, $to] = $this->period($request);
        $query = $this->sorted($request, $this->listQuery($request, $from, $to));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['الكود', 'اسم الموظف', 'الفرع', 'تاريخ المخالفة', 'نوع المخالفة', 'المخالفة', 'التفاصيل', 'الجزاء', 'ملاحظات', 'الحالة', 'المرجع']);
            foreach ($query->get() as $o) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v, [
                    $o->employee?->code, $o->employee?->name ?? $o->employee_name, CctvReport::storeName($o->branch?->branch_name),
                    $o->event_date?->toDateString(), $o->category?->name_ar, $o->catalogItem?->label_ar, $o->description,
                    $o->penalty, ($o->extra_values['reply'] ?? null), ObservationLabels::status($o->status), $o->reference,
                ]));
            }
            fclose($out);
        }, 'cctv-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(Request $request)
    {
        abort_unless(CctvReport::canRecord($request->user()), 403);
        $categories = CctvReport::categories();

        // The day being worked on (kept after each save): its count per branch goes on the branch boxes.
        try {
            $day = Carbon::parse((string) $request->query('event_date', now()->toDateString()))->startOfDay();
        } catch (\Throwable) {
            $day = now()->startOfDay();
        }
        $day = $day->isAfter(now()) ? now()->startOfDay() : $day;

        return view('dashboard.cctv.create', [
            'day' => $day,
            'branches' => $this->branches($request),
            'dayCounts' => CctvDashboard::byBranch(CctvReport::query($request->user(), $day, $day)),
            'dayClean' => $this->cleanBranches($request->user(), $day),
            // Branch staff only: head office and warehouse people are in HR's list but never on a branch camera.
            'employees' => Employee::where('active', true)->whereNotNull('branch_id')->orderBy('name')->get(['id', 'code', 'name', 'branch_id', 'job_title']),
            'categories' => $categories,
            'items' => ObservationCatalogItem::whereIn('category_id', $categories->pluck('id'))->where('active', true)
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'category_id', 'label_ar', 'extra_fields', 'responsible_department_id']),
            'departments' => ProblemRouting::departments(),
            'recent' => CctvReport::query($request->user())->where('observations.created_by', $request->user()->id)
                ->with(['branch', 'employee', 'catalogItem', 'category'])->latest('observations.id')->limit(10)->get(),
        ]);
    }

    public function store(Request $request, ObservationService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless(CctvReport::canRecord($user), 403);
        $categoryIds = CctvReport::categories()->pluck('id')->all();

        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($this->branches($request)->pluck('id')->all())],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('branch_id', $request->input('branch_id'))],
            'new_employee' => ['nullable', 'string', 'max:191'],
            'event_date' => ['required', 'date', 'before_or_equal:today'],
            'event_time' => ['nullable', 'date_format:H:i'],
            'category_id' => ['required', 'integer', Rule::in($categoryIds)],
            'catalog_item_id' => ['nullable', 'integer', Rule::exists('observation_catalog_items', 'id')->where('category_id', $request->input('category_id'))],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'description' => ['nullable', 'string', 'max:2000', 'required_without:catalog_item_id'],
            'reply' => ['nullable', 'string', 'max:2000'],
            'penalty' => ['nullable', 'string', 'max:500'],
            'department_id' => ['required', 'integer', Rule::in(ProblemRouting::departments()->pluck('id')->all())],
        ], [], [
            'branch_id' => 'الفرع', 'event_date' => 'تاريخ المخالفة', 'category_id' => 'نوع المخالفة',
            'catalog_item_id' => 'المخالفة', 'description' => 'التفاصيل', 'department_id' => 'الإدارة المسئولة',
        ]);

        $employee = ! empty($data['employee_id']) ? Employee::find($data['employee_id'])
            : (trim((string) ($data['new_employee'] ?? '')) !== '' ? Employee::create(['name' => trim($data['new_employee']), 'branch_id' => $data['branch_id']]) : null);

        $item = ! empty($data['catalog_item_id']) ? ObservationCatalogItem::find($data['catalog_item_id']) : null;
        $extra = array_filter([
            $this->minutesKey($item) => isset($data['minutes']) ? (int) $data['minutes'] : null,
            'time' => $data['event_time'] ?? null,
            'reply' => $data['reply'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        unset($extra['']);

        $visit = $service->createVisit($user, [
            'type' => 'desk', 'department_id' => CctvReport::department()->id, 'branch_id' => (int) $data['branch_id'],
            'visit_date' => $data['event_date'], 'source' => 'web',
        ]);
        $saved = $service->upsertObservations($visit, [[
            'catalog_item_id' => $item?->id,
            'category_id' => (int) $data['category_id'],
            'description' => trim((string) ($data['description'] ?? '')) ?: (string) $item?->label_ar,
            'employee_name' => $employee?->name,
            'penalty' => $data['penalty'] ?? null,
            'extra_values' => $extra ?: null,
        ]], $user);
        // The chosen department wins over the catalog default.
        $saved->first()?->update(['employee_id' => $employee?->id, 'responsible_department_id' => (int) $data['department_id']]);
        $status = ProblemRouting::submit($service, $visit, $user)->status;

        return redirect()->route('cctv.create', ['branch_id' => $data['branch_id'], 'event_date' => $data['event_date']])
            ->with('status', $status === 'approved'
                ? dt('اتسجلت المخالفة ونزلت للإدارة المسئولة.', 'The violation was recorded and sent to the responsible department.')
                : dt('اتسجلت المخالفة وبانتظار اعتماد مدير الإدارة.', 'The violation was recorded and awaits the department manager\'s approval.'));
    }

    /** POST /cctv/clean: the branch was watched on that day and nothing was found (a monitoring with no violation). */
    public function clean(Request $request, ObservationService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless(CctvReport::canRecord($user), 403);
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($this->branches($request)->pluck('id')->all())],
            'event_date' => ['required', 'date', 'before_or_equal:today'],
        ], [], ['branch_id' => 'الفرع', 'event_date' => 'التاريخ']);

        $visit = $service->createVisit($user, [
            'type' => 'desk', 'department_id' => CctvReport::department()->id, 'branch_id' => (int) $data['branch_id'],
            'visit_date' => $data['event_date'], 'source' => 'web',
        ]);
        ProblemRouting::submit($service, $visit, $user);
        $name = CctvReport::storeName(Branch::whereKey($data['branch_id'])->value('branch_name'));

        return redirect()->route('cctv.create', ['event_date' => $data['event_date']])
            ->with('status', dt("اتسجل {$name}: سليم.", "{$name}: clean."));
    }

    /**
     * Branch ids with a CCTV monitoring on that day and no violation at all that day.
     *
     * @return list<int>
     */
    private function cleanBranches(User $user, Carbon $day): array
    {
        $withViolations = array_keys(CctvDashboard::byBranch(CctvReport::query($user, $day, $day)));

        return ObservationScope::apply(ObservationVisit::query(), $user, 'branch_id', 'department_id')
            ->where('observation_visits.department_id', CctvReport::department()?->id ?? 0)
            ->whereIn('observation_visits.status', ['submitted', 'approved'])
            ->whereDate('observation_visits.visit_date', $day->toDateString())
            ->whereDoesntHave('observations')
            ->whereNotIn('observation_visits.branch_id', $withViolations)
            ->distinct()->pluck('observation_visits.branch_id')->map(fn ($id) => (int) $id)->all();
    }

    private function listQuery(Request $request, ?Carbon $from, ?Carbon $to)
    {
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        return CctvReport::query($request->user(), $from, $to)
            ->with(['branch', 'employee', 'category', 'catalogItem', 'responsibleDepartment'])
            ->when($request->integer('department_id'), fn ($q, $id) => $q->where('observations.responsible_department_id', $id))
            ->when($request->integer('branch_id'), fn ($q, $id) => $q->where('observations.branch_id', $id))
            ->when($request->integer('employee_id'), fn ($q, $id) => $q->where('observations.employee_id', $id))
            ->when($request->query('employee_id') === 'none', fn ($q) => $q->whereNull('observations.employee_id'))
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('observations.category_id', $id))
            ->when($request->integer('catalog_item_id'), fn ($q, $id) => $q->where('observations.catalog_item_id', $id))
            ->when(in_array($status, self::LIST_STATUSES, true), fn ($q) => $q->where('observations.status', $status))
            ->when($status === 'active', fn ($q) => $q->whereIn('observations.status', ObservationMetrics::OPEN_STATUSES))
            ->when($request->query('reply') === 'yes', fn ($q) => $q->whereRaw("JSON_EXTRACT(observations.extra_values, '$.reply') IS NOT NULL"))
            ->when($request->query('reply') === 'no', fn ($q) => $q->whereRaw("(observations.extra_values IS NULL OR JSON_EXTRACT(observations.extra_values, '$.reply') IS NULL)"))
            ->when($request->query('penalty') === 'yes', fn ($q) => $q->whereNotNull('observations.penalty')->where('observations.penalty', '<>', ''))
            ->when($request->integer('min_minutes'), fn ($q, $m) => $q->whereRaw(
                "GREATEST(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(observations.extra_values, '$.minutes_late')) AS UNSIGNED), 0),
                          COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(observations.extra_values, '$.minutes')) AS UNSIGNED), 0)) >= ?", [$m]))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('observations.description', 'like', "%{$search}%")
                ->orWhere('observations.employee_name', 'like', "%{$search}%")->orWhere('observations.reference', 'like', "%{$search}%")));
    }

    /** ?sort=newest (default) | oldest | branch | employee | type */
    private function sorted(Request $request, $query)
    {
        return match ($request->query('sort')) {
            'oldest' => $query->orderBy('observations.event_date')->orderBy('observations.id'),
            'branch' => $query->orderBy(Branch::select('branch_name')->whereColumn('branches.id', 'observations.branch_id'))->orderByDesc('observations.event_date'),
            'employee' => $query->orderByRaw("COALESCE(observations.employee_name, '') = ''")->orderBy('observations.employee_name')->orderByDesc('observations.event_date'),
            'type' => $query->orderBy('observations.category_id')->orderBy('observations.catalog_item_id')->orderByDesc('observations.event_date'),
            default => $query->orderByDesc('observations.event_date')->orderByDesc('observations.id'),
        };
    }

    /** Counts over every row the filters match (not only the page shown). */
    private function listStats($filtered): array
    {
        $byStatus = (clone $filtered)->selectRaw('observations.status AS s, COUNT(*) AS n')->groupBy('observations.status')
            ->toBase()->pluck('n', 's')->map(fn ($n) => (int) $n)->all();
        $row = (clone $filtered)->selectRaw("COUNT(*) AS total, COUNT(DISTINCT observations.branch_id) AS branches,
                COUNT(DISTINCT observations.employee_id) AS employees,
                SUM(CASE WHEN observations.employee_id IS NULL THEN 1 ELSE 0 END) AS unnamed,
                SUM(CASE WHEN JSON_EXTRACT(observations.extra_values, '$.reply') IS NOT NULL THEN 1 ELSE 0 END) AS replied,
                AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(observations.extra_values, '$.minutes_late')) AS UNSIGNED)) AS avg_late")
            ->toBase()->first();

        return [
            'total' => (int) $row->total, 'branches' => (int) $row->branches, 'employees' => (int) $row->employees,
            'unnamed' => (int) $row->unnamed, 'replied' => (int) $row->replied,
            'avg_late' => $row->avg_late !== null ? (int) round((float) $row->avg_late) : null,
            'by_category' => CctvDashboard::byCategory($filtered), 'by_status' => $byStatus,
            'active' => array_sum(array_intersect_key($byStatus, array_flip(ObservationMetrics::OPEN_STATUSES))),
        ];
    }

    /**
     * ?from=&to= (exact dates, used by the summary's links), else ?month=YYYY-MM with optional ?week=1-4,
     * else every date.
     */
    private function period(Request $request): array
    {
        try {
            if ($request->query('from') || $request->query('to')) {
                $from = $request->query('from') ? Carbon::parse((string) $request->query('from'))->startOfDay() : null;
                $to = $request->query('to') ? Carbon::parse((string) $request->query('to'))->startOfDay() : null;

                return [$from, $to];
            }
            if ($month = $this->listMonth($request)) {
                $week = $request->integer('week');

                return in_array($week, range(1, CctvDashboard::WEEKS), true)
                    ? CctvDashboard::weekRange($month, $week)
                    : [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()->startOfDay()];
            }
        } catch (\Throwable) {
            // An unreadable date shows every date.
        }

        return [null, null];
    }

    private function listMonth(Request $request): ?Carbon
    {
        $m = (string) $request->query('month');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? Carbon::createFromFormat('!Y-m', $m)->startOfMonth() : null;
    }

    private function branches(Request $request)
    {
        $scoped = $request->user()->scopedBranchIds();

        return Branch::where('active', true)->when($scoped !== null, fn ($q) => $q->whereIn('id', $scoped))->get()
            ->sortBy(fn ($b) => CctvReport::storeName($b->branch_name), SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    private function minutesKey(?ObservationCatalogItem $item): string
    {
        foreach ((array) ($item?->extra_fields ?? []) as $field) {
            if (in_array($field['key'] ?? null, ['minutes_late', 'minutes'], true)) {
                return $field['key'];
            }
        }

        return '';
    }
}
