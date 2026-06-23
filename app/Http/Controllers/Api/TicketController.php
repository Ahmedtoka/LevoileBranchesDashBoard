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
        $base = Ticket::where('department_id', $deptId);

        $openBase = (clone $base)->whereNotIn('status', ['closed', 'waiting_approval']);

        $stats = [
            'branches_with_problems' => (clone $openBase)->distinct('branch_id')->count('branch_id'),
            'new' => (clone $base)->where('status', 'open')->count(),
            'open' => (clone $openBase)->count(),
            'over_1_day' => (clone $openBase)->where('created_at', '<', now()->subDay())->count(),
            'postponed' => (clone $base)->where('status', 'postponed')->count(),
            'not_fixed' => (clone $base)->where('status', 'not_fixed')->count(),
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
            'timeline' => $ticket->updates->map(fn ($u) => [
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
        ]);

        if (! Ticket::canTransition($ticket->status, $data['status'])) {
            return response()->json(['message' => 'انتقال غير مسموح في دورة الحالة.'], 422);
        }

        $this->service->changeStatus($ticket, $data['status'], $request->user(), $data['note'] ?? null);

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
