<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The observations dashboard numbers (spec section 12, definitions verbatim). Every figure is a SQL
 * aggregate over a query that went through ObservationScope; the observation side is filtered by
 * ObservationFilters itself, so a tile counts the same rows as the register it links to.
 */
class ObservationMetrics
{
    /** The filters the dashboard honours (the register's other filters are not on this page). */
    public const FILTER_KEYS = ['reporting_department_id', 'area_id', 'branch_id', 'responsible_department_id'];

    /** "مشاكل مفتوحة": kind=issue in one of these statuses. */
    public const OPEN_STATUSES = ['open', 'in_progress', 'reopened'];

    /** @param array<string, string> $filters only FILTER_KEYS */
    private function __construct(private User $user, private array $filters) {}

    private static function make(User $u, array $filters): self
    {
        $filters = array_filter(
            array_intersect_key($filters, array_flip(self::FILTER_KEYS)),
            fn ($v) => is_scalar($v) && (string) $v !== ''
        );

        return new self($u, array_map('strval', $filters));
    }

    public static function for(User $u, DateRange $r, array $filters = []): array
    {
        $m = self::make($u, $filters);
        $stats = $m->stats($r);
        $names = $m->departmentNames($stats);
        $weeks = self::weekWindows($r);

        return $m->kpis($stats) + [
            'risk_by_department' => $m->riskByDepartment($r),
            'repeated_branches' => $m->repeatedBranches($r),
            'responsible_performance' => $m->responsiblePerformance($stats['tickets'], $names),
            'outside_catalog_pct_by_department' => $m->outsideCatalog($stats['observations'], $names),
            'week_compare' => [
                'current' => $m->kpis($m->stats($weeks['current'])),
                'previous' => $m->kpis($m->stats($weeks['previous'])),
            ],
            // One row per reporting department for the "الإدارات" table (spec 12, العرض).
            'departments' => $m->departmentRows($stats, $names),
        ];
    }

    /** "متأخر الآن" as a list: the overdue linked tickets, oldest due first, with their observation. */
    public static function overdueTickets(User $u, DateRange $r, array $filters = [], int $limit = 20): Collection
    {
        return self::make($u, $filters)->tickets($r)->overdue()
            ->with(['observation.branch', 'department'])
            ->orderBy('tickets.due_at')->orderBy('tickets.id')
            ->limit($limit)->get();
    }

    /** The two consecutive 7-day windows of "مقارنة الأسابيع": the week ending at $r->to and the one before it. */
    public static function weekWindows(DateRange $r): array
    {
        $currentFrom = $r->to->copy()->subDays(6)->startOfDay();

        return [
            'current' => self::window($currentFrom, $r->to->copy()),
            'previous' => self::window($currentFrom->copy()->subDays(7), $currentFrom->copy()->subSecond()),
        ];
    }

    private static function window(Carbon $from, Carbon $to): DateRange
    {
        $range = new DateRange;
        $range->from = $from;
        $range->to = $to;
        $range->key = 'custom';
        $range->label = DateRange::$presets['custom'];
        $range->customFrom = $from->toDateString();
        $range->customTo = $to->toDateString();

        return $range;
    }

    // ---- base queries: scope + range + filters -------------------------------------------------

    /** Observations by event_date, through ObservationScope and the register's own filter code. */
    private function observations(DateRange $r): Builder
    {
        return (new ObservationFilters($r, $this->filters))
            ->apply(ObservationScope::apply(Observation::query(), $this->user));
    }

    /** Submitted visits (field and desk) by visit_date; the reporting department is visits.department_id. */
    private function visits(DateRange $r): Builder
    {
        $q = ObservationScope::apply(ObservationVisit::query(), $this->user, 'branch_id', 'department_id')
            ->where('observation_visits.status', 'submitted');
        $r->apply($q, 'observation_visits.visit_date');

        if (isset($this->filters['reporting_department_id'])) {
            $q->where('observation_visits.department_id', $this->filters['reporting_department_id']);
        }
        if (isset($this->filters['branch_id'])) {
            $q->where('observation_visits.branch_id', $this->filters['branch_id']);
        }
        if (isset($this->filters['area_id'])) {
            $q->whereIn('observation_visits.branch_id', Branch::where('area_id', $this->filters['area_id'])->select('id'));
        }
        if (isset($this->filters['responsible_department_id'])) {
            // A visit has no responsible department: keep the visits that produced work for it.
            $q->whereHas('observations', fn (Builder $o) => $o->where('observations.responsible_department_id', $this->filters['responsible_department_id']));
        }

        return $q;
    }

