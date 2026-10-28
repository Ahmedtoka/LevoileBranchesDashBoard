<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Observation;
use App\Models\RiskLevel;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "تقرير الموظف" (2026-10-28): the legacy CCTV sheet's pivot by employee, over the register's own scope
 * and filters. One row per observations.employee_name: how many points, how many still open, the split by
 * risk level, the branches involved and the last date. Points without an employee are not on this page.
 */
class ObservationEmployeeReport
{
    /**
     * @return array{rows: list<array<string, mixed>>, risk_levels: Collection<int, RiskLevel>, totals: array<string, int>}
     */
    public static function for(User $u, ObservationFilters $filters): array
    {
        $riskLevels = RiskLevel::where('active', true)->orderBy('sort_order')->get();

        $base = $filters->apply(ObservationScope::apply(Observation::query(), $u))
            ->whereNotNull('observations.employee_name')->where('observations.employee_name', '<>', '');

        $open = ObservationMetrics::OPEN_STATUSES;
        $rows = (clone $base)
            ->selectRaw(
                'observations.employee_name AS employee, COUNT(*) AS observations,
                SUM(CASE WHEN observations.kind = ? AND observations.status IN (?, ?, ?) THEN 1 ELSE 0 END) AS open_issues,
                SUM(CASE WHEN observations.penalty IS NOT NULL AND observations.penalty <> ? THEN 1 ELSE 0 END) AS penalties,
                COUNT(DISTINCT observations.branch_id) AS branches,
                MAX('.ObservationFilters::DATE_SQL.') AS last_at',
                array_merge(['issue'], $open, [''])
            )
            ->groupBy('observations.employee_name')
            ->orderByDesc('observations')->orderBy('observations.employee_name')
            ->toBase()->get();

        $byRisk = (clone $base)
            ->join('risk_levels', 'risk_levels.id', '=', 'observations.risk_level_id')
            ->selectRaw('observations.employee_name AS employee, risk_levels.slug AS slug, COUNT(*) AS n')
            ->groupBy('observations.employee_name', 'risk_levels.slug')
            ->toBase()->get()->groupBy('employee');

        $branchNames = (clone $base)
            ->join('branches', 'branches.id', '=', 'observations.branch_id')
            ->selectRaw('observations.employee_name AS employee, branches.branch_name AS branch')
            ->distinct()->toBase()->get()->groupBy('employee');

        $list = $rows->map(fn ($r) => [
            'employee' => (string) $r->employee,
            'observations' => (int) $r->observations,
            'open_issues' => (int) $r->open_issues,
            'penalties' => (int) $r->penalties,
            'branches' => (int) $r->branches,
            'branch_names' => ($branchNames[$r->employee] ?? collect())->pluck('branch')->sort()->values()->all(),
            'last_at' => substr((string) $r->last_at, 0, 10),
            'risk' => ($byRisk[$r->employee] ?? collect())->mapWithKeys(fn ($x) => [$x->slug => (int) $x->n])->all(),
        ])->values()->all();

        return [
            'rows' => $list,
            'risk_levels' => $riskLevels,
            'totals' => [
                'employees' => count($list),
                'observations' => (int) $rows->sum('observations'),
                'open_issues' => (int) $rows->sum('open_issues'),
                'penalties' => (int) $rows->sum('penalties'),
            ],
        ];
    }
}
