<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(protected TicketService $service) {}

    /** GET /api/tickets/mine — tickets assigned to the current employee */
    public function mine(Request $request): JsonResponse
    {
        $tickets = Ticket::with(['branch', 'department'])
            ->where('assigned_to', $request->user()->id)
            ->latest()
            ->get();

        return response()->json(['data' => $tickets->map(fn ($t) => $this->item($t))]);
    }

    /** GET /api/my/requests — store manager's own requests: stats + list, by date range. */
    public function myRequests(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $from = $request->query('from');
        $to = $request->query('to');
        $status = $request->query('status', 'all'); // open | closed | all

        $base = Ticket::where('created_by', $userId);
        if ($from) {
            $base->where('created_at', '>=', $from);
        }
        if ($to) {
            $base->where('created_at', '<=', $to);
        }

        $stats = [
            'total' => (clone $base)->count(),
            'new' => (clone $base)->where('status', 'open')->count(),
            'assigned' => (clone $base)->whereIn('status', ['assigned', 'on_the_way'])->count(),
            'in_progress' => (clone $base)->where('status', 'in_progress')->count(),
            'waiting_approval' => (clone $base)->where('status', 'waiting_approval')->count(),
        ];

        $list = (clone $base)->with(['branch', 'assignee'])
            ->when($status === 'open', fn ($q) => $q->whereNotIn('status', ['closed']))
            ->when($status === 'closed', fn ($q) => $q->where('status', 'closed'))
            ->latest()->get()
            ->map(fn ($t) => $this->item($t));

        return response()->json(['stats' => $stats, 'data' => $list]);
    }

    /** GET /api/tickets/raised — problems raised by the current user (their visits). */
    public function raised(Request $request): JsonResponse
    {
        $tickets = Ticket::with(['branch', 'department', 'assignee'])
            ->where('created_by', $request->user()->id)
            ->latest()
            ->get();

        return response()->json(['data' => $tickets->map(fn ($t) => $this->item($t))]);
    }

    /** GET /api/departments/tickets — board for a department manager */
    public function department(Request $request): JsonResponse
    {
        $user = $request->user();
        $deptId = $request->query('department_id', $user->department_id);

        $tickets = Ticket::with(['branch', 'department', 'assignee'])
            ->where('department_id', $deptId)
            ->latest()
            ->get();

        return response()->json(['data' => $tickets->map(fn ($t) => $this->item($t))]);
    }

    /** GET /api/departments/overview — dashboard stats for the department manager. */
    public function departmentOverview(Request $request): JsonResponse
    {
        $deptId = $request->query('department_id', $request->user()->department_id);
        $from = $request->query('from');
        $to = $request->query('to');
        $base = Ticket::where('department_id', $deptId)
            ->when($from, fn ($x) => $x->where('created_at', '>=', $from))
            ->when($to, fn ($x) => $x->where('created_at', '<=', $to));

        $openBase = (clone $base)->whereNotIn('status', ['closed', 'waiting_approval']);
        $countIn = fn (array $st) => (clone $base)->whereIn('status', $st)->count();

        $stats = [
            // matrix the maintenance manager monitors
            'all' => (clone $base)->count(),
            'new_group' => $countIn(['open', 'assigned']),        // assigned/unassigned, not yet accepted
            'accepted' => $countIn(['on_the_way', 'in_progress', 'waiting_approval']),
            'rejected' => $countIn(['rejected']),
            'not_started' => $countIn(['on_the_way']),            // accepted, work not started
            'started' => $countIn(['in_progress']),               // work started
            'postponed' => $countIn(['postponed']),
            'not_fixed' => $countIn(['not_fixed']),
            // kept for compatibility
            'branches_with_problems' => (clone $openBase)->distinct('branch_id')->count('branch_id'),
            'new' => (clone $base)->where('status', 'open')->count(),
            'open' => (clone $openBase)->count(),
            'over_1_day' => (clone $openBase)->where('created_at', '<', now()->subDay())->count(),
        ];

        $employees = User::where('department_id', $deptId)
            ->where('is_department_manager', false)
            ->withCount([
                'assignedTickets as open_count' => fn ($q) => $q->whereIn('status', ['assigned', 'on_the_way']),
                'assignedTickets as working_count' => fn ($q) => $q->where('status', 'in_progress'),
            ])
            ->orderByDesc('open_count')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'open' => $u->open_count, 'working' => $u->working_count]);

        return response()->json(['stats' => $stats, 'employees' => $employees]);
    }

    /** GET /api/tickets/{ticket} */
    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $ticket->load([
            'branch', 'department', 'assignee', 'creator',
            'visit.template', 'answer.evidence', 'question', 'updates.user',
        ]);

        return response()->json([
            'ticket' => $this->item($ticket, true),
            'evidence' => optional($ticket->answer)->evidence?->map(fn ($e) => ['url' => $e->url, 'kind' => $e->kind]) ?? [],
            'timeline' => $ticket->updates->sortByDesc('id')->values()->map(fn ($u) => [
                'id' => $u->id,
                'action' => $u->action,
                'from_status' => $u->from_status,
                'to_status' => $u->to_status,
                'note' => $u->note,
                'evidence_url' => $u->evidence_path ? (str_starts_with($u->evidence_path, 'http') ? $u->evidence_path : asset('storage/'.$u->evidence_path)) : null,
                'by' => optional($u->user)->name,
                'at' => $u->created_at->toDateTimeString(),
            ]),
        ]);
    }

    /** PATCH /api/tickets/{ticket}/status */
    public function updateStatus(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', Ticket::STATUSES)],
            'note' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        if (! Ticket::canTransition($ticket->status, $data['status'])) {
            return response()->json(['message' => 'انتقال غير مسموح في دورة الحالة.'], 422);
        }

        $note = $data['note'] ?? null;
        if (isset($data['latitude'], $data['longitude'])) {
            $loc = 'الموقع: '.round($data['latitude'], 6).','.round($data['longitude'], 6);
            $note = $note ? $note.' · '.$loc : $loc;
        }

        $this->service->changeStatus($ticket, $data['status'], $request->user(), $note);

        return response()->json(['message' => 'Ticket updated.', 'ticket' => $this->item($ticket->fresh())]);
    }

    /** POST /api/tickets/{ticket}/evidence — before/after photo */
    public function uploadEvidence(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'photo' => ['required', 'image', 'max:8192'],
            'kind' => ['nullable', 'in:before,after,photo'],
        ]);

        $path = $request->file('photo')->store('evidence/tickets/'.$ticket->id, 'public');
        $this->service->addEvidence($ticket, $path, $data['kind'] ?? 'after', $request->user());

        return response()->json(['message' => 'Evidence uploaded.', 'url' => asset('storage/'.$path)]);
    }

    /** POST /api/tickets/{ticket}/decline — technician rejects an assigned task (note required). */
    public function decline(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string']]);
        $this->service->declineByTechnician($ticket, $request->user(), $data['note']);

        return response()->json(['message' => 'تم رفض المهمة وإعادتها للمدير.']);
    }

    /** POST /api/tickets/{ticket}/send-for-approval */
    public function sendForApproval(Request $request, Ticket $ticket): JsonResponse
    {
        $this->service->changeStatus($ticket, 'waiting_approval', $request->user(),
            $request->input('note', 'Marked fixed, waiting approval.'));

        return response()->json(['message' => 'Sent for approval.']);
    }

    /** POST /api/tickets/{ticket}/approve */
    public function approve(Request $request, Ticket $ticket): JsonResponse
    {
        $this->service->changeStatus($ticket, 'closed', $request->user(),
            $request->input('note', 'Approved and closed.'));

        return response()->json(['message' => 'Ticket approved and closed.']);
    }

    /** POST /api/tickets/{ticket}/reject */
    public function reject(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string']]);
        $this->service->changeStatus($ticket, 'rejected', $request->user(),
            $data['note'] ?? 'Rejected and reopened.');

        return response()->json(['message' => 'Ticket reopened.']);
    }

    /** POST /api/tickets/{ticket}/assign */
    public function assign(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:users,id'],
            'priority' => ['nullable', 'in:'.implode(',', Ticket::PRIORITIES)],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $update = [];
        if (! empty($data['priority'])) {
            $update['priority'] = $data['priority'];
        }
        if (! empty($data['scheduled_at'])) {
            $update['scheduled_at'] = $data['scheduled_at'];
        }
        if ($update) {
            $ticket->update($update);
        }

        $employee = User::findOrFail($data['employee_id']);
        $this->service->assign($ticket, $employee, $request->user());

        return response()->json(['message' => 'Ticket assigned.', 'ticket' => $this->item($ticket->fresh('assignee'))]);
    }

    /** GET /api/departments/employees */
    public function departmentEmployees(Request $request): JsonResponse
    {
        $deptId = $request->query('department_id', $request->user()->department_id);

        $employees = User::where('department_id', $deptId)
            ->where('is_department_manager', false)
            ->get(['id', 'name', 'email']);

        return response()->json(['data' => $employees]);
    }

    /** GET /api/departments/branches?from=&to= — per-branch summary for the department. */
    public function departmentBranches(Request $request): JsonResponse
    {
        $deptId = $request->query('department_id', $request->user()->department_id);
        $from = $request->query('from');
        $to = $request->query('to');

        $base = Ticket::where('department_id', $deptId);
        if ($from) {
            $base->where('created_at', '>=', $from);
        }
        if ($to) {
            $base->where('created_at', '<=', $to);
        }

        $rows = (clone $base)->with('branch')->get()->groupBy('branch_id')->map(function ($group) {
            $first = $group->first();

            return [
                'branch_id' => $first->branch_id,
                'branch' => optional($first->branch)->branch_name ?? '—',
                'total' => $group->count(),
                'new' => $group->where('status', 'open')->count(),
                'in_progress' => $group->whereIn('status', ['assigned', 'on_the_way', 'in_progress'])->count(),
                'closed' => $group->where('status', 'closed')->count(),
                'open' => $group->whereNotIn('status', ['closed'])->count(),
                'unassigned' => $group->whereNull('assigned_to')->whereNotIn('status', ['closed'])->count(),
            ];
        })->values()->sortByDesc('open')->values();

        return response()->json(['data' => $rows]);
    }

    /** GET /api/departments/branch-tickets?branch_id=&status=open&from=&to= */
    public function branchTickets(Request $request): JsonResponse
    {
        $deptId = $request->query('department_id', $request->user()->department_id);
        $branchId = $request->query('branch_id');
        $status = $request->query('status', 'all'); // open | closed | all
        $from = $request->query('from');
        $to = $request->query('to');

        // status can be: open | closed | all | a single status | comma-separated statuses
        $list = ($status !== 'open' && $status !== 'closed' && $status !== 'all')
            ? array_values(array_intersect(explode(',', $status), Ticket::STATUSES))
            : [];

        $q = Ticket::with(['branch', 'assignee'])
            ->where('department_id', $deptId)
            ->when($branchId, fn ($x) => $x->where('branch_id', $branchId))
            ->when($status === 'open', fn ($x) => $x->whereNotIn('status', ['closed']))
            ->when($status === 'closed', fn ($x) => $x->where('status', 'closed'))
            ->when(! empty($list), fn ($x) => $x->whereIn('status', $list))
            ->when($from, fn ($x) => $x->where('created_at', '>=', $from))
            ->when($to, fn ($x) => $x->where('created_at', '<=', $to))
            ->latest();

        return response()->json(['data' => $q->get()->map(fn ($t) => $this->item($t))]);
    }

    /** GET /api/departments/technicians — technicians with workload + branches that still have open tickets. */
    public function technicians(Request $request): JsonResponse
    {
        $deptId = $request->query('department_id', $request->user()->department_id);

        $techs = User::where('department_id', $deptId)
            ->where('is_department_manager', false)
            ->get(['id', 'name']);

        $openTickets = Ticket::with('branch')
            ->where('department_id', $deptId)
            ->whereNotIn('status', ['closed'])
            ->get();

        $data = $techs->map(function ($u) use ($openTickets) {
            $mine = $openTickets->where('assigned_to', $u->id);
            $branches = $mine->groupBy('branch_id')->map(fn ($g) => [
                'branch_id' => $g->first()->branch_id,
                'branch' => optional($g->first()->branch)->branch_name ?? '—',
                'open' => $g->count(),
            ])->values();

            return [
                'id' => $u->id,
                'name' => $u->name,
                'open' => $mine->whereIn('status', ['assigned', 'on_the_way'])->count(),
                'working' => $mine->where('status', 'in_progress')->count(),
                'total_open' => $mine->count(),
                'branches' => $branches,
            ];
        });

        return response()->json([
            'count' => $techs->count(),
            'unassigned' => $openTickets->whereNull('assigned_to')->count(),
            'data' => $data,
        ]);
    }

    /** GET /api/departments/technician-tickets?technician_id=&branch_id= */
    public function technicianTickets(Request $request): JsonResponse
    {
        $deptId = $request->query('department_id', $request->user()->department_id);
        $techId = $request->query('technician_id');
        $branchId = $request->query('branch_id');

        $q = Ticket::with(['branch', 'assignee'])
            ->where('department_id', $deptId)
            ->where('assigned_to', $techId)
            ->whereNotIn('status', ['closed'])
            ->when($branchId, fn ($x) => $x->where('branch_id', $branchId))
            ->latest();

        return response()->json(['data' => $q->get()->map(fn ($t) => $this->item($t))]);
    }

    /** POST /api/departments/ticket/{ticket}/status — a department manager progresses their own ticket. */
    public function deptSetStatus(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $user->is_department_manager || $user->department_id !== $ticket->department_id) {
            return response()->json(['message' => 'غير مسموح.'], 403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:in_progress,waiting_approval,closed,rejected'],
            'note' => ['nullable', 'string'],
        ]);

        $this->service->changeStatus($ticket, $data['status'], $user, $data['note'] ?? null);

        return response()->json(['message' => 'تم التحديث.', 'ticket' => $this->item($ticket->fresh())]);
    }

    /** POST /api/tickets/{ticket}/priority — department manager changes a ticket's priority. */
    public function updatePriority(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        if (! $user->is_department_manager || $user->department_id !== $ticket->department_id) {
            return response()->json(['message' => 'غير مسموح.'], 403);
        }

        $data = $request->validate([
            'priority' => ['required', 'in:'.implode(',', Ticket::PRIORITIES)],
        ]);

        $old = $ticket->priority;
        $ticket->update(['priority' => $data['priority']]);

        $labels = ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', 'critical' => 'حرجة'];
        $this->service->log($ticket, $user->id, 'priority', null, null,
            'تغيير الأولوية: '.($labels[$old] ?? $old).' ← '.($labels[$data['priority']] ?? $data['priority']));

        return response()->json(['message' => 'تم تحديث الأولوية.', 'ticket' => $this->item($ticket->fresh())]);
    }

    /** POST /api/departments/assign-bulk { ticket_ids:[], employee_id, scheduled_at } */
    public function assignBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
            'employee_id' => ['required', 'exists:users,id'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $employee = User::findOrFail($data['employee_id']);
        $count = 0;
        foreach ($data['ticket_ids'] as $id) {
            $ticket = Ticket::find($id);
            if (! $ticket) {
                continue;
            }
            if (! empty($data['scheduled_at'])) {
                $ticket->update(['scheduled_at' => $data['scheduled_at']]);
            }
            $this->service->assign($ticket, $employee, $request->user());
            $count++;
        }

        return response()->json(['message' => "تم تعيين {$count} مهمة.", 'count' => $count]);
    }

    protected function item(Ticket $t, bool $full = false): array
    {
        $base = [
            'id' => $t->id,
            'reference' => $t->reference,
            'group_code' => $t->group_code,
            'title' => $t->title,
            'status' => $t->status,
            'priority' => $t->priority,
            'category' => $t->category,
            'branch' => optional($t->branch)->branch_name,
            'branch_id' => $t->branch_id,
            'department' => optional($t->department)->name,
            'assignee' => optional($t->assignee)->name,
            'assigned_to' => $t->assigned_to,
            'due_at' => optional($t->due_at)->toDateTimeString(),
            'scheduled_at' => optional($t->scheduled_at)->toDateTimeString(),
            'overdue' => $t->isOverdue(),
            'age_hours' => $t->ageInHours(),
            'created_at' => $t->created_at->toDateTimeString(),
        ];

        if ($full) {
            $base['description'] = $t->description;
            $base['created_by'] = optional($t->creator)->name;
            $base['visit_id'] = $t->visit_id;
            $base['question'] = optional($t->question)->question_text;
            $base['reopen_count'] = $t->reopen_count;
        }

        return $base;
    }
}
