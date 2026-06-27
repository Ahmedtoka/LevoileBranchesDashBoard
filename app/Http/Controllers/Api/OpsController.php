<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operations Manager cockpit: oversees area managers, branches, maintenance,
 * schedules visits (per-branch date/time), and watches tickets by source.
 */
class OpsController extends Controller
{
    /** GET /api/ops/overview */
    public function overview(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $visitBase = Visit::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $tickets = Ticket::query();

        return response()->json([
            'counts' => [
                'area_managers' => User::whereHas('role', fn ($q) => $q->where('slug', 'area_manager'))->where('active', true)->count(),
                'branches' => Branch::where('active', true)->count(),
                'maintenance_staff' => User::whereHas('department', fn ($q) => $q->where('slug', 'maintenance'))->count(),
                'visits_total' => (clone $visitBase)->count(),
                'visits_assigned' => (clone $visitBase)->whereIn('status', ['assigned'])->count(),
                'visits_in_progress' => (clone $visitBase)->whereIn('status', ['checked_in', 'in_progress'])->count(),
                'visits_completed' => (clone $visitBase)->where('status', 'completed')->count(),
                'visits_overdue' => (clone $visitBase)->whereDate('scheduled_date', '<', today())
                    ->whereIn('status', ['assigned', 'checked_in', 'in_progress'])->count(),
            ],
            'tickets' => [
                'store' => (clone $tickets)->whereHas('visit.template', fn ($q) => $q->where('type', 'store_manager'))->count(),
                'area' => (clone $tickets)->whereHas('visit.template', fn ($q) => $q->where('type', 'area_manager'))->count(),
                'maintenance' => (clone $tickets)->whereNull('visit_id')->count(),
                'open' => (clone $tickets)->whereNotIn('status', ['closed'])->count(),
            ],
        ]);
    }

