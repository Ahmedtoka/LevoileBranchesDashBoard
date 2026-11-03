<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ObservationCatalogItem;
use App\Services\Observations\ObservationService;
use App\Support\CctvDashboard;
use App\Support\CctvReport;
use App\Support\FinanceReport;
use App\Support\ObservationLabels;
use App\Support\ObservationMetrics;
use App\Support\ProblemRouting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The finance department's menu (owner request 2026-11-03): the weekly report as a summary, the branch
 * review (clean, or its problems against the eight controls) and the problems list. Problems are ordinary
 * observations of the finance department, so tickets and approval apply as everywhere.
 */
class FinanceController extends Controller
{
    private const LIST_STATUSES = ['open', 'in_progress', 'waiting_verification', 'verified_closed', 'reopened', 'closed_no_action'];

    public function summary(Request $request)
    {
        $user = $request->user();
        abort_unless(FinanceReport::canView($user), 403);

        $branches = $this->branches($request);
        $branchId = $request->integer('branch_id') ?: null;
        abort_if($branchId !== null && ! $branches->contains('id', $branchId), 403);
        $month = $this->month($request) ?? FinanceReport::defaultMonth($user, $branchId);
        $week = $this->week($request);
        [$from, $to] = $week ? CctvDashboard::weekRange($month, $week) : [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()->startOfDay()];
        [$pFrom, $pTo, $pLabel] = (new CctvDashboard($user, $month, $week, $branchId))->previousRange();

        $reviews = FinanceReport::reviews($user, $from, $to, $branchId);
        $problems = FinanceReport::problems($user, $from, $to, $branchId);
        $prevProblems = FinanceReport::problems($user, $pFrom, $pTo, $branchId);

        $byBranch = FinanceReport::byBranch($problems);
        arsort($byBranch);
        $prevByBranch = FinanceReport::byBranch($prevProblems);
        $names = Branch::whereIn('id', array_keys($byBranch))->pluck('branch_name', 'id');

        return view('dashboard.finance.summary', [
            'month' => $month, 'week' => $week, 'from' => $from, 'to' => $to, 'pLabel' => $pLabel,
            'branches' => $branches, 'branch' => $branchId ? $branches->firstWhere('id', $branchId) : null,
            'categories' => FinanceReport::categories(),
            'compliance' => FinanceReport::compliance($reviews),
            'prevCompliance' => FinanceReport::compliance(FinanceReport::reviews($user, $pFrom, $pTo, $branchId)),
            'byCategory' => FinanceReport::byCategory($problems),
            'prevByCategory' => FinanceReport::byCategory($prevProblems),
            'money' => FinanceReport::money($problems),
            'biggest' => FinanceReport::biggestAmounts($problems),
            'topBranches' => collect($byBranch)->take(5)->map(fn ($n, $id) => ['id' => (int) $id, 'name' => CctvReport::storeName($names[$id] ?? null), 'n' => $n, 'prev' => $prevByBranch[$id] ?? 0])->values()->all(),
            'topProblems' => $this->topProblems($problems),
            'matrix' => FinanceReport::matrix($reviews, $problems),
            'prevByBranch' => $prevByBranch,
            'weeks' => FinanceReport::weeks($user, $month, $branchId),
            'canRecord' => FinanceReport::canRecord($user),
        ]);
    }

