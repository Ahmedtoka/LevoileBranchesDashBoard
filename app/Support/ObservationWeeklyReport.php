<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "تقرير الأسبوع للإدارة" (2026-10-28): the weekly report every department used to type by hand for the
 * general manager (Finance / Stock Control / CCTV sheets), generated from the register for one reporting
 * department and one Saturday-to-Friday week:
 *  - KPIs: visits, branches covered, points, issues, solved, compliance %
 *  - the branch x category matrix (issues per cell, the rating for assessments)
 *  - a status per branch: stable / needs follow-up / critical
 *  - recurring problems (same catalog item on the same branch at least twice, or also seen the week before)
 *  - the issue list per branch with responsible department, ticket status, penalty and follow-up date
 * Everything goes through ObservationScope, so a department manager only gets their own department.
 */
class ObservationWeeklyReport
{
    public const STABLE = 'stable';

    public const FOLLOW_UP = 'follow_up';

    public const CRITICAL = 'critical';

    /** Egyptian retail week: Saturday to Friday (the legacy sheets ran 4-10 Oct, 11-17 Oct). */
    public static function weekOf(Carbon $date): array
    {
        $start = $date->copy()->startOfDay();
        while ($start->dayOfWeek !== Carbon::SATURDAY) {
            $start->subDay();
        }

        return [$start, $start->copy()->addDays(6)->endOfDay()];
    }

    public static function build(User $u, Department $department, Carbon $anyDay): array
    {
        [$from, $to] = self::weekOf($anyDay);
        [$prevFrom, $prevTo] = self::weekOf($from->copy()->subDay());

        $current = self::week($u, $department, $from, $to);
        $previous = self::week($u, $department, $prevFrom, $prevTo, light: true);

        $categories = ObservationCategory::where('department_id', $department->id)->where('active', true)
            ->orderBy('sort_order')->orderBy('id')->get();

        // Branches covered this week: visited, or with points (desk visits over "all branches" carry points only).
        $branchIds = $current['visits']->pluck('branch_id')->filter()
            ->merge($current['observations']->pluck('branch_id')->filter())->unique()->values();
        $branches = Branch::whereIn('id', $branchIds)->orderBy('branch_name')->get(['id', 'branch_name']);

        $byBranch = $current['observations']->groupBy('branch_id');
        $matrix = [];
        $statuses = [];
        foreach ($branches as $branch) {
            $points = $byBranch[$branch->id] ?? collect();
            $issues = $points->filter(fn (Observation $o) => self::isIssue($o));
            $cells = [];
            foreach ($categories as $category) {
                $inCell = $points->where('category_id', $category->id);
                $cells[$category->id] = [
                    'issues' => $inCell->filter(fn (Observation $o) => self::isIssue($o))->count(),
                    'points' => $inCell->count(),
                    'rating' => $inCell->pluck('rating')->filter()->first(),
                    'worst' => self::worstSlug($inCell),
                ];
            }
            $status = self::branchStatus($issues);
            $statuses[$branch->id] = $status;
            $matrix[] = [
                'branch_id' => $branch->id,
                'branch' => $branch->branch_name,
                'visits' => $current['visits']->where('branch_id', $branch->id)->count(),
                'points' => $points->count(),
                'issues' => $issues->count(),
                'open' => $issues->whereIn('status', ObservationMetrics::OPEN_STATUSES)->count(),
                'solved' => $issues->whereIn('status', ['verified_closed', 'waiting_verification'])->count(),
                'worst' => self::worstSlug($issues),
                'status' => $status,
                'cells' => $cells,
            ];
        }

        $stable = count(array_filter($statuses, fn ($s) => $s === self::STABLE));
        $compliance = $branches->count() > 0 ? round($stable * 100 / $branches->count(), 1) : null;

        $allIssues = $current['observations']->filter(fn (Observation $o) => self::isIssue($o));
        $kpis = [
            'visits' => $current['visits']->count(),
            'branches' => $branches->count(),
            'points' => $current['observations']->count(),
            'issues' => $allIssues->count(),
            'solved' => $allIssues->whereIn('status', ['verified_closed', 'waiting_verification'])->count(),
            'open' => $allIssues->whereIn('status', ObservationMetrics::OPEN_STATUSES)->count(),
            'overdue' => $allIssues->filter(fn (Observation $o) => $o->ticket?->isOverdue())->count(),
            'compliance' => $compliance,
            'previous_compliance' => $previous['compliance'],
            'stable' => $stable,
            'follow_up' => count(array_filter($statuses, fn ($s) => $s === self::FOLLOW_UP)),
            'critical' => count(array_filter($statuses, fn ($s) => $s === self::CRITICAL)),
        ];

        return [
            'department' => $department,
            'from' => $from,
            'to' => $to,
            'previous' => ['from' => $prevFrom, 'to' => $prevTo],
            'kpis' => $kpis,
            'categories' => $categories,
            'matrix' => $matrix,
            'recurring' => self::recurring($allIssues, $previous['observations']),
            'issues_by_branch' => $allIssues->sortBy([['branch_id', 'asc'], ['id', 'asc']])->groupBy(fn (Observation $o) => $o->branch?->branch_name ?? dt('كل الفروع', 'All branches')),
            'by_responsible' => $allIssues->groupBy(fn (Observation $o) => $o->responsibleDepartment?->name ?? dt('بدون إدارة', 'No department'))
                ->map(fn (Collection $g) => ['total' => $g->count(), 'open' => $g->whereIn('status', ObservationMetrics::OPEN_STATUSES)->count()])
                ->sortByDesc('total'),
        ];
    }

