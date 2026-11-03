<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\User;
use App\Services\Observations\ObservationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "لوحة المخالفات" (owner request 2026-11-03): every department's violations across the user's branches, a
 * branch × reporting-department table, the branches with the most, and what is still open. A violation is an
 * issue observation of a submitted visit, dated by ObservationFilters::DATE_SQL, seen through ObservationScope.
 */
class ViolationsBoard
{
    public static function canView(User $u): bool
    {
        return $u->hasRole(ObservationService::ALL_DEPARTMENTS_ROLES);
    }

    /** Violations of the period (no bounds = every date). */
    public static function base(User $u, ?Carbon $from = null, ?Carbon $to = null, ?int $branchId = null, ?int $departmentId = null): Builder
    {
        $range = new DateRange;
        $range->key = $from ? 'custom' : ObservationFilters::ALL;
        $range->from = $from?->copy()->startOfDay() ?? Carbon::create(2000, 1, 1);
        $range->to = $to?->copy()->endOfDay() ?? now()->endOfDay();
        $filters = new ObservationFilters($range, array_filter(['branch_id' => $branchId ? (string) $branchId : null, 'reporting_department_id' => $departmentId ? (string) $departmentId : null]));

        return $filters->apply(ObservationScope::apply(Observation::query(), $u))
            ->where('observations.kind', 'issue')->whereNotNull('observations.branch_id');
    }

    /** Still open now, whatever the date. */
    public static function open(User $u, ?int $branchId = null, ?int $departmentId = null): Builder
    {
        return self::base($u, null, null, $branchId, $departmentId)->whereIn('observations.status', ObservationMetrics::OPEN_STATUSES);
    }

    /** @return array<int, array<int, int>> branch id => [reporting department id => n] */
    public static function matrix(Builder $q): array
    {
        $out = [];
        foreach ((clone $q)->selectRaw('observations.branch_id b, observations.reporting_department_id d, COUNT(*) n')
            ->groupBy('observations.branch_id', 'observations.reporting_department_id')->toBase()->get() as $r) {
            $out[(int) $r->b][(int) $r->d] = (int) $r->n;
        }

        return $out;
    }

    /** @return array<int, int> */
    public static function countBy(Builder $q, string $column): array
    {
        return (clone $q)->selectRaw("observations.{$column} k, COUNT(*) n")->groupBy("observations.{$column}")
            ->toBase()->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();
    }

    /** The reporting departments that appear, most violations first. */
    public static function departments(array ...$counts): Collection
    {
        $totals = [];
        foreach ($counts as $byDept) {
            foreach ($byDept as $id => $n) {
                $totals[$id] = ($totals[$id] ?? 0) + $n;
            }
        }
        arsort($totals);

        return Department::whereIn('id', array_keys($totals))->get()->sortBy(fn ($d) => array_search($d->id, array_keys($totals), true))->values();
    }

    /** Open violations whose ticket is past its due date. */
    public static function overdue(Builder $open): int
    {
        return (clone $open)->whereHas('ticket', fn ($t) => $t->overdue())->count();
    }

    /** The latest month with a violation the user can see. */
    public static function defaultMonth(User $u): Carbon
    {
        $last = self::base($u)->selectRaw('MAX('.ObservationFilters::DATE_SQL.') d')->toBase()->value('d');

        return Carbon::parse($last ?? now())->startOfMonth();
    }

    /** @return array<int, string> */
    public static function branchNames(array $ids): array
    {
        return Branch::whereIn('id', $ids)->pluck('branch_name', 'id')->map(fn ($n) => CctvReport::storeName($n))->all();
    }
}
