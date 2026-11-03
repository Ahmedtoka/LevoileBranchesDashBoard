<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Support\CctvDashboard;
use App\Support\CctvReport;
use App\Support\ViolationsBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** "لوحة المخالفات" for management (owner request 2026-11-03); see ViolationsBoard. */
class ViolationsBoardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(ViolationsBoard::canView($user), 403);

        $scoped = $user->scopedBranchIds();
        $branches = Branch::where('active', true)->when($scoped !== null, fn ($q) => $q->whereIn('id', $scoped))->get()
            ->sortBy(fn ($b) => CctvReport::storeName($b->branch_name), SORT_NATURAL | SORT_FLAG_CASE)->values();
        $branchId = $request->integer('branch_id') ?: null;
        abort_if($branchId !== null && ! $branches->contains('id', $branchId), 403);
        $departmentId = $request->integer('department_id') ?: null;

        $m = (string) $request->query('month');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? Carbon::createFromFormat('!Y-m', $m) : ViolationsBoard::defaultMonth($user);
        $week = in_array($request->integer('week'), range(1, CctvDashboard::WEEKS), true) ? $request->integer('week') : null;
        [$from, $to] = $week ? CctvDashboard::weekRange($month, $week) : [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()->startOfDay()];
        [$pFrom, $pTo] = $week
            ? ($week === 1 ? CctvDashboard::weekRange($month->copy()->subMonthNoOverflow(), CctvDashboard::WEEKS) : CctvDashboard::weekRange($month, $week - 1))
            : [$month->copy()->subMonthNoOverflow()->startOfMonth(), $month->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()];

        $period = ViolationsBoard::base($user, $from, $to, $branchId, $departmentId);
        $previous = ViolationsBoard::base($user, $pFrom, $pTo, $branchId, $departmentId);
        $open = ViolationsBoard::open($user, $branchId, $departmentId);

        $matrix = ViolationsBoard::matrix($period);
        $openByBranch = ViolationsBoard::countBy($open, 'branch_id');
        $prevByBranch = ViolationsBoard::countBy($previous, 'branch_id');
        $rows = collect(array_keys($matrix + $openByBranch))->map(fn ($id) => [
            'id' => (int) $id, 'cells' => $matrix[$id] ?? [], 'total' => array_sum($matrix[$id] ?? []),
            'open' => $openByBranch[$id] ?? 0, 'prev' => $prevByBranch[$id] ?? 0,
        ])->sortByDesc(fn ($r) => [$r['total'], $r['open']])->values();

        $byDept = ViolationsBoard::countBy($period, 'reporting_department_id');
        $openByResponsible = ViolationsBoard::countBy($open, 'responsible_department_id');
        arsort($openByResponsible);

        return view('dashboard.board.index', [
            'month' => $month, 'week' => $week, 'from' => $from, 'to' => $to, 'pFrom' => $pFrom, 'pTo' => $pTo,
            'branches' => $branches, 'branchId' => $branchId, 'departmentId' => $departmentId,
            'allDepartments' => Department::where('is_reporting', true)->orderBy('name')->get(['id', 'name']),
            'departments' => ViolationsBoard::departments($byDept, ViolationsBoard::countBy($open, 'reporting_department_id')),
            'rows' => $rows,
            'names' => ViolationsBoard::branchNames($rows->pluck('id')->all()),
            'byDept' => $byDept,
            'prevByDept' => ViolationsBoard::countBy($previous, 'reporting_department_id'),
            'kpi' => [
                'total' => array_sum($byDept),
                'prev' => (clone $previous)->count(),
                'open' => (clone $open)->count(),
                'overdue' => ViolationsBoard::overdue($open),
                'branches' => count($matrix),
                'closed' => (clone $period)->whereIn('observations.status', ['verified_closed', 'closed_no_action'])->count(),
            ],
            'openByResponsible' => $openByResponsible,
            'responsibleNames' => Department::whereIn('id', array_keys($openByResponsible))->pluck('name', 'id'),
            'oldest' => (clone $open)->with(['branch', 'reportingDepartment', 'responsibleDepartment', 'catalogItem', 'ticket', 'employee'])
                ->orderByRaw(\App\Support\ObservationFilters::DATE_SQL)->orderBy('observations.id')->limit(12)->get(),
        ]);
    }
}
