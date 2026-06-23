<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);
        $slug = Str::slug($data['name'], '_');

        Department::updateOrCreate(['slug' => $slug], [
            'name' => $data['name'],
            'color' => $data['color'] ?? '#64748b',
            'active' => true,
        ]);

        return back()->with('status', 'Department saved.');
    }

    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'active' => ['nullable', 'boolean'],
        ]);

        $department->update([
            'name' => $data['name'],
            'color' => $data['color'] ?? $department->color,
            'active' => $request->boolean('active'),
        ]);

        return back()->with('status', 'Department updated.');
    }

    public function destroy(Department $department)
    {
        if ($department->tickets()->exists() || $department->users()->exists()) {
            return back()->with('status', 'Cannot delete: department has tickets or users. Set it inactive instead.');
        }

        $department->delete();

        return back()->with('status', 'Department deleted.');
    }

    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $q = $request->query('q');

        $departments = Department::query()
            ->when($q, fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->get()
            ->map(function ($d) use ($range) {
                $base = Ticket::where('department_id', $d->id)->whereBetween('created_at', [$range->from, $range->to]);
                $d->total = (clone $base)->count();
                $d->open = (clone $base)->whereNotIn('status', ['closed'])->count();
                $d->closed = (clone $base)->where('status', 'closed')->count();
                $d->overdue = (clone $base)->whereNotNull('due_at')->where('due_at', '<', now())
                    ->whereNotIn('status', ['closed', 'waiting_approval'])->count();
                $d->employees_count = User::where('department_id', $d->id)->where('is_department_manager', false)->count();

                return $d;
            });

        $summary = [
            'departments' => $departments->count(),
            'total' => $departments->sum('total'),
            'open' => $departments->sum('open'),
            'closed' => $departments->sum('closed'),
            'overdue' => $departments->sum('overdue'),
        ];

        return view('dashboard.departments.index', compact('departments', 'range', 'summary'));
    }

    public function board(Request $request, Department $department)
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(['super_admin', 'branch_director', 'area_manager', 'ops_manager']);
        $isOwnManager = $user->is_department_manager && $user->department_id === $department->id;

        abort_unless($isAdmin || $isOwnManager, 403, 'You can only view your own department.');

        $range = DateRange::fromRequest($request);
        $q = $request->query('q');
        $statusFilter = $request->query('status');

        $base = Ticket::where('department_id', $department->id)->whereBetween('created_at', [$range->from, $range->to]);

        $stats = [
            'total' => (clone $base)->count(),
            'open' => (clone $base)->where('status', 'open')->count(),
            'assigned' => (clone $base)->where('status', 'assigned')->count(),
            'in_progress' => (clone $base)->whereIn('status', ['on_the_way', 'in_progress'])->count(),
            'waiting_approval' => (clone $base)->where('status', 'waiting_approval')->count(),
            'closed' => (clone $base)->where('status', 'closed')->count(),
            'overdue' => (clone $base)->whereNotNull('due_at')->where('due_at', '<', now())
                ->whereNotIn('status', ['closed', 'waiting_approval'])->count(),
        ];

        $tickets = Ticket::with(['branch', 'assignee'])
            ->where('department_id', $department->id)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
            ->when($q, fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%");
            }))
            ->latest()
            ->get();

        // Employees with workload (highlight those with no tickets).
        $employees = User::where('department_id', $department->id)
            ->where('is_department_manager', false)
            ->withCount([
                'assignedTickets as assigned' => fn ($qq) => $qq->where('department_id', $department->id),
                'assignedTickets as open' => fn ($qq) => $qq->where('department_id', $department->id)->whereNotIn('status', ['closed']),
                'assignedTickets as closed' => fn ($qq) => $qq->where('department_id', $department->id)->where('status', 'closed'),
            ])
            ->orderByDesc('open')
            ->get();

        // Branches involved.
        $branches = Ticket::where('department_id', $department->id)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw('branch_id, count(*) as total, sum(case when status != "closed" then 1 else 0 end) as open')
            ->groupBy('branch_id')
            ->with('branch')
            ->orderByDesc('total')
            ->get();

        return view('dashboard.departments.board', compact(
            'department', 'range', 'stats', 'tickets', 'employees', 'branches', 'statusFilter'
        ));
    }
}
