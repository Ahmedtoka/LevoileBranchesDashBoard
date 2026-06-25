<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\Visit;
use App\Support\DateRange;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        [$from, $to] = [$range->from, $range->to];

        $ticketsInRange = Ticket::whereBetween('created_at', [$from, $to]);
        $visitsInRange = Visit::whereBetween('created_at', [$from, $to]);

        $stats = [
            'branches' => Branch::where('active', true)->count(),
            'visits_total' => (clone $visitsInRange)->count(),
            'visits_completed' => (clone $visitsInRange)->where('status', 'completed')->count(),
            'visits_open' => (clone $visitsInRange)->whereIn('status', ['assigned', 'checked_in', 'in_progress'])->count(),
            'tickets_total' => (clone $ticketsInRange)->count(),
            'tickets_open' => (clone $ticketsInRange)->whereNotIn('status', ['closed'])->count(),
            'tickets_closed' => (clone $ticketsInRange)->where('status', 'closed')->count(),
            'waiting_approval' => (clone $ticketsInRange)->where('status', 'waiting_approval')->count(),
            'overdue' => Ticket::whereNotNull('due_at')->where('due_at', '<', now())
                ->whereNotIn('status', ['closed', 'waiting_approval'])->count(),
        ];

        $ticketsByDept = Department::withCount([
            'tickets as open_count' => fn ($q) => $q->whereNotIn('status', ['closed'])->whereBetween('created_at', [$from, $to]),
            'tickets as closed_count' => fn ($q) => $q->where('status', 'closed')->whereBetween('created_at', [$from, $to]),
            'tickets as total_count' => fn ($q) => $q->whereBetween('created_at', [$from, $to]),
        ])->orderByDesc('open_count')->get();

        // tickets by source (store checklist / area visit / maintenance request)
        $bySource = [
            'store' => (clone $ticketsInRange)->whereHas('visit.template', fn ($q) => $q->where('type', 'store_manager'))->count(),
            'area' => (clone $ticketsInRange)->whereHas('visit.template', fn ($q) => $q->where('type', 'area_manager'))->count(),
            'maintenance' => (clone $ticketsInRange)->whereNull('visit_id')->count(),
        ];

        // tickets by status (Arabic)
        $byStatus = (clone $ticketsInRange)->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status')->toArray();

        $repeated = Ticket::selectRaw('category, branch_id, count(*) as total')
            ->whereNotNull('category')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('category', 'branch_id')
            ->having('total', '>=', 2)
            ->with('branch')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $recentTickets = Ticket::with(['branch', 'department'])
            ->whereBetween('created_at', [$from, $to])
            ->latest()->limit(8)->get();

        return view('dashboard.overview', compact('stats', 'ticketsByDept', 'bySource', 'byStatus', 'repeated', 'recentTickets', 'range'));
    }
}