    /** @return array{visits: Collection<int, ObservationVisit>, observations: Collection<int, Observation>, compliance: ?float} */
    private static function week(User $u, Department $department, Carbon $from, Carbon $to, bool $light = false): array
    {
        $visits = ObservationScope::apply(ObservationVisit::query(), $u, 'branch_id', 'department_id')
            ->where('observation_visits.department_id', $department->id)
            ->whereIn('observation_visits.status', ['submitted', 'approved'])
            ->whereBetween('observation_visits.visit_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'branch_id', 'type', 'visit_date']);

        $observations = ObservationScope::apply(Observation::query(), $u)
            ->where('observations.reporting_department_id', $department->id)
            ->whereIn('observations.observation_visit_id', ObservationFilters::submittedVisitIds())
            ->whereRaw(ObservationFilters::DATE_SQL.' BETWEEN ? AND ?', [$from, $to])
            ->when(! $light, fn (Builder $q) => $q->with(['branch:id,branch_name', 'category:id,name_ar,name_en', 'catalogItem:id,code,label_ar,label_en', 'riskLevel', 'responsibleDepartment:id,name', 'ticket']))
            ->when($light, fn (Builder $q) => $q->with(['riskLevel']))
            ->orderBy('observations.id')->get();

        $compliance = null;
        if ($light) {
            $ids = $visits->pluck('branch_id')->filter()->merge($observations->pluck('branch_id')->filter())->unique();
            if ($ids->isNotEmpty()) {
                $stable = $ids->filter(fn ($id) => self::branchStatus($observations->where('branch_id', $id)->filter(fn (Observation $o) => self::isIssue($o))) === self::STABLE)->count();
                $compliance = round($stable * 100 / $ids->count(), 1);
            }
        }

        return ['visits' => $visits, 'observations' => $observations, 'compliance' => $compliance];
    }

    /** A problem: an issue, or an assessment rated poor (the same rule ObservationService uses to raise a ticket). */
    public static function isIssue(Observation $o): bool
    {
        return $o->kind === 'issue' || ($o->kind === 'assessment' && $o->rating === 'poor');
    }

    /** @param  Collection<int, Observation>  $issues */
    public static function branchStatus(Collection $issues): string
    {
        if ($issues->isEmpty()) {
            return self::STABLE;
        }
        $severe = $issues->contains(fn (Observation $o) => in_array($o->riskLevel?->slug, ['critical', 'high'], true)
            || ($o->relationLoaded('ticket') && $o->ticket?->isOverdue()));

        return $severe ? self::CRITICAL : self::FOLLOW_UP;
    }

    private static function worstSlug(Collection $points): ?string
    {
        return $points->map(fn (Observation $o) => $o->riskLevel)->filter()->sortBy('sort_order')->first()?->slug;
    }

    /**
     * Same catalog item (or free-text category) on the same branch at least twice this week, or seen this week
     * and the week before. Sorted by count.
     */
    private static function recurring(Collection $issues, Collection $previous): array
    {
        $key = fn (Observation $o) => $o->branch_id.'|'.($o->catalog_item_id ? 'i'.$o->catalog_item_id : 'c'.$o->category_id);
        $prevKeys = $previous->filter(fn (Observation $o) => self::isIssue($o))->map($key)->flip();

        return $issues->whereNotNull('branch_id')->groupBy($key)
            ->filter(fn (Collection $g, string $k) => $g->count() >= 2 || isset($prevKeys[$k]))
            ->map(function (Collection $g, string $k) use ($prevKeys) {
                $first = $g->first();

                return [
                    'branch' => $first->branch?->branch_name ?? '—',
                    'item' => $first->catalogItem ? dt($first->catalogItem->label_ar, $first->catalogItem->label_en ?: $first->catalogItem->label_ar)
                        : ($first->category ? dt($first->category->name_ar, $first->category->name_en ?: $first->category->name_ar) : $first->title),
                    'count' => $g->count(),
                    'last_week' => isset($prevKeys[$k]),
                    'open' => $g->whereIn('status', ObservationMetrics::OPEN_STATUSES)->count(),
                    'worst' => self::worstSlug($g),
                ];
            })
            ->sortByDesc('count')->values()->all();
    }
}