    public function index(Request $request)
    {
        abort_unless(FinanceReport::canView($request->user()), 403);
        [$from, $to] = $this->period($request);
        $filtered = $this->listQuery($request, $from, $to);
        $perPage = in_array($request->integer('per_page'), [25, 50, 100, 200], true) ? $request->integer('per_page') : 50;
        $categories = FinanceReport::categories();

        return view('dashboard.finance.index', [
            'from' => $from, 'to' => $to, 'month' => $this->month($request), 'week' => $this->week($request),
            'violations' => $this->sorted($request, clone $filtered)->paginate($perPage)->withQueryString(),
            'stats' => $this->listStats($request, $filtered, $from, $to),
            'perPage' => $perPage,
            'categories' => $categories,
            'items' => ObservationCatalogItem::whereIn('category_id', $categories->pluck('id'))->orderBy('sort_order')->orderBy('id')->get(['id', 'category_id', 'label_ar']),
            'statuses' => self::LIST_STATUSES,
            'branches' => $this->branches($request),
            'departments' => ProblemRouting::departments(),
            'canRecord' => FinanceReport::canRecord($request->user()),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(FinanceReport::canView($request->user()), 403);
        [$from, $to] = $this->period($request);
        $query = $this->sorted($request, $this->listQuery($request, $from, $to));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['التاريخ', 'الأسبوع', 'الفرع', 'البند', 'المشكلة', 'التفاصيل', 'المبلغ', 'اتحولت لمنع الخسائر', 'الإجراء', 'الحالة', 'المرجع']);
            foreach ($query->get() as $o) {
                $x = (array) ($o->extra_values ?? []);
                fputcsv($out, array_map(fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v, [
                    $o->event_date?->toDateString(), $o->event_date ? FinanceReport::weekOfMonth($o->event_date) : '',
                    CctvReport::storeName($o->branch?->branch_name), $o->category?->name_ar, $o->catalogItem?->label_ar, $o->description,
                    $x['amount'] ?? null, FinanceReport::isLossPrevention($o) ? 'نعم' : '', $x['action'] ?? null, ObservationLabels::status($o->status), $o->reference,
                ]));
            }
            fclose($out);
        }, 'finance-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** GET /finance/reviews/new?month=&week=: the branch boxes of one week with their review state. */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless(FinanceReport::canRecord($user), 403);
        $month = $this->month($request) ?? now()->startOfMonth();
        $week = $this->week($request) ?? ($month->isSameMonth(now()) ? FinanceReport::weekOfMonth(now()) : 1);
        [$from, $to] = CctvDashboard::weekRange($month, $week);
        $categories = FinanceReport::categories();

        $reviews = FinanceReport::reviews($user, $from, $to)->with(['observations' => fn ($q) => $q->with('catalogItem')->orderBy('id')])->get();

        return view('dashboard.finance.create', [
            'month' => $month, 'week' => $week, 'from' => $from, 'to' => $to,
            'branches' => $this->branches($request),
            'state' => $reviews->groupBy('branch_id')->map(fn ($g) => [
                'reviews' => $g->count(),
                'problems' => $g->flatMap->observations->map(fn ($o) => ['label' => $o->catalogItem?->label_ar ?? $o->title, 'cat' => $o->category_id, 'amount' => $o->extra_values['amount'] ?? null, 'ref' => $o->reference, 'id' => $o->id])->values()->all(),
            ]),
            'categories' => $categories,
            'items' => ObservationCatalogItem::whereIn('category_id', $categories->pluck('id'))->where('active', true)
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'category_id', 'label_ar', 'extra_fields', 'responsible_department_id']),
            'departments' => ProblemRouting::departments(),
        ]);
    }

    /** POST /finance/reviews: one branch's review of the week, clean (no problems) or with its problems. */
    public function store(Request $request, ObservationService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless(FinanceReport::canRecord($user), 403);
        $categoryIds = FinanceReport::categories()->pluck('id')->all();

        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($this->branches($request)->pluck('id')->all())],
            'review_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'problems' => ['nullable', 'array', 'max:30'],
            'problems.*.category_id' => ['required', 'integer', Rule::in($categoryIds)],
            'problems.*.catalog_item_id' => ['nullable', 'integer', 'exists:observation_catalog_items,id'],
            'problems.*.description' => ['nullable', 'string', 'max:2000'],
            'problems.*.amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'problems.*.department_id' => ['required', 'integer', Rule::in(ProblemRouting::departments()->pluck('id')->all())],
        ], [], ['branch_id' => 'الفرع', 'review_date' => 'تاريخ المراجعة', 'problems.*.department_id' => 'الإدارة المسئولة']);

        $items = [];
        foreach ($data['problems'] ?? [] as $i => $p) {
            $item = ! empty($p['catalog_item_id']) ? ObservationCatalogItem::find($p['catalog_item_id']) : null;
            if ($item && (int) $item->category_id !== (int) $p['category_id']) {
                return back()->withInput()->withErrors(["problems.{$i}.catalog_item_id" => dt('المشكلة مش من البند ده.', 'The problem is not under that control.')]);
            }
            if (! $item && trim((string) ($p['description'] ?? '')) === '') {
                return back()->withInput()->withErrors(["problems.{$i}.description" => dt('اكتب المشكلة لما تختار «أخرى».', 'Describe the problem when choosing "Other".')]);
            }
            $items[] = [
                'catalog_item_id' => $item?->id,
                'category_id' => (int) $p['category_id'],
                'description' => trim((string) ($p['description'] ?? '')) ?: (string) $item?->label_ar,
                // Loss prevention is no longer a tick: the problem is sent to that department instead.
                'extra_values' => isset($p['amount']) && $p['amount'] !== '' ? ['amount' => (float) $p['amount']] : null,
            ];
        }

        $visit = $service->createVisit($user, [
            'type' => 'desk', 'department_id' => FinanceReport::department()->id, 'branch_id' => (int) $data['branch_id'],
            'visit_date' => $data['review_date'], 'source' => 'web', 'general_notes' => $data['notes'] ?? null,
        ]);
        if ($items !== []) {
            // The chosen department wins over the catalog default.
            foreach ($service->upsertObservations($visit, $items, $user)->values() as $i => $o) {
                $o->update(['responsible_department_id' => (int) array_values($data['problems'])[$i]['department_id']]);
            }
        }
        $status = ProblemRouting::submit($service, $visit, $user)->status;

        $date = Carbon::parse($data['review_date']);
        $name = CctvReport::storeName(Branch::whereKey($data['branch_id'])->value('branch_name'));

        return redirect()->route('finance.create', ['month' => $date->format('Y-m'), 'week' => FinanceReport::weekOfMonth($date)])
            ->with('status', $items === []
                ? dt("اتسجلت مراجعة {$name}: سليم.", "{$name} reviewed: clean.")
                : dt("اتسجلت مراجعة {$name}: ".count($items).' مشكلة'.($status === 'approved' ? '، ونزلت للإدارات المسئولة.' : '، بانتظار اعتماد مدير الإدارة.'), "{$name} reviewed: ".count($items).' problems.'));
    }

    /** @return list<array{label:string, n:int}> */
    private function topProblems($problems): array
    {
        return (clone $problems)->leftJoin('observation_catalog_items as ci', 'ci.id', '=', 'observations.catalog_item_id')
            ->selectRaw('COALESCE(ci.label_ar, observations.title) AS label, COUNT(*) AS n')
            ->groupBy('ci.label_ar', 'observations.title')->toBase()->get()
            ->groupBy('label')->map(fn ($g) => (int) $g->sum('n'))->sortDesc()->take(6)
            ->map(fn ($n, $label) => ['label' => (string) $label, 'n' => $n])->values()->all();
    }

    private function listQuery(Request $request, ?Carbon $from, ?Carbon $to)
    {
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        return FinanceReport::problems($request->user(), $from, $to, $request->integer('branch_id') ?: null)
            ->with(['branch', 'category', 'catalogItem', 'responsibleDepartment'])
            ->when($request->integer('category_id'), fn ($q, $id) => $q->where('observations.category_id', $id))
            ->when($request->integer('department_id'), fn ($q, $id) => $q->where('observations.responsible_department_id', $id))
            ->when($request->integer('catalog_item_id'), fn ($q, $id) => $q->where('observations.catalog_item_id', $id))
            ->when(in_array($status, self::LIST_STATUSES, true), fn ($q) => $q->where('observations.status', $status))
            ->when($status === 'active', fn ($q) => $q->whereIn('observations.status', ObservationMetrics::OPEN_STATUSES))
            ->when($request->query('lp') === 'yes', fn ($q) => FinanceReport::whereLossPrevention($q))
            ->when($request->query('lp') === 'no', fn ($q) => $q->whereNot(fn ($w) => FinanceReport::whereLossPrevention($w)))
            ->when($request->query('amount') === 'yes', fn ($q) => $q->whereRaw("JSON_EXTRACT(observations.extra_values, '$.amount') IS NOT NULL"))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('observations.description', 'like', "%{$search}%")
                ->orWhere('observations.reference', 'like', "%{$search}%")));
    }

    private function sorted(Request $request, $query)
    {
        return match ($request->query('sort')) {
            'oldest' => $query->orderBy('observations.event_date')->orderBy('observations.id'),
            'branch' => $query->orderBy(Branch::select('branch_name')->whereColumn('branches.id', 'observations.branch_id'))->orderByDesc('observations.event_date'),
            'amount' => $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(observations.extra_values, '$.amount')) AS DECIMAL(12,2)) DESC")->orderByDesc('observations.event_date'),
            'type' => $query->orderBy('observations.category_id')->orderBy('observations.catalog_item_id')->orderByDesc('observations.event_date'),
            default => $query->orderByDesc('observations.event_date')->orderByDesc('observations.id'),
        };
    }

    private function listStats(Request $request, $filtered, ?Carbon $from, ?Carbon $to): array
    {
        $byStatus = (clone $filtered)->selectRaw('observations.status AS s, COUNT(*) AS n')->groupBy('observations.status')
            ->toBase()->pluck('n', 's')->map(fn ($n) => (int) $n)->all();
        $reviews = FinanceReport::reviews($request->user(), $from, $to, $request->integer('branch_id') ?: null);

        return [
            'total' => array_sum($byStatus),
            'branches' => (clone $filtered)->distinct()->count('observations.branch_id'),
            'by_category' => FinanceReport::byCategory($filtered),
            'active' => array_sum(array_intersect_key($byStatus, array_flip(ObservationMetrics::OPEN_STATUSES))),
            'money' => FinanceReport::money($filtered),
            'compliance' => FinanceReport::compliance($reviews),
        ];
    }

    /** ?from=&to=, else ?month= with optional ?week=1-4, else every date. */
    private function period(Request $request): array
    {
        try {
            if ($request->query('from') || $request->query('to')) {
                return [
                    $request->query('from') ? Carbon::parse((string) $request->query('from'))->startOfDay() : null,
                    $request->query('to') ? Carbon::parse((string) $request->query('to'))->startOfDay() : null,
                ];
            }
            if ($month = $this->month($request)) {
                $week = $this->week($request);

                return $week ? CctvDashboard::weekRange($month, $week) : [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()->startOfDay()];
            }
        } catch (\Throwable) {
            // An unreadable date shows every date.
        }

        return [null, null];
    }

    private function month(Request $request): ?Carbon
    {
        $m = (string) $request->query('month');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? Carbon::createFromFormat('!Y-m', $m)->startOfMonth() : null;
    }

    private function week(Request $request): ?int
    {
        return in_array($request->integer('week'), range(1, CctvDashboard::WEEKS), true) ? $request->integer('week') : null;
    }

    private function branches(Request $request)
    {
        $scoped = $request->user()->scopedBranchIds();

        return Branch::where('active', true)->when($scoped !== null, fn ($q) => $q->whereIn('id', $scoped))->get()
            ->sortBy(fn ($b) => CctvReport::storeName($b->branch_name), SORT_NATURAL | SORT_FLAG_CASE)->values();
    }
}
