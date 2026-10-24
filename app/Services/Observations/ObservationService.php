<?php

namespace App\Services\Observations;

use App\Models\AiClassification;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationPhoto;
use App\Models\ObservationVerification;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Services\Observations\Ai\ClassificationBatch;
use App\Services\TicketService;
use App\Support\ObservationScope;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ObservationService
{
    /** Ticket statuses meaning someone is working on it: the observation shows `in_progress`. */
    private const TICKET_WORKING = ['assigned', 'on_the_way', 'in_progress'];

    /** Management roles that may record for any reporting department (others: their own one only). */
    public const ALL_DEPARTMENTS_ROLES = ['super_admin', 'ops_manager', 'area_manager', 'branch_director', 'vm_manager'];

    /** Statuses after which an observation can no longer be closed without action. */
    public const FINAL_STATUSES = ['closed_no_action', 'verified_closed', 'positive'];

    public function __construct(private ?RiskResolver $resolver = null, private ?TicketService $tickets = null)
    {
        $this->resolver ??= new RiskResolver;
        $this->tickets ??= app(TicketService::class);
    }

    /**
     * Start a draft visit. Keys: department_id?, branch_id?, type, visit_date, checkin_latitude?,
     * checkin_longitude?, simulated?, general_notes?, source.
     */
    public function createVisit(User $u, array $data): ObservationVisit
    {
        if (! in_array($data['type'] ?? null, ObservationVisit::TYPES, true)) {
            throw ValidationException::withMessages(['type' => dt('نوع الزيارة غير صحيح.', 'Invalid visit type.')]);
        }
        if (! in_array($data['source'] ?? null, ObservationVisit::SOURCES, true)) {
            throw ValidationException::withMessages(['source' => dt('مصدر الزيارة غير صحيح.', 'Invalid visit source.')]);
        }
        if (empty($data['visit_date'])) {
            throw ValidationException::withMessages(['visit_date' => dt('تاريخ الزيارة مطلوب.', 'The visit date is required.')]);
        }
        if ($data['type'] === 'field' && empty($data['branch_id'])) {
            throw ValidationException::withMessages(['branch_id' => dt('الفرع مطلوب للزيارة الميدانية.', 'A field visit requires a branch.')]);
        }

        if (! empty($data['department_id']) && ! $this->reportingDepartmentsFor($u)->contains('id', (int) $data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => dt('لا يمكنك التسجيل باسم هذه الإدارة.', 'You may not record observations for this department.')]);
        }

        $departmentId = ! empty($data['department_id']) ? (int) $data['department_id'] : $u->defaultReportingDepartmentId();
        if ($departmentId === null) {
            throw ValidationException::withMessages(['department_id' => dt('تعذّر تحديد الإدارة المُبلِّغة.', 'The reporting department could not be determined.')]);
        }

        // Must run outside any transaction: under REPEATABLE READ a retry inside one re-reads the same
        // snapshot and picks the same code again (see retryOnDuplicate).
        return $this->retryOnDuplicate(fn () => ObservationVisit::create([
            'type' => $data['type'],
            'department_id' => $departmentId,
            'branch_id' => $data['branch_id'] ?? null,
            'user_id' => $u->id,
            'visit_date' => $data['visit_date'],
            'started_at' => now(),
            'checkin_latitude' => $data['checkin_latitude'] ?? null,
            'checkin_longitude' => $data['checkin_longitude'] ?? null,
            'checkin_simulated' => (bool) ($data['simulated'] ?? false),
            'status' => 'draft',
            'general_notes' => $data['general_notes'] ?? null,
            'source' => $data['source'],
        ]));
    }

    /**
     * Reporting departments the user may record observations for: every `is_reporting` department for
     * management roles, else the user's own department when it is reporting, else none.
     *
     * @return Collection<int, Department>
     */
    public function reportingDepartmentsFor(User $u): Collection
    {
        if ($u->hasRole(self::ALL_DEPARTMENTS_ROLES)) {
            return Department::where('is_reporting', true)->orderBy('name')->get(['id', 'slug', 'name']);
        }

        return Department::where('is_reporting', true)->whereKey($u->department_id ?? 0)->get(['id', 'slug', 'name']);
    }

    /**
     * Persist one ai_classifications row per checked point (spec section 8): `request_hash` =
     * sha1(department|text), `suggested` = the matching result (null when the classifier gave none), the
     * batch status / model / error / latency on every row, and the batch token totals on the FIRST row
     * only (null on the others) so sums over the table stay correct.
     *
     * @param  array<int, array{0:int, 1:string}>  $points  [[index, text], ...]
     * @return list<AiClassification> the created rows, in point order
     */
    public function recordClassifications(ObservationVisit $v, array $points, ClassificationBatch $batch): array
    {
        $byIndex = [];
        foreach ($batch->results as $result) {
            $byIndex[$result->index] = $result;
        }

        return DB::transaction(function () use ($v, $points, $batch, $byIndex) {
            $rows = [];
            foreach (array_values($points) as $n => [$index, $text]) {
                $rows[] = AiClassification::create([
                    'observation_visit_id' => $v->id,
                    'request_hash' => sha1($v->department_id.'|'.$text),
                    'input_text' => $text,
                    'suggested' => isset($byIndex[$index]) ? $byIndex[$index]->toArray() : null,
                    'model' => $batch->model,
                    'status' => $batch->status,
                    'error' => $batch->error,
                    'latency_ms' => $batch->latencyMs,
                    'input_tokens' => $n === 0 ? $batch->inputTokens : null,
                    'output_tokens' => $n === 0 ? $batch->outputTokens : null,
                ]);
            }

            return $rows;
        });
    }

    /**
     * Create or update the observations of a draft visit (all or nothing).
     *
     * @param  array<int, array>  $items  one payload per observation; an `id` updates that row of this visit
     * @return Collection<int, Observation> saved rows, in input order
     */
    public function upsertObservations(ObservationVisit $v, array $items, User $u): Collection
    {
        $this->assertDraft($v);

        // The whole transaction is the unit retried on a duplicate reference: the retry starts a new
        // transaction with a fresh snapshot, so nextReference() sees the competing insert.
        return $this->retryOnDuplicate(fn () => DB::transaction(function () use ($v, $items, $u) {
            $saved = [];
            foreach (array_values($items) as $i => $item) {
                $saved[] = $this->saveItem($v, (array) $item, $i, $u);
            }

            return (new Collection($saved))->map(fn (Observation $o) => $o->fresh(['photos']));
        }));
    }

    public function deleteDraftObservation(Observation $o): void
    {
        $visit = $o->visit;
        if ($visit === null || $visit->status !== 'draft') {
            throw ValidationException::withMessages(['visit' => dt('لا يمكن حذف ملاحظة من زيارة تم إرسالها.', 'An observation of a submitted visit cannot be deleted.')]);
        }

        DB::transaction(function () use ($o) {
            ObservationPhoto::where('observation_id', $o->id)->delete();
            $o->delete();
        });
    }

    /**
     * Submit a draft visit (spec section 9.2 step 4): repeat escalation, one ticket per issue (and per
     * `poor` assessment, converted to an issue), before photos, notifications, counters. All or nothing.
     * A second call on a submitted visit creates nothing and returns the tickets already linked.
     *
     * Options: `silent` (bool) passes through to createManual and suppresses this method's notifications;
     * `due_from` (?Carbon, default now) is the start of each ticket's SLA.
     *
     * @return array{visit: ObservationVisit, created_tickets: list<array{observation_id:int, ticket_id:int, reference:string}>}
     */
    public function submitVisit(ObservationVisit $v, User $u, array $options = []): array
    {
        if ($v->status === 'submitted') {
            return ['visit' => $v, 'created_tickets' => $this->linkedTickets($v)];
        }
        $this->assertDraft($v);

        $silent = (bool) ($options['silent'] ?? false);
        $dueFrom = $options['due_from'] ?? null;

        // One transaction is the unit retried on a duplicate ticket reference (see retryOnDuplicate).
        $created = $this->retryOnDuplicate(fn () => DB::transaction(function () use ($v, $u, $silent, $dueFrom) {
            // The draft check above is in memory: the lock closes the race with a concurrent upsert/submit.
            $visit = ObservationVisit::whereKey($v->id)->lockForUpdate()->firstOrFail();
            if ($visit->status === 'submitted') {
                return null;
            }
            $this->assertDraft($visit);

            $observations = $visit->observations()->with(['catalogItem', 'category'])->orderBy('id')->get();
            $this->assertTicketable($observations);

            $created = [];
            foreach ($observations as $o) {
                if ($o->catalog_item_id !== null) {
                    ObservationCatalogItem::whereKey($o->catalog_item_id)->increment('usage_count');
                }

                if (! $this->needsTicket($o)) {
                    // Nothing follows a good/average assessment.
                    if ($o->kind === 'assessment') {
                        $o->update(['status' => 'closed_no_action']);
                    }

                    continue;
                }

                $created[] = $this->openTicket($o, $u, $silent, $dueFrom);
            }

            $visit->update(['status' => 'submitted', 'submitted_at' => now()]);

            return $created;
        }));

        $v->refresh();

        return ['visit' => $v, 'created_tickets' => $created ?? $this->linkedTickets($v)];
    }

    /**
     * Ticket attributes for an observation (reused when a verification reopens it).
     * Priority, SLA and due date follow the row's current risk level (including overrides and escalations).
     */
    public function ticketAttributesFor(Observation $o, User $u, ?Carbon $dueFrom = null): array
    {
        $catalog = $o->catalog_item_id ? ObservationCatalogItem::find($o->catalog_item_id) : null;
        $category = $o->category_id ? ObservationCategory::find($o->category_id) : null;
        $risk = $o->risk_level_id ? RiskLevel::find($o->risk_level_id) : null;
        $sla = $this->slaHours($catalog, $category, $risk);

        return [
            'source' => 'observation',
            'observation_id' => $o->id,
            'branch_id' => $o->branch_id,
            'department_id' => $o->responsible_department_id,
            'title' => $catalog ? mb_substr((string) $catalog->label_ar, 0, 255) : mb_substr((string) ($o->title ?: $o->description), 0, 120),
            'description' => $this->ticketDescription($o, $catalog),
            'priority' => $risk?->ticket_priority ?: 'medium',
            'sla_hours' => $sla,
            'due_at' => $sla ? ($dueFrom ?? now())->copy()->addHours($sla) : null,
            'category' => $catalog?->code ?? $category?->slug,
            'created_by' => $u->id,
        ];
    }

    /**
     * Move an observation along with its CURRENT ticket (spec section 7); called by SyncObservationFromTicket.
     * assigned / on_the_way / in_progress -> in_progress; closed (approved) -> waiting_verification, or
     * verified_closed with an automatic verification by the approver when the risk needs no field check.
     * Other ticket statuses, tickets outside the module and earlier (reopened) tickets change nothing.
     */
    public function syncFromTicket(Ticket $t, ?string $from, string $to): void
    {
        if ($t->observation_id === null) {
            return;
        }

        DB::transaction(function () use ($t, $to) {
            $observation = Observation::with('riskLevel')->whereKey($t->observation_id)->lockForUpdate()->first();
            if ($observation === null || (int) $observation->ticket_id !== (int) $t->id) {
                return;
            }

            if (in_array($to, self::TICKET_WORKING, true)) {
                if (in_array($observation->status, ['open', 'in_progress'], true)) {
                    $observation->update(['status' => 'in_progress']);
                }

                return;
            }

            if ($to !== 'closed' || ! in_array($observation->status, ['open', 'in_progress', 'reopened'], true)) {
                return;
            }

            $this->copyAfterEvidence($observation, $t);

            // A null risk (none) needs no field verification.
            if ($observation->riskLevel?->requires_field_verification) {
                $observation->update(['status' => 'waiting_verification']);

                return;
            }

            // The approver is the actor of the closing log row, written just before the event fired.
            $approverId = TicketUpdate::where('ticket_id', $t->id)->whereNotNull('user_id')->latest('id')->value('user_id')
                ?? $t->created_by ?? $observation->created_by;
            ObservationVerification::create([
                'observation_id' => $observation->id,
                'verified_by' => $approverId,
                'result' => 'resolved',
                'notes' => 'اعتماد بصورة بعد',
                'verified_at' => now(),
            ]);
            $observation->update(['status' => 'verified_closed']);
        });
    }

    /**
     * Field verification of a `waiting_verification` observation (spec section 7). `resolved` closes it,
     * `partially` keeps it waiting with the note, `not_resolved` (note required) reopens it with a new ticket.
     * The caller authorizes with the `verify` policy; the check is repeated here so the service is safe alone.
     */
    public function verify(Observation $o, User $u, string $result, ?string $notes = null, ?string $photoPath = null, ?int $visitId = null): ObservationVerification
    {
        if (! $u->can('verify', $o)) {
            throw new AuthorizationException(dt('غير مسموح لك بالتحقق من هذه الملاحظة.', 'You may not verify this observation.'));
        }
        if (! in_array($result, ObservationVerification::RESULTS, true)) {
            throw ValidationException::withMessages(['result' => dt('نتيجة التحقق غير صحيحة.', 'Invalid verification result.')]);
        }
        $notes = trim((string) $notes) === '' ? null : trim((string) $notes);
        if ($result === 'not_resolved' && $notes === null) {
            throw ValidationException::withMessages(['notes' => dt('اكتب سبب عدم الحل.', 'A note is required when the issue is not resolved.')]);
        }
        $this->assertWaitingVerification($o);

        // One transaction is the unit retried on a duplicate ticket reference (see retryOnDuplicate).
        $verification = $this->retryOnDuplicate(fn () => DB::transaction(function () use ($o, $u, $result, $notes, $photoPath, $visitId) {
            // The status check above is in memory: the lock closes the race with a concurrent verification.
            $locked = Observation::whereKey($o->id)->lockForUpdate()->firstOrFail();
            $this->assertWaitingVerification($locked);

            $verification = ObservationVerification::create([
                'observation_id' => $locked->id,
                'verified_by' => $u->id,
                'observation_visit_id' => $visitId,
                'result' => $result,
                'notes' => $notes,
                'verified_at' => now(),
            ]);

            if ($photoPath !== null && $photoPath !== '') {
                ObservationPhoto::create([
                    'observation_id' => $locked->id, 'path' => $photoPath, 'kind' => 'after',
                    'source' => 'camera', 'uploaded_by' => $u->id,
                ]);
            }

            if ($result === 'resolved') {
                $locked->update(['status' => 'verified_closed']);
            } elseif ($result === 'not_resolved') {
                $this->reopen($locked, $u);
            }

            return $verification;
        }));

        $o->refresh();

        return $verification;
    }

    /** Observations of the branch waiting for field verification that the user may see. */
    public function pendingVerification(Branch $b, User $u): Collection
    {
        return ObservationScope::apply(Observation::query(), $u)
            ->where('observations.branch_id', $b->id)
            ->where('observations.status', 'waiting_verification')
            ->with(['riskLevel', 'category', 'catalogItem', 'responsibleDepartment', 'reportingDepartment', 'ticket', 'photos'])
            ->orderBy('observations.id')
            ->get();
    }

    /**
     * Change the responsible department. A ticket that is not closed moves to the new department's board,
     * unassigned (a working ticket goes back to `open`), and that department's manager is notified.
     */
    public function reroute(Observation $o, int $responsibleDepartmentId, User $u, ?string $reason = null): Observation
    {
        $department = Department::find($responsibleDepartmentId);
        if ($department === null) {
            throw ValidationException::withMessages(['responsible_department_id' => dt('الإدارة غير موجودة.', 'The department does not exist.')]);
        }

        DB::transaction(function () use ($o, $department, $u, $reason) {
            $o->update(['responsible_department_id' => $department->id]);

            $ticket = $o->ticket_id ? Ticket::whereKey($o->ticket_id)->lockForUpdate()->first() : null;
            if ($ticket === null || $ticket->status === 'closed') {
                return;
            }

            $from = $ticket->status;
            $to = in_array($from, self::TICKET_WORKING, true) ? 'open' : $from;
            $ticket->update(['department_id' => $department->id, 'assigned_to' => null, 'assigned_at' => null, 'status' => $to]);
            $this->tickets->log($ticket, $u->id, 'status_change', $from, $to, trim('إعادة توجيه: '.(string) $reason));

            if ($to === 'open' && $o->status === 'in_progress') {
                $o->update(['status' => 'open']);
            }

            $managerId = User::where('department_id', $department->id)->where('is_department_manager', true)->orderBy('id')->value('id');
            if ($managerId !== null) {
                $this->tickets->notify((int) $managerId, 'ticket_new', 'New ticket in your board', $ticket->reference.' — '.$ticket->title, $ticket->id);
            }
        });

        return $o->fresh();
    }

    /**
     * Raise the risk (only to a more severe level, lower sort_order). A ticket that is not closed takes the
     * new priority and SLA, its due date counted from the ticket's creation.
     */
    public function raiseRisk(Observation $o, RiskLevel $to, User $u, string $reason): Observation
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['risk_override_reason' => dt('سبب رفع درجة الخطورة مطلوب.', 'A reason is required to raise the risk level.')]);
        }
        $current = $o->risk_level_id ? RiskLevel::find($o->risk_level_id) : null;
        if ($current !== null && (int) $to->sort_order >= (int) $current->sort_order) {
            throw ValidationException::withMessages(['risk_level_id' => dt('الدرجة الجديدة لازم تكون أعلى من الحالية.', 'The new risk level must be more severe than the current one.')]);
        }

        DB::transaction(function () use ($o, $to, $u, $reason) {
            $o->update(['risk_level_id' => $to->id, 'risk_override_reason' => $reason]);

            $ticket = $o->ticket_id ? Ticket::whereKey($o->ticket_id)->lockForUpdate()->first() : null;
            if ($ticket === null || $ticket->status === 'closed') {
                return;
            }

            $catalog = $o->catalog_item_id ? ObservationCatalogItem::find($o->catalog_item_id) : null;
            $category = $o->category_id ? ObservationCategory::find($o->category_id) : null;
            $sla = $this->slaHours($catalog, $category, $to);
            $oldPriority = $ticket->priority;
            $newPriority = $to->ticket_priority ?: 'medium';

            $ticket->update([
                'priority' => $newPriority,
                'sla_hours' => $sla,
                'due_at' => $sla ? $ticket->created_at->copy()->addHours($sla) : null,
            ]);
            $this->tickets->log($ticket, $u->id, 'priority', $oldPriority, $newPriority, 'رفع درجة الريسك: '.$reason);
        });

        return $o->fresh();
    }

    /**
     * Close without action (duplicate, recording error). A ticket that is not closed is cancelled
     * (TicketService::cancel: no after photo needed, no TicketStatusChanged), then the observation is
     * marked `closed_no_action` with the reason. Does NOT self-authorize: callers check `can('closeNoAction')`.
     */
    public function closeNoAction(Observation $o, User $u, string $reason): Observation
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => dt('سبب الإغلاق مطلوب.', 'A closing reason is required.')]);
        }

        DB::transaction(function () use ($o, $u, $reason) {
            $locked = Observation::whereKey($o->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, self::FINAL_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => dt('الملاحظة مغلقة بالفعل.', 'The observation is already closed.')]);
            }

            $ticket = $locked->ticket_id ? Ticket::whereKey($locked->ticket_id)->lockForUpdate()->first() : null;
            if ($ticket !== null && $ticket->status !== 'closed') {
                $this->tickets->cancel($ticket, $u, 'أُغلقت بدون إجراء: '.$reason);
            }

            $locked->update([
                'status' => 'closed_no_action',
                'close_reason' => $reason,
                'closed_by' => $u->id,
                'closed_at' => now(),
            ]);
        });

        return $o->fresh();
    }

    /** Same chain as RiskResolver (spec section 7), ending at the row's own risk instead of the policy default. */
    private function slaHours(?ObservationCatalogItem $catalog, ?ObservationCategory $category, ?RiskLevel $risk): ?int
    {
        $sla = $catalog?->sla_hours ?? $category?->default_sla_hours ?? $risk?->default_sla_hours;

        return $sla === null ? null : (int) $sla;
    }

    /** Copy the ticket's after photos to the observation, skipping paths it already has. */
    private function copyAfterEvidence(Observation $o, Ticket $t): void
    {
        $present = $o->photos()->pluck('path')->all();
        $rows = TicketUpdate::where('ticket_id', $t->id)
            ->where('evidence_kind', 'after')
            ->whereNotNull('evidence_path')
            ->orderBy('id')
            ->get(['evidence_path', 'user_id']);

        foreach ($rows as $row) {
            if (in_array($row->evidence_path, $present, true)) {
                continue;
            }
            $present[] = $row->evidence_path;
            ObservationPhoto::create([
                'observation_id' => $o->id,
                'path' => $row->evidence_path,
                'kind' => 'after',
                'source' => 'gallery',
                'uploaded_by' => $row->user_id ?? $t->created_by ?? $o->created_by,
            ]);
        }
    }

    /**
     * `not_resolved`: reopen with a new ticket (the previous one stays closed), one more repeat and the
     * escalation check. Original dates are never touched. Runs inside verify()'s transaction.
     */
    private function reopen(Observation $o, User $u): void
    {
        $previous = $o->ticket_id ? Ticket::find($o->ticket_id) : null;

        $o->status = 'reopened';
        $this->applyRepeatEscalation($o, (int) $o->repeat_count + 1);
        $o->save();

        $attributes = $this->ticketAttributesFor($o, $u);
        $attributes['title'] = mb_substr('إعادة فتح: '.$attributes['title'], 0, 255);
        $attributes['reopen_count'] = (int) ($previous?->reopen_count ?? 0) + 1;
        // Not silent: the department manager gets ticket_new and the ticket may be auto-assigned. The
        // assignment event is a no-op here because the observation still points to the previous ticket.
        $ticket = $this->tickets->createManual($attributes, $u->id);
        $o->update(['ticket_id' => $ticket->id]);

        foreach ($o->photos()->where('kind', 'before')->orderBy('id')->pluck('path') as $path) {
            $this->tickets->addEvidence($ticket, $path, 'before', $u);
        }

        if ($previous?->assigned_to) {
            $this->tickets->notify((int) $previous->assigned_to, 'observation_reopened',
                dt('إعادة فتح ملاحظة لم تُحل', 'Observation reopened: not resolved'),
                $ticket->reference.' — '.$ticket->title, $ticket->id);
        }
    }

    private function assertWaitingVerification(Observation $o): void
    {
        if ($o->status !== 'waiting_verification') {
            throw ValidationException::withMessages(['status' => dt('الملاحظة ليست بانتظار التحقق.', 'The observation is not waiting for verification.')]);
        }
    }

    private function needsTicket(Observation $o): bool
    {
        // A row already closed without action (an imported "Done" finding) is not open work. Live drafts
        // never carry this status on an issue, so nothing changes for them.
        if ($o->status === 'closed_no_action') {
            return false;
        }

        return $o->kind === 'issue' || ($o->kind === 'assessment' && $o->rating === 'poor');
    }

    /**
     * Every ticket-bound row needs a responsible department and a branch. One 422 lists every failing
     * item; `i` is the row's position in the visit's observations ordered by id.
     */
    private function assertTicketable(Collection $observations): void
    {
        $errors = [];
        foreach ($observations->values() as $i => $o) {
            if (! $this->needsTicket($o)) {
                continue;
            }
            if ($o->responsible_department_id === null) {
                $errors["items.{$i}.responsible_department_id"] = dt('الإدارة المسؤولة مطلوبة لفتح طلب.', 'A responsible department is required to open a ticket.');
            }
            if ($o->branch_id === null) {
                $errors["items.{$i}.branch_id"] = dt('الفرع مطلوب لفتح طلب.', 'A branch is required to open a ticket.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array{observation_id:int, ticket_id:int, reference:string} */
    private function openTicket(Observation $o, User $u, bool $silent, ?Carbon $dueFrom): array
    {
        if ($o->kind === 'assessment') {
            // A poor assessment becomes an issue with its category's risk (spec section 7).
            $o->kind = 'issue';
            $o->status = 'open';
            if ($o->risk_level_id === null) {
                $o->risk_level_id = $this->resolver->resolve(['extra_values' => (array) ($o->extra_values ?? [])], $o->catalogItem, $o->category)['risk_level_id'];
            }
        }

        $escalated = $this->applyRepeatEscalation($o);
        $o->save();

        $ticket = $this->tickets->createManual($this->ticketAttributesFor($o, $u, $dueFrom), $u->id, ['silent' => $silent]);
        // createManual may have auto-assigned a fresh copy of the ticket; its assignment event ran before
        // ticket_id was linked, so the status is derived here from the saved ticket.
        $ticket->refresh();
        $o->update([
            'ticket_id' => $ticket->id,
            'status' => in_array($ticket->status, self::TICKET_WORKING, true) ? 'in_progress' : 'open',
        ]);

        foreach ($o->photos()->where('kind', 'before')->orderBy('id')->pluck('path') as $path) {
            $this->tickets->addEvidence($ticket, $path, 'before', $u);
        }

        if (! $silent) {
            $this->notifyObservation($o, $ticket, $escalated);
        }

        return ['observation_id' => $o->id, 'ticket_id' => $ticket->id, 'reference' => $ticket->reference];
    }

    /**
     * Same catalog item (or same category for a free row) in the same branch within 30 days: the third
     * occurrence raises the risk one level (spec section 7). A reopen passes its own `$repeatCount`
     * (previous count + 1) instead of the query. The risk is raised at most once per observation.
     * Sets the attributes; the caller saves.
     */
    private function applyRepeatEscalation(Observation $o, ?int $repeatCount = null): bool
    {
        if ($repeatCount !== null) {
            $o->repeat_count = $repeatCount;
        } else {
            if ($o->catalog_item_id === null && $o->category_id === null) {
                return false;
            }

            $query = Observation::where('branch_id', $o->branch_id)
                ->whereKeyNot($o->id)
                ->where('created_at', '>=', now()->subDays(30));
            // Demo rows (DemoObservationsSeeder) never count as repeats of a real observation; demo-on-demo does.
            if ($o->import_source !== 'demo') {
                $query->where(fn ($q) => $q->whereNull('import_source')->orWhere('import_source', '!=', 'demo'));
            }
            $o->catalog_item_id !== null
                ? $query->where('catalog_item_id', $o->catalog_item_id)
                : $query->whereNull('catalog_item_id')->where('category_id', $o->category_id);

            $o->repeat_count = $query->count();
        }

        if ($o->repeat_count < 2 || $o->risk_level_id === null || $o->escalated_from_risk_level_id !== null) {
            return false;
        }

        $above = RiskLevel::find($o->risk_level_id)?->oneAbove();
        if ($above === null) {
            return false;
        }

        $o->escalated_from_risk_level_id = $o->risk_level_id;
        $o->risk_level_id = $above->id;

        return true;
    }

    /**
     * Branch manager, area manager (escalated, or high/critical) and cc departments' managers; one row per
     * recipient. The responsible department's manager is already notified by createManual.
     */
    private function notifyObservation(Observation $o, Ticket $ticket, bool $escalated): void
    {
        $branch = Branch::find($o->branch_id);
        // $branch->area is the legacy text column: query the relation.
        $areaManagerId = $branch?->area()->value('manager_id');
        $body = $ticket->reference.' — '.$ticket->title;
        $sent = [];
        $send = function ($userId, string $type, string $title) use (&$sent, $ticket, $body) {
            if ($userId === null || in_array((int) $userId, $sent, true)) {
                return;
            }
            $sent[] = (int) $userId;
            $this->tickets->notify((int) $userId, $type, $title, $body, $ticket->id);
        };

        if ($escalated) {
            $send($areaManagerId, 'observation_escalated', dt('تصعيد ملاحظة متكررة في منطقتك', 'Repeated observation escalated in your area'));
        }
        $send($branch?->manager_id, 'observation_new', dt('ملاحظة جديدة على الفرع', 'New observation at the branch'));
        if (in_array(RiskLevel::whereKey($o->risk_level_id)->value('slug'), ['high', 'critical'], true)) {
            $send($areaManagerId, 'observation_new', dt('ملاحظة عالية الخطورة في منطقتك', 'High-risk observation in your area'));
        }

        $ccIds = array_diff(array_map('intval', (array) ($o->cc_department_ids ?? [])), [(int) $o->responsible_department_id]);
        if ($ccIds !== []) {
            $managers = User::whereIn('department_id', $ccIds)->where('is_department_manager', true)->orderBy('id')->pluck('id');
            foreach ($managers as $managerId) {
                $send($managerId, 'observation_cc', dt('نسخة من ملاحظة لإدارتك', 'Observation copied to your department'));
            }
        }
    }

    private function ticketDescription(Observation $o, ?ObservationCatalogItem $catalog): string
    {
        $description = (string) $o->description;
        $values = (array) ($o->extra_values ?? []);
        if ($values === []) {
            return $description;
        }

        $labels = [];
        foreach ((array) ($catalog?->extra_fields ?? []) as $field) {
            if (is_array($field) && isset($field['key'])) {
                $labels[$field['key']] = $field['label_ar'] ?? $field['key'];
            }
        }

        $lines = [];
        foreach ($values as $key => $value) {
            $text = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $lines[] = ($labels[$key] ?? $key).': '.$text;
        }

        return $description."\n\n".implode("\n", $lines);
    }

    /** @return list<array{observation_id:int, ticket_id:int, reference:string}> the first ticket of each observation */
    private function linkedTickets(ObservationVisit $v): array
    {
        return Ticket::whereIn('observation_id', $v->observations()->select('id'))
            ->orderBy('id')
            ->get(['id', 'observation_id', 'reference'])
            ->unique('observation_id')
            ->map(fn (Ticket $t) => ['observation_id' => (int) $t->observation_id, 'ticket_id' => (int) $t->id, 'reference' => $t->reference])
            ->values()
            ->all();
    }

    private function saveItem(ObservationVisit $v, array $item, int $index, User $u): Observation
    {
        $key = "items.{$index}";

        $existing = null;
        if (! empty($item['id'])) {
            $existing = Observation::where('id', $item['id'])->where('observation_visit_id', $v->id)->first();
            if ($existing === null) {
                throw ValidationException::withMessages(["{$key}.id" => dt('الملاحظة لا تخص هذه الزيارة.', 'The observation does not belong to this visit.')]);
            }
        }

        $catalog = null;
        if (! empty($item['catalog_item_id'])) {
            $catalog = ObservationCatalogItem::with('category')->find($item['catalog_item_id']);
            if ($catalog === null) {
                throw ValidationException::withMessages(["{$key}.catalog_item_id" => dt('بند الكتالوج غير موجود.', 'The catalog item does not exist.')]);
            }
        }
        $categoryId = ! empty($item['category_id']) ? (int) $item['category_id'] : $catalog?->category_id;
        $category = $catalog?->category_id === $categoryId ? $catalog?->category : ($categoryId ? ObservationCategory::find($categoryId) : null);

        $resolved = $this->resolver->resolve($item, $catalog, $category);

        if (! in_array($resolved['kind'], Observation::KINDS, true)) {
            throw ValidationException::withMessages(["{$key}.kind" => dt('نوع الملاحظة غير صحيح.', 'Invalid observation kind.')]);
        }

        $description = trim((string) ($item['description'] ?? ''));
        if ($description === '' && $catalog !== null) {
            $description = (string) $catalog->label_ar;
        }
        if ($description === '') {
            throw ValidationException::withMessages(["{$key}.description" => dt('وصف الملاحظة مطلوب.', 'The description is required.')]);
        }

        [$riskLevelId, $overrideReason] = $this->decideRisk($item, $resolved['risk_level_id'], $key);

        $attributes = [
            'observation_visit_id' => $v->id,
            'branch_id' => $v->branch_id,
            'reporting_department_id' => $v->department_id,
            'responsible_department_id' => $resolved['responsible_department_id'],
            'cc_department_ids' => $resolved['cc_department_ids'] ?: null,
            'category_id' => $categoryId,
            'catalog_item_id' => $catalog?->id,
            'kind' => $resolved['kind'],
            'risk_level_id' => $riskLevelId,
            'risk_override_reason' => $overrideReason,
            'title' => $this->title($item, $catalog, $description),
            'description' => $description,
            'product_or_area' => $item['product_or_area'] ?? null,
            'extra_values' => $item['extra_values'] ?? null,
            'rating' => $item['rating'] ?? null,
            'event_date' => $v->visit_date,
            'classification_status' => $catalog ? 'catalog' : (! empty($item['ai_classification_id']) ? 'ai_pending_review' : 'manual'),
            'status' => match ($resolved['kind']) {
                'positive' => 'positive',
                'activity' => 'closed_no_action',
                default => 'open',
            },
        ];

        if ($existing) {
            $existing->update($attributes);
            $observation = $existing;
        } else {
            $attributes['created_by'] = $u->id;
            $observation = Observation::create($attributes);
        }

        $this->addPhotos($observation, (array) ($item['photos'] ?? []), $item['photo_source'] ?? 'gallery', $u);

        if (! empty($item['ai_classification_id'])) {
            // Link the suggestion and record what the employee kept, read from the saved row.
            AiClassification::where('id', $item['ai_classification_id'])
                ->where(fn ($q) => $q->where('observation_visit_id', $v->id)->orWhereNull('observation_visit_id'))
                ->first()
                ?->update([
                    'observation_id' => $observation->id,
                    'employee_final' => [
                        'catalog_item_id' => $observation->catalog_item_id,
                        'category_id' => $observation->category_id,
                        'kind' => $observation->kind,
                        'risk_level' => $observation->risk_level_id ? RiskLevel::whereKey($observation->risk_level_id)->value('slug') : null,
                        'responsible_department_id' => $observation->responsible_department_id,
                    ],
                ]);
        }

        return $observation;
    }

    /**
     * The employee's risk wins over the policy default only with a reason.
     *
     * @return array{0:?int, 1:?string} [risk_level_id, risk_override_reason]
     */
    private function decideRisk(array $item, ?int $resolvedId, string $key): array
    {
        $slug = $item['risk_level_slug'] ?? null;
        if (! $slug) {
            return [$resolvedId, null];
        }

        $chosen = RiskLevel::bySlug((string) $slug);
        if ($chosen === null) {
            throw ValidationException::withMessages(["{$key}.risk_level_slug" => dt('درجة الخطورة غير صحيحة.', 'Invalid risk level.')]);
        }
        if ($resolvedId === null || $chosen->id === $resolvedId) {
            return [$chosen->id, null];
        }

        $reason = trim((string) ($item['risk_override_reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(["{$key}.risk_override_reason" => dt('سبب تغيير درجة الخطورة مطلوب.', 'A reason is required to change the risk level.')]);
        }

        return [$chosen->id, $reason];
    }

    private function title(array $item, ?ObservationCatalogItem $catalog, string $description): string
    {
        $given = trim((string) ($item['title'] ?? ''));
        if ($given !== '') {
            return mb_substr($given, 0, 255);
        }

        return $catalog ? mb_substr((string) $catalog->label_ar, 0, 255) : mb_substr($description, 0, 120);
    }

    private function addPhotos(Observation $observation, array $paths, string $source, User $u): void
    {
        $source = in_array($source, ObservationPhoto::SOURCES, true) ? $source : 'gallery';
        $present = $observation->photos()->pluck('path')->all();

        foreach (array_unique(array_filter($paths, 'is_string')) as $path) {
            if (in_array($path, $present, true)) {
                continue;
            }
            ObservationPhoto::create([
                'observation_id' => $observation->id,
                'path' => $path,
                'kind' => 'before',
                'source' => $source,
                'uploaded_by' => $u->id,
            ]);
        }
    }

    private function assertDraft(ObservationVisit $v): void
    {
        if ($v->status !== 'draft') {
            throw ValidationException::withMessages(['visit' => dt('الزيارة تم إرسالها ولا يمكن تعديلها.', 'The visit was submitted and can no longer be edited.')]);
        }
    }

    /**
     * Code / reference are max+1 without a lock, so a concurrent insert can take the number first
     * (duplicate-key 1062). Retry the whole unit once. The unit must start its own transaction (or use
     * none) and this method must not be called inside one: under InnoDB REPEATABLE READ a retry within
     * the same transaction re-reads the same snapshot and collides again.
     */
    private function retryOnDuplicate(callable $unit): mixed
    {
        try {
            return $unit();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }

            return $unit();
        }
    }
}
