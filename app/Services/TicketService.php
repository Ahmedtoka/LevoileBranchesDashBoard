<?php

namespace App\Services;

use App\Models\ChecklistQuestion;
use App\Models\Notification;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAnswer;
use Illuminate\Support\Carbon;

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

        foreach ($deptIds as $deptId) {
            $ticket = Ticket::create([
                'reference' => Ticket::nextReference('CHK'),
                'title' => str($question->question_text)->limit(120),
                'description' => $answer->comment,
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
    public function createManual(array $attrs, ?int $actorId = null): Ticket
    {
        $attrs['reference'] = $attrs['reference'] ?? Ticket::nextReference();
        $attrs['status'] = $attrs['status'] ?? 'open';
        $ticket = Ticket::create($attrs);

        $this->log($ticket, $actorId, 'created', null, $ticket->status, 'تم إنشاء الطلب.');
        $this->notifyDepartmentManager($ticket);
        $this->autoAssign($ticket);

        return $ticket;
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

        return $ticket;
    }

    /** Technician declines an assigned task: back to the manager (rejected, unassigned). */
    public function declineByTechnician(Ticket $ticket, User $tech, string $note): Ticket
    {
        $from = $ticket->status;
        $ticket->update(['status' => 'rejected', 'assigned_to' => null]);
        $this->log($ticket, $tech->id, 'declined', $from, 'rejected', 'رفض الفني: '.$note);
        $this->notifyDepartmentManager($ticket);

        return $ticket;
    }

    public function changeStatus(Ticket $ticket, string $status, ?User $actor = null, ?string $note = null): Ticket
    {
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

        return $ticket;
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
