<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * "مهام إدارتي" (owner request 2026-11-03): the problems another department sent to mine (CCTV, finance,
 * any observation), answered here with "working on it" / "solved", and the solutions of the problems my
 * department sent that wait for our approval (close, or send back). Closed ones stay visible for 30 days.
 */
class TasksController extends Controller
{
    public const TABS = ['incoming', 'approve', 'closed'];

    public function index(Request $request, TicketService $tickets)
    {
        $user = $request->user();
        $department = self::departmentFor($user, $request->integer('department_id') ?: null);
        abort_if($department === null, 403);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'incoming';
        $counts = [];
        foreach (self::TABS as $t) {
            $counts[$t] = self::query($t, $department->id)->count();
        }
        $counts['late'] = self::query('incoming', $department->id)->overdue()->count();

        $list = self::query($tab, $department->id)
            ->when($request->query('late') === '1', fn ($q) => $q->overdue())
            ->with(['branch', 'department', 'observation.category', 'observation.catalogItem', 'observation.reportingDepartment',
                // The last thing someone wrote (a reply, a return), not the system's "ticket created" row.
                'observation.employee', 'updates' => fn ($q) => $q->whereIn('action', ['status_change', 'comment', 'declined'])
                    ->whereNotNull('user_id')->whereNotNull('note')->where('note', '<>', '')->latest('id')->with('user')])
            ->orderByRaw($tab === 'closed' ? 'tickets.closed_at DESC' : 'tickets.due_at IS NULL, tickets.due_at ASC')
            ->orderByDesc('tickets.id')
            ->paginate(30)->withQueryString();

        return view('dashboard.tasks.index', [
            'department' => $department, 'tab' => $tab, 'counts' => $counts, 'tasks' => $list,
            'canReply' => fn (Ticket $t) => $tickets->canReply($t, $user),
            'canClose' => fn (Ticket $t) => $tickets->canClose($t, $user),
            'departments' => self::seesAll($user) ? Department::orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    /** Open tasks of the user's department: what came in, plus what waits for its approval (the menu badge). */
    public static function badge(User $user): int
    {
        $department = self::departmentFor($user);

        return $department ? self::query('incoming', $department->id)->count() + self::query('approve', $department->id)->count() : 0;
    }

    /** The department the page is about: the user's own, or any one for management (default: Operation). */
    public static function departmentFor(User $user, ?int $asked = null): ?Department
    {
        if (self::seesAll($user)) {
            return Department::find($asked ?? $user->department_id) ?? Department::where('slug', 'operation')->first();
        }

        return $user->department_id ? Department::find($user->department_id) : null;
    }

    private static function seesAll(User $user): bool
    {
        return $user->hasRole(['super_admin', 'ops_manager']);
    }

    private static function query(string $tab, int $departmentId): Builder
    {
        $sentByUs = fn ($q) => $q->where('reporting_department_id', $departmentId);

        return Ticket::query()->where('tickets.source', 'observation')->whereNotNull('tickets.observation_id')
            ->when($tab === 'incoming', fn ($q) => $q->where('tickets.department_id', $departmentId)
                ->whereNotIn('tickets.status', ['waiting_approval', 'closed']))
            ->when($tab === 'approve', fn ($q) => $q->where('tickets.status', 'waiting_approval')->whereHas('observation', $sentByUs))
            ->when($tab === 'closed', fn ($q) => $q->where('tickets.status', 'closed')->where('tickets.closed_at', '>=', now()->subDays(30))
                ->where(fn ($w) => $w->where('tickets.department_id', $departmentId)->orWhereHas('observation', $sentByUs)));
    }
}
