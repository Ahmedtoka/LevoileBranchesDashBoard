<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\User;
use App\Services\Observations\ObservationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The finance department's pages (owner request 2026-11-03). The unit is the branch review: one finance
 * visit to a branch in a week, clean or with problems (its observations). A branch-week is "clean" when
 * none of its reviews found a problem; the compliance rate is clean branch-weeks over reviewed ones,
 * as the weekly report computed it. Weeks are the month's four (1-7, 8-14, 15-21, 22-end).
 */
class FinanceReport
{
    /** A branch-week with this many problems or more is critical (the reports' red branches had 4 and 5). */
    public const CRITICAL_FROM = 4;

    public static function department(): ?Department
    {
        return Department::where('slug', 'finance')->first();
    }

    public static function canView(User $u): bool
    {
        $d = self::department();

        return $d !== null && $u->can('viewAny', Observation::class)
            && ($u->hasRole(ObservationService::ALL_DEPARTMENTS_ROLES) || (int) $u->department_id === $d->id);
    }

    public static function canRecord(User $u): bool
    {
        $d = self::department();

        return $d !== null && app(ObservationService::class)->reportingDepartmentsFor($u)->contains('id', $d->id);
    }

    /** Submitted or approved finance reviews the user may see. */
    public static function reviews(User $u, ?Carbon $from = null, ?Carbon $to = null, ?int $branchId = null): Builder
    {
        // Visits name their reporting department `department_id`.
        return ObservationScope::apply(ObservationVisit::query(), $u, 'branch_id', 'department_id')
            ->where('observation_visits.department_id', self::department()?->id ?? 0)
            ->whereIn('observation_visits.status', ['submitted', 'approved'])
            ->whereNotNull('observation_visits.branch_id')
            ->when($from, fn ($q) => $q->whereDate('observation_visits.visit_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('observation_visits.visit_date', '<=', $to->toDateString()))
            ->when($branchId, fn ($q) => $q->where('observation_visits.branch_id', $branchId));
    }

    /** The problems of those reviews. */
    public static function problems(User $u, ?Carbon $from = null, ?Carbon $to = null, ?int $branchId = null): Builder
    {
        return ObservationScope::apply(Observation::query(), $u)
            ->where('observations.reporting_department_id', self::department()?->id ?? 0)
            ->whereIn('observations.observation_visit_id', self::reviews($u, $from, $to, $branchId)->select('observation_visits.id'));
    }

    /** The eight controls in order (plus "أخرى"), active ones only. */
    public static function categories(): Collection
    {
        return ObservationCategory::where('department_id', self::department()?->id ?? 0)->where('active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Week number (1-4) of a date inside its month. */
    public static function weekOfMonth(Carbon $d): int
    {
        return min(CctvDashboard::WEEKS, intdiv($d->day - 1, 7) + 1);
    }

    /**
     * Branch-weeks of the reviews: problems per branch per week.
     *
     * @return Collection<string, array{branch_id:int, week:string, problems:int}>
     */
    public static function branchWeeks(Builder $reviews): Collection
    {
        $rows = (clone $reviews)->withCount('observations')->get(['observation_visits.id', 'observation_visits.branch_id', 'observation_visits.visit_date']);

        return $rows->groupBy(fn ($v) => $v->branch_id.'|'.$v->visit_date->format('Y-m').'-'.self::weekOfMonth($v->visit_date))
            ->map(fn ($g, $key) => ['branch_id' => (int) $g->first()->branch_id, 'week' => explode('|', $key)[1], 'problems' => (int) $g->sum('observations_count')]);
    }

    /** @return array{reviewed:int, clean:int, follow:int, critical:int, rate:?int, branches:int} */
    public static function compliance(Builder $reviews): array
    {
        $bw = self::branchWeeks($reviews);
        $reviewed = $bw->count();
        $clean = $bw->where('problems', 0)->count();
        $critical = $bw->where('problems', '>=', self::CRITICAL_FROM)->count();

        return [
            'reviewed' => $reviewed, 'clean' => $clean, 'critical' => $critical, 'follow' => $reviewed - $clean - $critical,
            'rate' => $reviewed ? (int) round($clean * 100 / $reviewed) : null,
            'branches' => $bw->pluck('branch_id')->unique()->count(),
        ];
    }

    /** @return array<int, int> category id => problems */
    public static function byCategory(Builder $problems): array
    {
        return (clone $problems)->selectRaw('observations.category_id AS c, COUNT(*) AS n')->groupBy('observations.category_id')
            ->toBase()->pluck('n', 'c')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<int, int> branch id => problems */
    public static function byBranch(Builder $problems): array
    {
        return (clone $problems)->selectRaw('observations.branch_id AS b, COUNT(*) AS n')->groupBy('observations.branch_id')
            ->toBase()->pluck('n', 'b')->map(fn ($n) => (int) $n)->all();
    }

    /** Amount (EGP) recorded on the problems, and how many were sent to loss prevention. */
    public static function money(Builder $problems): array
    {
        $row = (clone $problems)->selectRaw("
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(observations.extra_values, '$.amount')) AS DECIMAL(12,2))) AS amount,
                SUM(CASE WHEN JSON_EXTRACT(observations.extra_values, '$.amount') IS NOT NULL THEN 1 ELSE 0 END) AS with_amount")
            ->toBase()->first();

        return ['amount' => (float) ($row->amount ?? 0), 'with_amount' => (int) ($row->with_amount ?? 0),
            'lp' => self::whereLossPrevention(clone $problems)->count()];
    }

    /**
     * Sent to loss prevention: routed to that department (the review's dropdown, 2026-11-03), or ticked in the
     * imported weekly reports ("تم ارسال المشكله لقسم منع الخسائر").
     */
    public static function whereLossPrevention($q)
    {
        $lp = Department::where('slug', 'lp')->value('id') ?? 0;

        return $q->where(fn ($w) => $w->where('observations.responsible_department_id', $lp)
            ->orWhereRaw("JSON_EXTRACT(observations.extra_values, '$.lp_referred') = true"));
    }

    public static function isLossPrevention(Observation $o): bool
    {
        return ! empty(((array) ($o->extra_values ?? []))['lp_referred'])
            || $o->responsibleDepartment?->slug === 'lp';
    }

    /**
     * The problems with the biggest amounts.
     *
     * @return Collection<int, Observation>
     */
    public static function biggestAmounts(Builder $problems, int $limit = 5): Collection
    {
        return (clone $problems)->whereRaw("JSON_EXTRACT(observations.extra_values, '$.amount') IS NOT NULL")
            ->with(['branch', 'catalogItem', 'responsibleDepartment'])
            ->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(observations.extra_values, '$.amount')) AS DECIMAL(12,2)) DESC")
            ->limit($limit)->get();
    }

    /**
     * The report's matrix: one row per reviewed branch, problems per control, the controls hit out of the
     * active controls, and the branch's weeks reviewed / clean.
     *
     * @return list<array{branch_id:int, name:string, cells:array<int,int>, total:int, hit:int, weeks:int, clean:int}>
     */
    public static function matrix(Builder $reviews, Builder $problems): array
    {
        $cells = (clone $problems)->selectRaw('observations.branch_id AS b, observations.category_id AS c, COUNT(*) AS n')
            ->groupBy('observations.branch_id', 'observations.category_id')->toBase()->get()->groupBy('b');
        $bw = self::branchWeeks($reviews)->groupBy('branch_id');
        $names = Branch::whereIn('id', $bw->keys())->pluck('branch_name', 'id');

        $rows = [];
        foreach ($bw as $branchId => $weeks) {
            $byCat = ($cells[$branchId] ?? collect())->pluck('n', 'c')->map(fn ($n) => (int) $n)->all();
            $rows[] = [
                'branch_id' => (int) $branchId, 'name' => CctvReport::storeName($names[$branchId] ?? null),
                'cells' => $byCat, 'total' => array_sum($byCat), 'hit' => count($byCat),
                'weeks' => $weeks->count(), 'clean' => $weeks->where('problems', 0)->count(),
            ];
        }
        usort($rows, fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return $rows;
    }

    /**
     * The month's four weeks: compliance and problems per control, each against the week before.
     *
     * @return list<array{n:int, from:Carbon, to:Carbon, compliance:array, cells:array<int,int>, total:int, prev_total:int, prev_rate:?int, future:bool}>
     */
    public static function weeks(User $u, Carbon $month, ?int $branchId): array
    {
        [$pf, $pt] = CctvDashboard::weekRange($month->copy()->subMonthNoOverflow(), CctvDashboard::WEEKS);
        $prevTotal = array_sum(self::byCategory(self::problems($u, $pf, $pt, $branchId)));
        $prevRate = self::compliance(self::reviews($u, $pf, $pt, $branchId))['rate'];

        $out = [];
        for ($n = 1; $n <= CctvDashboard::WEEKS; $n++) {
            [$from, $to] = CctvDashboard::weekRange($month, $n);
            $cells = self::byCategory(self::problems($u, $from, $to, $branchId));
            $compliance = self::compliance(self::reviews($u, $from, $to, $branchId));
            $out[] = ['n' => $n, 'from' => $from, 'to' => $to, 'compliance' => $compliance, 'cells' => $cells, 'total' => array_sum($cells),
                'prev_total' => $prevTotal, 'prev_rate' => $prevRate, 'future' => $from->isAfter(now())];
            $prevTotal = array_sum($cells);
            $prevRate = $compliance['rate'];
        }

        return $out;
    }

    /** The latest month with a finance review the user can see. */
    public static function defaultMonth(User $u, ?int $branchId = null): Carbon
    {
        $last = self::reviews($u, null, null, $branchId)->max('observation_visits.visit_date');

        return Carbon::parse($last ?? now())->startOfMonth();
    }
}
