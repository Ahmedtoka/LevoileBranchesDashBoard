<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use App\Support\DateRange;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(protected TicketService $service) {}

    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $q = $request->query('q');

        $query = Ticket::with(['branch', 'department', 'assignee'])
            ->whereBetween('created_at', [$range->from, $range->to]);

        if ($request->filled('department')) {
            $query->whereHas('department', fn ($d) => $d->where('slug', $request->department));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('status')) {
            if ($request->status === 'open_group') {
                $query->whereNotIn('status', ['closed', 'waiting_approval']);
            } elseif ($request->status === 'over_1_day') {
                $query->whereNotIn('status', ['closed', 'waiting_approval'])->where('created_at', '<', now()->subDay());
            } else {
                $query->where('status', $request->status);
            }
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($q) {
            $query->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%"));
        }

        $base = Ticket::whereBetween('created_at', [$range->from, $range->to]);
        $stats = [
            'total' => (clone $base)->count(),
            'open' => (clone $base)->whereNotIn('status', ['closed'])->count(),
            'waiting_approval' => (clone $base)->where('status', 'waiting_approval')->count(),
            'closed' => (clone $base)->where('status', 'closed')->count(),
        ];

        $tickets = $query->latest()->paginate(20)->withQueryString();
        $departments = Department::orderBy('name')->get();

        return view('dashboard.tickets.index', compact('tickets', 'departments', 'range', 'stats'));
    }

    public function board(Request $request, string $slug)
    {
        $department = Department::where('slug', $slug)->firstOrFail();

        $columns = [];
        foreach (Ticket::STATUSES as $status) {
            $columns[$status] = Ticket::with(['branch', 'assignee'])
                ->where('department_id', $department->id)
                ->where('status', $status)
                ->latest()
                ->get();
        }

        $employees = User::where('department_id', $department->id)
            ->where('is_department_manager', false)->get();

        return view('dashboard.tickets.board', compact('department', 'columns', 'employees'));
    }

    public function show(Ticket $ticket)
    {
        $ticket->load([
            'branch', 'department', 'assignee', 'creator',
            'visit.template', 'answer.evidence', 'question', 'updates.user',
        ]);

        $employees = $ticket->department
            ? User::where('department_id', $ticket->department_id)->where('is_department_manager', false)->get()
            : collect();

        return view('dashboard.tickets.show', compact('ticket', 'employees'));
    }

    public function assign(Request $request, Ticket $ticket)
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

        $this->service->assign($ticket, User::findOrFail($data['employee_id']), $request->user());

        return back()->with('status', 'Ticket assigned.');
    }

    public function transition(Request $request, Ticket $ticket)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', Ticket::STATUSES)],
            'note' => ['nullable', 'string'],
        ]);

        // Forward-only cycle: reject transitions that aren't allowed from the current status.
        if (! Ticket::canTransition($ticket->status, $data['status'])) {
            return back()->with('status', 'انتقال غير مسموح في دورة الحالة.');
        }

        $this->service->changeStatus($ticket, $data['status'], $request->user(), $data['note'] ?? null);

        return back()->with('status', 'تم تحديث حالة التذكرة.');
    }
}
