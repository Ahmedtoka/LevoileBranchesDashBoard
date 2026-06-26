<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        [$from, $to] = [$range->from, $range->to];

        $user = $request->user();
        $deptId = ($user->is_department_manager && $user->department_id) ? $user->department_id : null;
        $scopeDept = $deptId ? Department::find($deptId) : null;

        $scoped = fn () => Ticket::query()
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->whereBetween('created_at', [$from, $to]);

        // Visits per branch (admins only — visits aren't department-scoped)
        $visitsPerBranch = $deptId ? collect() : Visit::selectRaw('branch_id, count(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('branch_id')->with('branch')->orderByDesc('total')->get();

        // Open / closed tickets by department (admins only)
        $byDept = $deptId ? collect() : Department::withCount([
            'tickets as open' => fn ($q) => $q->whereNotIn('status', ['closed'])->whereBetween('created_at', [$from, $to]),
            'tickets as closed' => fn ($q) => $q->where('status', 'closed')->whereBetween('created_at', [$from, $to]),
        ])->orderByDesc('open')->get();

        // Tickets by source
        $bySource = [
            'store' => $scoped()->whereHas('visit.template', fn ($q) => $q->where('type', 'store_manager'))->count(),
            'area' => $scoped()->whereHas('visit.template', fn ($q) => $q->where('type', 'area_manager'))->count(),
            'maintenance' => $scoped()->whereNull('visit_id')->count(),
        ];

        // Tickets by status (for chart)
        $byStatus = $scoped()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->toArray();

        // SLA summary
        $closedQ = fn () => Ticket::query()
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->whereNotNull('closed_at')->whereBetween('closed_at', [$from, $to]);
        $avgRes = $closedQ()->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) as h'))->value('h');
        $closedWithDue = $closedQ()->whereNotNull('due_at')->count();
        $metSla = $closedQ()->whereNotNull('due_at')->whereColumn('closed_at', '<=', 'due_at')->count();
        $sla = [
            'avg_resolution' => $avgRes ? round($avgRes, 1) : null,
            'sla_pct' => $closedWithDue ? (int) round($metSla / $closedWithDue * 100) : null,
            'closed' => $scoped()->where('status', 'closed')->count(),
        ];

        // Avg resolution time (hours) per department
        $resolution = Ticket::selectRaw('department_id, AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) as avg_hours')
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from, $to])
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->groupBy('department_id')
            ->with('department')
            ->get();

        // Repeated problems
        $repeated = Ticket::selectRaw('category, branch_id, count(*) as total')
            ->whereNotNull('category')
            ->whereBetween('created_at', [$from, $to])
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->groupBy('category', 'branch_id')
            ->having('total', '>=', 2)
            ->with('branch')
            ->orderByDesc('total')
            ->get();

        // Employee performance
        $performance = User::where('is_department_manager', false)
            ->whereNotNull('department_id')
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->withCount([
                'assignedTickets as assigned',
                'assignedTickets as closed' => fn ($q) => $q->where('status', 'closed'),
                'assignedTickets as pending' => fn ($q) => $q->whereNotIn('status', ['closed']),
                'assignedTickets as reopened' => fn ($q) => $q->where('reopen_count', '>', 0),
            ])
            ->with('department')
            ->having('assigned', '>', 0)
            ->orderByDesc('assigned')
            ->get()
            ->map(function ($u) {
                $avg = Ticket::where('assigned_to', $u->id)
                    ->whereNotNull('closed_at')
                    ->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, assigned_at, closed_at)) as h'))
                    ->value('h');
                $u->avg_hours = $avg ? round($avg, 1) : null;
                $rate = $u->assigned ? $u->closed / $u->assigned : 0;
                $u->performance = $rate >= 0.8 ? 'ممتاز' : ($rate >= 0.5 ? 'جيد' : 'يحتاج تحسين');

                return $u;
            });

        $overdue = Ticket::whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', ['closed', 'waiting_approval'])
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->with(['branch', 'department'])->get();

        return view('dashboard.reports.index', compact(
            'visitsPerBranch', 'byDept', 'bySource', 'byStatus', 'sla', 'resolution',
            'repeated', 'performance', 'overdue', 'range', 'scopeDept'
        ));
    }
}
