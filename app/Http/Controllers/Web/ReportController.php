<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index()
    {
        // Visits per branch
        $visitsPerBranch = Visit::selectRaw('branch_id, count(*) as total')
            ->groupBy('branch_id')->with('branch')->orderByDesc('total')->get();

        // Open / closed tickets by department
        $byDept = Department::withCount([
            'tickets as open' => fn ($q) => $q->whereNotIn('status', ['closed']),
            'tickets as closed' => fn ($q) => $q->where('status', 'closed'),
        ])->orderByDesc('open')->get();

        // Tickets by source (store checklist / area visit / maintenance request)
        $bySource = [
            'store' => Ticket::whereHas('visit.template', fn ($q) => $q->where('type', 'store_manager'))->count(),
            'area' => Ticket::whereHas('visit.template', fn ($q) => $q->where('type', 'area_manager'))->count(),
            'maintenance' => Ticket::whereNull('visit_id')->count(),
        ];

        // Avg resolution time (hours) per department
        $resolution = Ticket::selectRaw('department_id, AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) as avg_hours')
            ->whereNotNull('closed_at')
            ->groupBy('department_id')
            ->with('department')
            ->get();

        // Repeated problems
        $repeated = Ticket::selectRaw('category, branch_id, count(*) as total')
            ->whereNotNull('category')
            ->groupBy('category', 'branch_id')
            ->having('total', '>=', 2)
            ->with('branch')
            ->orderByDesc('total')
            ->get();

        // Employee performance
        $performance = User::where('is_department_manager', false)
            ->whereNotNull('department_id')
            ->withCount([
                'assignedTickets as assigned',
                'assignedTickets as closed' => fn ($q) => $q->where('status', 'closed'),
                'assignedTickets as pending' => fn ($q) => $q->whereNotIn('status', ['closed']),
                'assignedTickets as reopened' => fn ($q) => $q->where('reopen_count', '>', 0),
            ])
            ->with('department')
            ->having('assigned', '>', 0)
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
            ->with(['branch', 'department'])->get();

        return view('dashboard.reports.index', compact(
            'visitsPerBranch', 'byDept', 'bySource', 'resolution', 'repeated', 'performance', 'overdue'
        ));
    }
}