    /** GET /api/ops/teams */
    public function teams(): JsonResponse
    {
        $areaManagers = User::whereHas('role', fn ($q) => $q->where('slug', 'area_manager'))
            ->where('active', true)
            ->withCount(['visits as open_visits' => fn ($q) => $q->whereIn('status', ['assigned', 'checked_in', 'in_progress'])])
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'open_visits' => $u->open_visits]);

        $branches = Branch::where('active', true)->with('manager:id,name')
            ->orderBy('branch_name')->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->branch_name,
                'manager' => optional($b->manager)->name,
            ]);

        $maintDept = Department::where('slug', 'maintenance')->first();
        $maintenance = [];
        if ($maintDept) {
            $maintenance = User::where('department_id', $maintDept->id)
                ->withCount([
                    'assignedTickets as open' => fn ($q) => $q->whereNotIn('status', ['closed']),
                ])
                ->get(['id', 'name', 'is_department_manager'])
                ->map(fn ($u) => [
                    'id' => $u->id, 'name' => $u->name,
                    'manager' => (bool) $u->is_department_manager, 'open' => $u->open,
                ]);
        }

        return response()->json([
            'area_managers' => $areaManagers,
            'branches' => $branches,
            'maintenance' => $maintenance,
        ]);
    }

    /** GET /api/ops/pickers — data for the schedule form. */
    public function pickers(): JsonResponse
    {
        return response()->json([
            'templates' => VisitTemplate::where('active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'users' => User::whereHas('role', fn ($q) => $q->whereIn('slug', ['area_manager', 'store_manager', 'vm_manager', 'ops_manager']))
                ->where('active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'branches' => Branch::where('active', true)->orderBy('branch_name')->get(['id', 'branch_name'])
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->branch_name]),
        ]);
    }

    /** POST /api/ops/schedule — one visit per branch, each with its own date/time. */
    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visit_template_id' => ['required', 'exists:visit_templates,id'],
            'user_id' => ['required', 'exists:users,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.branch_id' => ['required', 'exists:branches,id'],
            'items.*.scheduled_date' => ['required', 'date'],
            'items.*.scheduled_time' => ['nullable', 'string'],
        ]);

        $count = 0;
        foreach ($data['items'] as $item) {
            Visit::create([
                'visit_template_id' => $data['visit_template_id'],
                'branch_id' => $item['branch_id'],
                'user_id' => $data['user_id'],
                'status' => 'assigned',
                'scheduled_date' => $item['scheduled_date'],
                'scheduled_time' => $item['scheduled_time'] ?? null,
            ]);
            $count++;
        }

        return response()->json(['message' => "تم جدولة {$count} زيارة.", 'count' => $count]);
    }

    /** GET /api/ops/visits?status=&from=&to= */
    public function visits(Request $request): JsonResponse
    {
        $status = $request->query('status', 'all'); // all | assigned | in_progress | completed | overdue
        $type = $request->query('type', 'all');     // all | area | store
        $from = $request->query('from');
        $to = $request->query('to');

        $q = Visit::with(['branch:id,branch_name', 'user:id,name', 'template:id,name,type'])
            ->when($from, fn ($x) => $x->whereDate('scheduled_date', '>=', substr($from, 0, 10)))
            ->when($to, fn ($x) => $x->whereDate('scheduled_date', '<=', substr($to, 0, 10)))
            ->when($type === 'area', fn ($x) => $x->whereHas('template', fn ($t) => $t->where('type', 'area_manager')))
            ->when($type === 'store', fn ($x) => $x->whereHas('template', fn ($t) => $t->where('type', 'store_manager')))
            ->when($status === 'assigned', fn ($x) => $x->where('status', 'assigned'))
            ->when($status === 'in_progress', fn ($x) => $x->whereIn('status', ['checked_in', 'in_progress']))
            ->when($status === 'completed', fn ($x) => $x->where('status', 'completed'))
            ->when($status === 'overdue', fn ($x) => $x->whereDate('scheduled_date', '<', today())
                ->whereIn('status', ['assigned', 'checked_in', 'in_progress']))
            ->latest('scheduled_date');

        return response()->json([
            'data' => $q->get()->map(fn ($v) => [
                'id' => $v->id,
                'branch' => optional($v->branch)->branch_name,
                'branch_id' => $v->branch_id,
                'user' => optional($v->user)->name,
                'template' => optional($v->template)->name,
                'template_type' => optional($v->template)->type,
                'status' => $v->status,
                'scheduled_date' => optional($v->scheduled_date)->toDateString(),
                'scheduled_time' => $v->scheduled_time,
                'positives' => $v->positives_count,
                'problems' => $v->problems_count,
                'tickets' => $v->tickets_count,
                'completed_at' => optional($v->completed_at)->toDateTimeString(),
                'overdue' => $v->scheduled_date && $v->scheduled_date->isPast()
                    && in_array($v->status, ['assigned', 'checked_in', 'in_progress'], true),
            ]),
        ]);
    }

    /** GET /api/ops/tickets?category=store|area|maintenance */
    public function tickets(Request $request): JsonResponse
    {
        $cat = $request->query('category', 'store');

        $q = Ticket::with(['branch:id,branch_name', 'department:id,name', 'assignee:id,name'])
            ->when($cat === 'store', fn ($x) => $x->whereHas('visit.template', fn ($t) => $t->where('type', 'store_manager')))
            ->when($cat === 'area', fn ($x) => $x->whereHas('visit.template', fn ($t) => $t->where('type', 'area_manager')))
            ->when($cat === 'maintenance', fn ($x) => $x->whereNull('visit_id'))
            ->latest();

        return response()->json([
            'data' => $q->get()->map(fn ($t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'title' => $t->title,
                'status' => $t->status,
                'priority' => $t->priority,
                'branch' => optional($t->branch)->branch_name,
                'branch_id' => $t->branch_id,
                'department' => optional($t->department)->name,
                'assignee' => optional($t->assignee)->name,
                'assigned_to' => $t->assigned_to,
                'overdue' => $t->isOverdue(),
                'created_at' => $t->created_at->toDateTimeString(),
                'age_hours' => $t->ageInHours(),
            ]),
        ]);
    }
}