    /** The linked tickets (source=observation) of the in-range, scoped, filtered observations. */
    private function tickets(DateRange $r): Builder
    {
        return Ticket::query()
            ->where('tickets.source', 'observation')
            ->whereIn('tickets.observation_id', $this->observations($r)->select('observations.id'));
    }

    // ---- aggregates -----------------------------------------------------------------------------

    /** One query per metric family: visits (total + per department), observations, closed tickets, overdue. */
    private function stats(DateRange $r): array
    {
        $visitSelect = "COUNT(*) AS submitted,
            SUM(CASE WHEN observation_visits.type = 'field' THEN 1 ELSE 0 END) AS field,
            COUNT(DISTINCT CASE WHEN observation_visits.type = 'field' THEN observation_visits.branch_id END) AS branches";

        return [
            'visit_totals' => $this->visits($r)->selectRaw($visitSelect)->toBase()->first(),
            'visits' => $this->visits($r)
                ->selectRaw('observation_visits.department_id AS department_id, '.$visitSelect)
                ->groupBy('observation_visits.department_id')->toBase()->get()->keyBy('department_id'),
            'observations' => $this->observations($r)
                ->selectRaw(
                    'observations.reporting_department_id AS department_id, COUNT(*) AS observations,
                    SUM(CASE WHEN observations.kind = ? AND observations.status IN (?, ?, ?) THEN 1 ELSE 0 END) AS open_issues,
                    SUM(CASE WHEN observations.catalog_item_id IS NULL THEN 1 ELSE 0 END) AS outside',
                    array_merge(['issue'], self::OPEN_STATUSES)
                )
                ->groupBy('observations.reporting_department_id')->toBase()->get()->keyBy('department_id'),
            // Closed linked tickets per responsible department (tickets.department_id follows reroutes).
            'tickets' => $this->tickets($r)
                ->where('tickets.status', 'closed')->whereNotNull('tickets.closed_at')
                ->selectRaw('tickets.department_id AS department_id, COUNT(*) AS closed,
                    SUM(CASE WHEN tickets.due_at IS NOT NULL AND tickets.closed_at <= tickets.due_at THEN 1 ELSE 0 END) AS on_time,
                    AVG(TIMESTAMPDIFF(SECOND, tickets.created_at, tickets.closed_at)) AS avg_seconds')
                ->groupBy('tickets.department_id')->toBase()->get()->keyBy('department_id'),
            'overdue' => $this->tickets($r)->overdue()->count(),
        ];
    }

    /** The six KPI tiles (also the "مقارنة الأسابيع" set). */
    private function kpis(array $stats): array
    {
        $observations = (int) $stats['observations']->sum('observations');

        return [
            'visits' => (int) $stats['visit_totals']->field,
            'branches_visited' => (int) $stats['visit_totals']->branches,
            'observations_per_visit' => self::ratio($observations, (int) $stats['visit_totals']->submitted),
            'open_issues' => (int) $stats['observations']->sum('open_issues'),
            'on_time_pct' => self::pct((int) $stats['tickets']->sum('on_time'), (int) $stats['tickets']->sum('closed')),
            'overdue_now' => (int) $stats['overdue'],
        ];
    }

    /** "توزيع الريسك": observations per risk slug per reporting department, slugs in risk order. */
    private function riskByDepartment(DateRange $r): array
    {
        $rows = $this->observations($r)
            ->join('risk_levels', 'risk_levels.id', '=', 'observations.risk_level_id')
            ->selectRaw('observations.reporting_department_id AS department_id, risk_levels.slug AS slug, COUNT(*) AS n')
            ->groupBy('observations.reporting_department_id', 'risk_levels.slug')
            ->orderByRaw('MIN(risk_levels.sort_order)')
            ->toBase()->get();

        $names = Department::whereIn('id', $rows->pluck('department_id')->filter()->unique())->pluck('name', 'id');

        return $rows->groupBy('department_id')
            ->map(fn ($group, $id) => [
                'department' => $names[$id] ?? self::noDepartment(),
                'counts' => $group->mapWithKeys(fn ($row) => [$row->slug => (int) $row->n])->all(),
            ])
            ->sortBy('department')->values()->all();
    }

    /** "الفروع الأكثر تكراراً": catalog_item_id x branch_id seen at least twice in the range. */
    private function repeatedBranches(DateRange $r, int $limit = 20): array
    {
        $rows = $this->observations($r)
            ->whereNotNull('observations.catalog_item_id')->whereNotNull('observations.branch_id')
            ->selectRaw('observations.catalog_item_id AS item_id, observations.branch_id AS branch_id,
                COUNT(*) AS n, MAX(observations.event_date) AS last_at')
            ->groupBy('observations.catalog_item_id', 'observations.branch_id')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('n')->orderByDesc('last_at')
            ->limit($limit)->toBase()->get();

        $branches = Branch::whereIn('id', $rows->pluck('branch_id')->unique())->pluck('branch_name', 'id');
        $items = ObservationCatalogItem::whereIn('id', $rows->pluck('item_id')->unique())->get(['id', 'label_ar', 'label_en'])->keyBy('id');

        return $rows->map(fn ($row) => [
            'branch' => $branches[$row->branch_id] ?? '—',
            'item' => isset($items[$row->item_id]) ? dt($items[$row->item_id]->label_ar, $items[$row->item_id]->label_en) : '—',
            'count' => (int) $row->n,
            'last_at' => substr((string) $row->last_at, 0, 10),
        ])->all();
    }

    /** "أداء الإدارة المسؤولة" + "نسبة الحل في المهلة" per responsible department. */
    private function responsiblePerformance($tickets, $names): array
    {
        return $tickets->map(fn ($row, $id) => [
            'department' => $names[$id] ?? self::noDepartment(),
            'avg_hours' => $row->avg_seconds === null ? null : round((float) $row->avg_seconds / 3600, 1),
            'closed' => (int) $row->closed,
            'on_time_pct' => self::pct((int) $row->on_time, (int) $row->closed),
        ])->sortBy('department')->values()->all();
    }

    /** "خارج الكتالوج": % of observations without a catalog item, per reporting department. */
    private function outsideCatalog($observations, $names): array
    {
        return $observations->map(fn ($row, $id) => [
            'department' => $names[$id] ?? self::noDepartment(),
            'observations' => (int) $row->observations,
            'outside' => (int) $row->outside,
            'pct' => self::pct((int) $row->outside, (int) $row->observations) ?? 0.0,
        ])->sortBy('department')->values()->all();
    }

    /** One row per reporting department with visits or observations; ticket figures where it is also responsible. */
    private function departmentRows(array $stats, $names): array
    {
        $ids = $stats['visits']->keys()->merge($stats['observations']->keys())->unique();

        return $ids->map(function ($id) use ($stats, $names) {
            $v = $stats['visits'][$id] ?? null;
            $o = $stats['observations'][$id] ?? null;
            $t = $stats['tickets'][$id] ?? null;
            $observations = (int) ($o->observations ?? 0);

            return [
                'department_id' => $id === '' ? null : (int) $id,
                'department' => $names[$id] ?? self::noDepartment(),
                'visits' => (int) ($v->field ?? 0),
                'branches_visited' => (int) ($v->branches ?? 0),
                'observations' => $observations,
                'observations_per_visit' => self::ratio($observations, (int) ($v->submitted ?? 0)),
                'open_issues' => (int) ($o->open_issues ?? 0),
                'outside_catalog_pct' => self::pct((int) ($o->outside ?? 0), $observations),
                'on_time_pct' => $t ? self::pct((int) $t->on_time, (int) $t->closed) : null,
                'avg_hours' => $t && $t->avg_seconds !== null ? round((float) $t->avg_seconds / 3600, 1) : null,
            ];
        })->sortBy('department')->values()->all();
    }

    /** id => name for every department any of the aggregates mentions (one query). */
    private function departmentNames(array $stats)
    {
        $ids = $stats['visits']->keys()->merge($stats['observations']->keys())->merge($stats['tickets']->keys())
            ->filter(fn ($id) => $id !== '' && $id !== null)->unique();

        return Department::whereIn('id', $ids)->pluck('name', 'id');
    }

    private static function ratio(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 2) : 0.0;
    }

    /** Percentage with one decimal, null when there is nothing to divide by. */
    private static function pct(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    private static function noDepartment(): string
    {
        return dt('بدون إدارة', 'No department');
    }
}
