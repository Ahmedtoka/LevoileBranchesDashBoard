<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MaintenanceDashboardController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $q = $request->query('q');
        $dept = Department::where('slug', 'maintenance')->first();
        $deptId = optional($dept)->id;

        $base = Ticket::where('department_id', $deptId)->whereBetween('created_at', [$range->from, $range->to]);
        $open = (clone $base)->whereNotIn('status', ['closed', 'waiting_approval']);

        $stats = [
            'total' => (clone $base)->count(),
            'open' => (clone $open)->count(),
            'new' => (clone $base)->where('status', 'open')->count(),
            'in_progress' => (clone $base)->whereIn('status', ['assigned', 'on_the_way', 'in_progress'])->count(),
            'postponed' => (clone $base)->where('status', 'postponed')->count(),
            'not_fixed' => (clone $base)->where('status', 'not_fixed')->count(),
            'closed' => (clone $base)->where('status', 'closed')->count(),
            'over_1_day' => (clone $open)->where('created_at', '<', now()->subDay())->count(),
            'branches_with_problems' => (clone $open)->distinct('branch_id')->count('branch_id'),
        ];

        $byBranch = (clone $base)->selectRaw('branch_id, count(*) total, sum(case when status="closed" then 1 else 0 end) closed, sum(case when status not in ("closed","waiting_approval") then 1 else 0 end) open')
            ->groupBy('branch_id')->with('branch')->orderByDesc('total')->get();

        $byCategory = (clone $base)->selectRaw('category, count(*) total, sum(case when status not in ("closed","waiting_approval") then 1 else 0 end) open')
            ->whereNotNull('category')->groupBy('category')->orderByDesc('total')->get();

        // Cycle funnel — counts per stage in order.
        $cycle = [];
        foreach (['open', 'assigned', 'on_the_way', 'in_progress', 'waiting_approval', 'postponed', 'not_fixed', 'closed'] as $s) {
            $cycle[$s] = (clone $base)->where('status', $s)->count();
        }

        // By technician.
        $byEmployee = User::where('department_id', $deptId)->where('is_department_manager', false)
            ->withCount([
                'assignedTickets as open' => fn ($x) => $x->whereIn('status', ['assigned', 'on_the_way']),
                'assignedTickets as working' => fn ($x) => $x->where('status', 'in_progress'),
                'assignedTickets as done' => fn ($x) => $x->where('status', 'closed'),
                'assignedTickets as total' => fn ($x) => $x,
            ])
            ->orderByDesc('open')->get();

        $latest = (clone $base)->with(['branch', 'assignee'])
            ->when($q, fn ($x) => $x->where('title', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%"))
            ->latest()->limit(12)->get();

        return view('dashboard.maintenance.index', compact('range', 'stats', 'byBranch', 'byCategory', 'cycle', 'byEmployee', 'latest'));
    }

    /** Wipe all operational data (tickets, visits, notifications) — keeps structure. */
    public function wipe()
    {
        Schema::disableForeignKeyConstraints();
        foreach (['ticket_updates', 'tickets', 'visit_answer_evidence', 'visit_answer_selected_employees', 'visit_answers', 'visits', 'notifications'] as $t) {
            DB::table($t)->truncate();
        }
        Schema::enableForeignKeyConstraints();

        return back()->with('status', 'تم مسح كل التذاكر والزيارات والإشعارات.');
    }

    /** Generate demo data from the branch maintenance sheet. */
    public function generate()
    {
        $this->wipe();
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DemoVisitSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\BranchMaintenanceSeeder', '--force' => true]);

        return back()->with('status', 'تم توليد داتا الديمو من شيت صيانة الفروع.');
    }
}
