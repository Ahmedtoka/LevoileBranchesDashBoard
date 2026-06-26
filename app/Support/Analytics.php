<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Central analytics used by both the admin overview and the per-department
 * manager dashboard. Pass a $deptId to scope every ticket metric to one department.
 */
class Analytics
{
    public static function overview(Carbon $from, Carbon $to, ?int $deptId = null): array
    {
        // fresh scoped ticket builders
        $scoped = fn () => Ticket::query()->when($deptId, fn ($q) => $q->where('department_id', $deptId));
        $inRange = fn () => $scoped()->whereBetween('created_at', [$from, $to]);

        // ---- KPI cards ----
        $stats = [
            'branches' => Branch::where('active', true)->count(),
            'visits_total' => Visit::whereBetween('created_at', [$from, $to])->count(),
            'visits_completed' => Visit::whereBetween('created_at', [$from, $to])->where('status', 'completed')->count(),
            'visits_open' => Visit::whereBetween('created_at', [$from, $to])->whereIn('status', ['assigned', 'checked_in', 'in_progress'])->count(),
            'tickets_total' => $inRange()->count(),
            'tickets_open' => $inRange()->whereNotIn('status', ['closed'])->count(),
            'tickets_closed' => $inRange()->where('status', 'closed')->count(),
            'waiting_approval' => $inRange()->where('status', 'waiting_approval')->count(),
            'overdue' => $scoped()->whereNotNull('due_at')->where('due_at', '<', now())
                ->whereNotIn('status', ['closed', 'waiting_approval'])->count(),
        ];

        // ---- SLA / aging ----
        $closedQ = fn () => $scoped()->whereNotNull('closed_at')->whereBetween('closed_at', [$from, $to]);
        $avgRes = $closedQ()->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) as h'))->value('h');
        $closedWithDue = $closedQ()->whereNotNull('due_at')->count();
        $metSla = $closedQ()->whereNotNull('due_at')->whereColumn('closed_at', '<=', 'due_at')->count();
        $oldestOpen = $scoped()->whereNotIn('status', ['closed'])->min('created_at');

        $sla = [
            'avg_resolution' => $avgRes ? round($avgRes, 1) : null,
            'sla_pct' => $closedWithDue ? (int) round($metSla / $closedWithDue * 100) : null,
            'oldest_hours' => $oldestOpen ? (int) Carbon::parse($oldestOpen)->diffInHours(now()) : null,
            'closed' => $stats['tickets_closed'],
        ];

        // ---- by status ----
        $byStatus = $inRange()->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status')->toArray();

        // ---- by department (admin only) ----
        $byDept = $deptId ? collect() : Department::withCount([
            'tickets as open_count' => fn ($q) => $q->whereNotIn('status', ['closed'])->whereBetween('created_at', [$from, $to]),
            'tickets as closed_count' => fn ($q) => $q->where('status', 'closed')->whereBetween('created_at', [$from, $to]),
            'tickets as total_count' => fn ($q) => $q->whereBetween('created_at', [$from, $to]),
        ])->orderByDesc('total_count')->get();

        // ---- by source ----
        $bySource = [
            'store' => $inRange()->whereHas('visit.template', fn ($q) => $q->where('type', 'store_manager'))->count(),
            'area' => $inRange()->whereHas('visit.template', fn ($q) => $q->where('type', 'area_manager'))->count(),
            'maintenance' => $inRange()->whereNull('visit_id')->count(),
        ];

        // ---- daily trend: created vs closed ----
        $created = $inRange()->selectRaw('DATE(created_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');
        $closed = $scoped()->whereBetween('closed_at', [$from, $to])
            ->selectRaw('DATE(closed_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');

        $labels = [];
        $crSeries = [];
        $clSeries = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $guard = 0;
        while ($cursor->lessThanOrEqualTo($end) && $guard < 200) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->format('m-d');
            $crSeries[] = (int) ($created[$key] ?? 0);
            $clSeries[] = (int) ($closed[$key] ?? 0);
            $cursor->addDay();
            $guard++;
        }
        $trend = ['labels' => $labels, 'created' => $crSeries, 'closed' => $clSeries];

        // ---- top branches with open tickets ----
        $topBranches = $scoped()->whereNotIn('status', ['closed'])
            ->selectRaw('branch_id, count(*) as total')
            ->groupBy('branch_id')->with('branch')
            ->orderByDesc('total')->limit(8)->get();

        // ---- team performance ----
        $team = User::where('is_department_manager', false)
            ->whereNotNull('department_id')
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->withCount([
                'assignedTickets as assigned',
                'assignedTickets as closed' => fn ($q) => $q->where('status', 'closed'),
                'assignedTickets as pending' => fn ($q) => $q->whereNotIn('status', ['closed']),
            ])
            ->with('department')
            ->having('assigned', '>', 0)
            ->orderByDesc('assigned')
            ->limit(12)
            ->get()
            ->map(function ($u) {
                $avg = Ticket::where('assigned_to', $u->id)->whereNotNull('closed_at')
                    ->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, assigned_at, closed_at)) as h'))->value('h');
                $u->avg_hours = $avg ? round($avg, 1) : null;
                $u->rate = $u->assigned ? (int) round($u->closed / $u->assigned * 100) : 0;

                return $u;
            });

        // ---- repeated requests ----
        $repeated = $scoped()->selectRaw('category, branch_id, count(*) as total')
            ->whereNotNull('category')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('category', 'branch_id')
            ->having('total', '>=', 2)
            ->with('branch')
            ->orderByDesc('total')
            ->limit(10)->get();

        // ---- recent tickets ----
        $recentTickets = $scoped()->with(['branch', 'department', 'assignee'])
            ->whereBetween('created_at', [$from, $to])
            ->latest()->limit(8)->get();

        return compact(
            'stats', 'sla', 'byStatus', 'byDept', 'bySource', 'trend',
            'topBranches', 'team', 'repeated', 'recentTickets'
        );
    }
}
