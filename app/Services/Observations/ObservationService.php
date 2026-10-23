<?php

namespace App\Services\Observations;

use App\Models\AiClassification;
use App\Models\Branch;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationPhoto;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ObservationService
{
    public function __construct(private ?RiskResolver $resolver = null, private ?TicketService $tickets = null)
    {
        $this->resolver ??= new RiskResolver;
        $this->tickets ??= app(TicketService::class);
    }

    /**
     * Start a draft visit. Keys: department_id?, branch_id?, type, visit_date, checkin_latitude?,
     * checkin_longitude?, simulated?, source.
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
            'source' => $data['source'],
        ]));
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

        // Same chain as RiskResolver (spec section 7), ending at the row's own risk instead of the policy default.
        $sla = $catalog?->sla_hours ?? $category?->default_sla_hours ?? $risk?->default_sla_hours;
        $sla = $sla === null ? null : (int) $sla;

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

    private function needsTicket(Observation $o): bool
    {
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
        $o->update(['ticket_id' => $ticket->id, 'status' => 'open']);

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
     * occurrence raises the risk one level (spec section 7). Sets the attributes; the caller saves.
     */
    private function applyRepeatEscalation(Observation $o): bool
    {
        if ($o->catalog_item_id === null && $o->category_id === null) {
            return false;
        }

        $query = Observation::where('branch_id', $o->branch_id)
            ->whereKeyNot($o->id)
            ->where('created_at', '>=', now()->subDays(30));
        $o->catalog_item_id !== null
            ? $query->where('catalog_item_id', $o->catalog_item_id)
            : $query->whereNull('catalog_item_id')->where('category_id', $o->category_id);

        $o->repeat_count = $query->count();
        if ($o->repeat_count < 2 || $o->risk_level_id === null) {
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
            AiClassification::where('id', $item['ai_classification_id'])
                ->where(fn ($q) => $q->where('observation_visit_id', $v->id)->orWhereNull('observation_visit_id'))
                ->update(['observation_id' => $observation->id]);
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
