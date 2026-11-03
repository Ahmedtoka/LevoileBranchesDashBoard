<?php

namespace App\Services;

use App\Events\TicketStatusChanged;
use App\Exceptions\TicketTransitionDenied;
use App\Models\ChecklistQuestion;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Observation;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAnswer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketService
{
    /**
     * Create tickets from a failed checklist answer — one per responsible
     * department (with its own notification). Returns the number created.
     */
    public function createFromFailedAnswer(Visit $visit, VisitAnswer $answer): int
    {
        /** @var ChecklistQuestion $question */
        $question = $answer->question;
        if (! $question) {
            return 0;
        }

        $deptIds = [];
        $priority = $question->default_priority ?? 'medium';
        $shouldCreate = false;

        if ($question->input_type === 'options') {
            // Find the chosen option; create tickets if that option is flagged.
            $opt = collect($question->options ?? [])->first(fn ($o) => ($o['label'] ?? null) === $answer->result);
            if ($opt && ! empty($opt['creates_ticket'])) {
                $shouldCreate = true;
                $deptIds = $opt['department_ids'] ?? [];
                $priority = $opt['priority'] ?? $priority;
            }
        } elseif ($answer->result === 'fail' && $question->auto_create_ticket_on_fail) {
            $shouldCreate = true;
            $deptIds = $question->departmentIds();
        }

        if (! $shouldCreate) {
            return 0;
        }
        if (empty($deptIds)) {
            $deptIds = [null];
        }

        $sla = $question->sla_hours;
        $created = 0;

        // For people (HR) issues, record the concerned branch employees in the ticket.
        $description = $answer->comment;
        if ($question->is_people_issue) {
            $names = $answer->selectedEmployees->pluck('name')->all();
            if (! empty($names)) {
                $line = 'الموظفون المعنيون: '.implode('، ', $names);
                $description = $description ? $line."\n".$description : $line;
            }
        }

        foreach ($deptIds as $deptId) {
            $ticket = Ticket::create([
                'reference' => $this->referenceForDept($deptId),
                'title' => str($question->question_text)->limit(120),
                'description' => $description,
                'branch_id' => $visit->branch_id,
                'department_id' => $deptId,
                'visit_id' => $visit->id,
                'visit_answer_id' => $answer->id,
                'checklist_question_id' => $question->id,
                'created_by' => $visit->user_id,
                'status' => 'open',
                'priority' => $priority,
                'sla_hours' => $sla,
                'due_at' => $sla ? Carbon::now()->addHours($sla) : null,
                'category' => $this->guessCategory($question->question_text),
            ]);

            $this->log($ticket, $visit->user_id, 'created', null, 'open', 'طلب من الشيك ليست.');
            $this->notifyDepartmentManager($ticket);
            $this->autoAssign($ticket);
            $created++;
        }

        return $created;
    }

    /** Create a ticket manually (e.g. a maintenance request item). */
    /** Reference using the responsible department's 3-letter prefix (own running serial). */
    public function referenceForDept(?int $deptId): string
    {
        $prefix = $deptId ? Department::where('id', $deptId)->value('ticket_prefix') : null;

        return Ticket::nextReference($prefix ?: 'GEN');
    }

    /** Options: 'silent' => true creates and logs the ticket without notifying the manager or auto-assigning. */
    public function createManual(array $attrs, ?int $actorId = null, array $options = []): Ticket
    {
        $attrs['reference'] = $attrs['reference'] ?? $this->referenceForDept($attrs['department_id'] ?? null);
        $attrs['status'] = $attrs['status'] ?? 'open';
        $ticket = Ticket::create($attrs);

        $this->log($ticket, $actorId, 'created', null, $ticket->status, 'تم إنشاء الطلب.');

        if (! ($options['silent'] ?? false)) {
            $this->notifyDepartmentManager($ticket);
            $this->autoAssign($ticket);
        }

        return $ticket;
    }

    /**
     * May this user move the ticket to closed: its creator, super_admin / ops_manager, the branch's
     * store manager, the area manager of the branch, or the manager of the ticket's department.
     */
    /**
     * A ticket raised from a desk review (CCTV cameras, finance documents): nothing on site to photograph, so
     * closing needs no after photo and the reporter's approval is the verification (2026-11-03).
     */
    public static function fromDeskReview(Ticket $ticket): bool
    {
        return $ticket->observation_id !== null
            && Observation::whereKey($ticket->observation_id)
                ->whereHas('visit', fn ($q) => $q->where('type', 'desk'))->exists();
    }

    public function canClose(Ticket $ticket, User $user): bool
    {
        $userId = (int) $user->id;

        if ($ticket->created_by !== null && (int) $ticket->created_by === $userId) {
            return true;
        }

        // The manager of the department that reported the problem approves its solution.
        if ($user->is_department_manager && $user->department_id !== null && $ticket->observation_id !== null
            && (int) Observation::whereKey($ticket->observation_id)->value('reporting_department_id') === (int) $user->department_id) {
            return true;
        }

        if ($user->hasRole(['super_admin', 'ops_manager'])) {
            return true;
        }

        $branch = $ticket->branch;

        if ($branch) {
            if ($branch->manager_id !== null && (int) $branch->manager_id === $userId) {
                return true;
            }

            if ($user->branch_id !== null && (int) $user->branch_id === (int) $ticket->branch_id
                && $user->hasRole(['store_manager', 'branch_manager'])) {
                return true;
            }

            if ($user->hasRole('area_manager')) {
                // Query the relation: $branch->area resolves to the legacy `branches.area` text column.
                $areaManagerId = $branch->area()->value('manager_id');
                if ($areaManagerId !== null && (int) $areaManagerId === $userId) {
                    return true;
                }

                if ($user->branches()->where('branches.id', $branch->id)->exists()) {
                    return true;
                }
            }
        }

        return $user->is_department_manager
            && $user->department_id !== null
            && $ticket->department_id !== null
            && (int) $user->department_id === (int) $ticket->department_id;
    }

    /**
     * Auto-assign to a technician responsible for this branch in this department.
     * Manager can still re-assign afterwards.
     */
    public function autoAssign(Ticket $ticket): bool
    {
        if (! $ticket->department_id || ! $ticket->branch_id || $ticket->assigned_to) {
            return false;
        }

        $tech = User::where('department_id', $ticket->department_id)
            ->where('is_department_manager', false)
            ->where('active', true)
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $ticket->branch_id))
            ->first();

        if (! $tech) {
            return false;
        }

        $this->assign($ticket->fresh(), $tech, null);

        return true;
    }

    public function assign(Ticket $ticket, User $employee, ?User $actor = null): Ticket
    {
        $from = $ticket->status;

        $ticket->update([
            'assigned_to' => $employee->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $this->log($ticket, optional($actor)->id, 'assignment', $from, 'assigned',
            'تعيين للفني: '.$employee->name);

        $this->notify($employee->id, 'ticket_assigned', 'New ticket assigned',
            $ticket->reference.' — '.$ticket->title, $ticket->id);

        event(new TicketStatusChanged($ticket, $from, 'assigned'));

        return $ticket;
    }

    /** Technician declines an assigned task: back to the manager (rejected, unassigned). */
    public function declineByTechnician(Ticket $ticket, User $tech, string $note): Ticket
    {
        $from = $ticket->status;
        $ticket->update(['status' => 'rejected', 'assigned_to' => null]);
        $this->log($ticket, $tech->id, 'declined', $from, 'rejected', 'رفض الفني: '.$note);
        $this->notifyDepartmentManager($ticket);

        event(new TicketStatusChanged($ticket, $from, 'rejected'));

        return $ticket;
    }

    public function changeStatus(Ticket $ticket, string $status, ?User $actor = null, ?string $note = null): Ticket
    {
        // Closing is guarded before any write: authorised actor (system calls pass null) and an after photo.
        if ($status === 'closed') {
            if ($actor !== null && ! $this->canClose($ticket, $actor)) {
                throw TicketTransitionDenied::notAllowed();
            }

            $hasAfterEvidence = TicketUpdate::where('ticket_id', $ticket->id)
                ->where('evidence_kind', 'after')
                ->exists();
            if (! $hasAfterEvidence && ! self::fromDeskReview($ticket)) {
                throw TicketTransitionDenied::afterEvidenceRequired();
            }
        }

        $from = $ticket->status;

        $attrs = ['status' => $status];

        if ($status === 'waiting_approval') {
            $attrs['resolved_at'] = now();
        }

        if ($status === 'closed') {
            $attrs['closed_at'] = now();
        }

        if ($status === 'rejected') {
            $attrs['reopen_count'] = $ticket->reopen_count + 1;
        }

        $ticket->update($attrs);

        $this->log($ticket, optional($actor)->id, 'status_change', $from, $status, $note);

        // Notify the visit creator when work is finished and awaiting approval.
        if ($status === 'waiting_approval' && $ticket->created_by) {
            $this->notify($ticket->created_by, 'ticket_approval', 'Ticket waiting approval',
                $ticket->reference.' is fixed and waiting for your approval.', $ticket->id);
        }

        // Notify assignee on reject/reopen.
        if ($status === 'rejected' && $ticket->assigned_to) {
            $this->notify($ticket->assigned_to, 'ticket_rejected', 'Ticket reopened',
                $ticket->reference.' was rejected and reopened.', $ticket->id);
        }

        event(new TicketStatusChanged($ticket, $from, $status));

        return $ticket;
    }

    /**
     * Close a ticket because its observation was closed without action (duplicate, recording error).
     * An explicit admin action: it bypasses the after-photo rule, writes one `cancelled` log row, and does
     * NOT go through changeStatus nor fire TicketStatusChanged (the caller sets the observation's status).
     */
    public function cancel(Ticket $ticket, User $actor, string $note): Ticket
    {
        if (! $this->canClose($ticket, $actor)) {
            throw TicketTransitionDenied::notAllowed();
        }
        if ($ticket->status === 'closed') {
            throw TicketTransitionDenied::invalidTransition();
        }

        $from = $ticket->status;
        $ticket->update(['status' => 'closed', 'closed_at' => now()]);
        $this->log($ticket, $actor->id, 'cancelled', $from, 'closed', $note);

        return $ticket;
    }

    /** Plan H reply states: `working` -> in_progress, `solved` -> waiting_approval. */
    public const REPLY_STATES = ['working', 'solved'];

    /** Users of the ticket's department (manager or employee), plus super_admin / ops_manager, may reply. */
    public function canReply(Ticket $ticket, User $user): bool
    {
        if ($user->hasRole(['super_admin', 'ops_manager'])) {
            return true;
        }

        return $ticket->department_id !== null
            && $user->department_id !== null
            && (int) $user->department_id === (int) $ticket->department_id;
    }

    /**
     * The responsible department's one-tap answer (Plan H): `working` assigns the ticket to the actor when
     * nobody holds it and walks the cycle to `in_progress` in one call (assigned -> on_the_way ->
     * in_progress, a log row per step; the note goes on the last one, or on a plain `comment` row when the
     * ticket is already in progress). `solved` does the same chain, records `$photoPath` as `after`
     * evidence (skipped when that path is already on the ticket) and moves to `waiting_approval`, so the
     * approver can close. The note is mandatory (min 3 chars). Throws AuthorizationException for other
     * departments, ValidationException for a bad state / note, TicketTransitionDenied (422) when the
     * ticket is already waiting approval or closed.
     */
    public function reply(Ticket $ticket, User $actor, string $state, string $note, ?string $photoPath = null): Ticket
    {
        if (! $this->canReply($ticket, $actor)) {
            throw new AuthorizationException(dt('هذا الطلب ليس من اختصاص إدارتك.', 'This ticket is not routed to your department.'));
        }
        if (! in_array($state, self::REPLY_STATES, true)) {
            throw ValidationException::withMessages(['state' => dt('نوع الرد غير صحيح.', 'Invalid reply state.')]);
        }
        $note = trim($note);
        if (mb_strlen($note) < 3) {
            throw ValidationException::withMessages(['note' => dt('اكتب ملاحظة مع الرد.', 'A note is required with the reply.')]);
        }
        if (in_array($ticket->status, ['waiting_approval', 'closed'], true)) {
            throw TicketTransitionDenied::invalidTransition();
        }

        return DB::transaction(function () use ($ticket, $actor, $state, $note, $photoPath) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (in_array($ticket->status, ['waiting_approval', 'closed'], true)) {
                throw TicketTransitionDenied::invalidTransition();
            }

            $marker = $state === 'working'
                ? dt('رد الإدارة: جاري العمل عليها', 'Department reply: working on it')
                : dt('رد الإدارة: تم الحل', 'Department reply: solved');

            if ($ticket->status === 'open' || $ticket->assigned_to === null) {
                $ticket = $this->assign($ticket, $ticket->assigned_to ? User::findOrFail($ticket->assigned_to) : $actor, $actor)->fresh();
            }

            // Walk forward to in_progress; the last step of the chain carries the note for `working`.
            $path = match ($ticket->status) {
                'assigned' => ['on_the_way', 'in_progress'],
                'on_the_way', 'postponed', 'not_fixed', 'rejected' => ['in_progress'],
                default => [],
            };
            foreach ($path as $i => $to) {
                $last = $i === array_key_last($path);
                $ticket = $this->changeStatus($ticket, $to, $actor, $last && $state === 'working' ? $note : $marker)->fresh();
            }

            if ($state === 'working') {
                if ($path === []) {
                    $this->log($ticket, $actor->id, 'comment', null, null, $note);
                }

                return $ticket->fresh();
            }

            if ($photoPath !== null && trim($photoPath) !== ''
                && ! TicketUpdate::where('ticket_id', $ticket->id)->where('evidence_path', $photoPath)->exists()) {
                $this->addEvidence($ticket, trim($photoPath), 'after', $actor);
            }

            return $this->changeStatus($ticket, 'waiting_approval', $actor, $note)->fresh();
        });
    }

    public function addEvidence(Ticket $ticket, string $path, string $kind = 'after', ?User $actor = null): TicketUpdate
    {
        return $this->log($ticket, optional($actor)->id, 'evidence', null, null,
            ucfirst($kind).' photo uploaded.', $path, $kind);
    }

    public function log(
        Ticket $ticket,
        ?int $userId,
        string $action,
        ?string $from = null,
        ?string $to = null,
        ?string $note = null,
        ?string $evidencePath = null,
        ?string $evidenceKind = null
    ): TicketUpdate {
        return TicketUpdate::create([
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'evidence_path' => $evidencePath,
            'evidence_kind' => $evidenceKind,
        ]);
    }

    protected function notifyDepartmentManager(Ticket $ticket): void
    {
        if (! $ticket->department_id) {
            return;
        }

        $manager = User::where('department_id', $ticket->department_id)
            ->where('is_department_manager', true)
            ->first();

        if ($manager) {
            $this->notify($manager->id, 'ticket_new', 'New ticket in your board',
                $ticket->reference.' — '.$ticket->title, $ticket->id);
        }
    }

    public function notify(int $userId, string $type, string $title, ?string $body = null, ?int $ticketId = null): void
    {
        Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'ticket_id' => $ticketId,
            'link' => $ticketId ? '/tickets/'.$ticketId : null,
        ]);
    }

    protected function guessCategory(string $text): ?string
    {
        $map = [
            'lamp' => ['lamp', 'light', 'إضاءة', 'لمبة', 'اضاءة'],
            'pos' => ['pos', 'visa', 'فيزا', 'كاش', 'ماكينة'],
            'cctv' => ['cctv', 'camera', 'كاميرا'],
            'cleaning' => ['clean', 'نظافة', 'نظيف'],
            'stock' => ['stock', 'مخزن', 'نواقص', 'بضاعة'],
            'maintenance' => ['maintenance', 'صيانة'],
        ];

        $lower = mb_strtolower($text);

        foreach ($map as $cat => $needles) {
            foreach ($needles as $n) {
                if (str_contains($lower, mb_strtolower($n))) {
                    return $cat;
                }
            }
        }

        return null;
    }
}
