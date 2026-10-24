<?php

namespace App\Services\Observations;

use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Classification review queue (spec section 9.6): approve / update / merge into a catalog item / promote
 * to a new item. Every decision writes `admin_final` on the latest ai_classifications row and leaves
 * `ai_pending_review`. Ticket side effects go through ObservationService (`reroute`, `raiseRisk`);
 * lowering the risk, which ObservationService does not offer, is done here.
 *
 * Callers authorize first (`can('review', [ObservationCategory::class, $observation])`); nothing here does.
 */
class ClassificationReviewService
{
    public const NOTE = 'إعادة توجيه من مراجعة التصنيف';

    public function __construct(private ObservationService $observations, private TicketService $tickets) {}

    /** Keep the employee's confirmation as the admin decision. */
    public function approve(Observation $o, User $u): Observation
    {
        return DB::transaction(function () use ($o, $u) {
            $this->finish($o, $u, 'ai_reviewed');

            return $o->fresh();
        });
    }

    /**
     * Correct the classification. Keys (all optional): category_id, risk_level_id, responsible_department_id,
     * cc_department_ids (array), note. An open ticket follows the department and the priority.
     */
    public function update(Observation $o, User $u, array $data): Observation
    {
        $category = $this->categoryFor($o, $data['category_id'] ?? null);
        $risk = $this->riskFor($data['risk_level_id'] ?? null);
        $note = trim((string) ($data['note'] ?? ''));

        return DB::transaction(function () use ($o, $u, $data, $category, $risk, $note) {
            if ($category !== null) {
                $o->update(['category_id' => $category->id]);
            }
            if (array_key_exists('cc_department_ids', $data)) {
                $cc = array_values(array_unique(array_map('intval', (array) ($data['cc_department_ids'] ?? []))));
                $o->update(['cc_department_ids' => $cc === [] ? null : $cc]);
            }
            $this->applyResponsible($o, $u, $data['responsible_department_id'] ?? null, $note);
            $this->applyRisk($o, $u, $risk, $note);
            $this->finish($o->refresh(), $u, 'ai_reviewed');

            return $o->fresh();
        });
    }

    /**
     * Link the observation to a catalog item: category/risk/responsible/cc inherited from the item (then its
     * category) unless overridden; `classification_status = catalog`; `usage_count` +1 once per observation.
     */
    public function merge(Observation $o, ObservationCatalogItem $item, User $u, array $overrides = []): Observation
    {
        $item->loadMissing('category');
        if ((int) $item->category->department_id !== (int) $o->reporting_department_id) {
            throw ValidationException::withMessages(['catalog_item_id' => dt('البند من كتالوج إدارة أخرى.', 'The item belongs to another department\'s catalog.')]);
        }
        $riskId = $overrides['risk_level_id'] ?? $item->risk_level_id ?? $item->category->default_risk_level_id ?? $o->risk_level_id;
        $risk = $this->riskFor($riskId);
        $responsible = $overrides['responsible_department_id'] ?? $item->responsible_department_id ?? $item->category->default_responsible_department_id;
        $cc = $item->cc_department_ids ?: $item->category->default_cc_department_ids ?: $o->cc_department_ids;
        $note = trim((string) ($overrides['note'] ?? ''));

        return DB::transaction(function () use ($o, $item, $u, $risk, $responsible, $cc, $note) {
            $alreadyLinked = (int) $o->catalog_item_id === (int) $item->id;
            $o->update([
                'catalog_item_id' => $item->id,
                'category_id' => $item->category_id,
                'cc_department_ids' => $cc ?: null,
            ]);
            if (! $alreadyLinked) {
                ObservationCatalogItem::whereKey($item->id)->increment('usage_count');
            }
            $this->applyResponsible($o, $u, $responsible, $note !== '' ? $note : 'دمج في بند '.$item->code);
            $this->applyRisk($o, $u, $risk, $note !== '' ? $note : 'دمج في بند '.$item->code);
            $this->finish($o->refresh(), $u, 'catalog');

            return $o->fresh();
        });
    }

    /** Create a catalog item from the review form, then merge the observation into it. */
    public function promote(Observation $o, User $u, array $itemAttributes): ObservationCatalogItem
    {
        $category = ObservationCategory::findOrFail($itemAttributes['category_id']);
        if ((int) $category->department_id !== (int) $o->reporting_department_id) {
            throw ValidationException::withMessages(['category_id' => dt('الفئة من إدارة أخرى.', 'The category belongs to another department.')]);
        }

        return DB::transaction(function () use ($o, $u, $itemAttributes, $category) {
            $item = ObservationCatalogItem::create(array_merge($itemAttributes, [
                'usage_count' => 0,
                'sort_order' => $itemAttributes['sort_order'] ?? ((int) ObservationCatalogItem::where('category_id', $category->id)->max('sort_order') + 1),
            ]));
            $this->merge($o, $item, $u);

            return $item->fresh();
        });
    }

    /** Snapshot of the observation's classification (same keys as `employee_final`). */
    public static function snapshot(Observation $o): array
    {
        return [
            'catalog_item_id' => $o->catalog_item_id,
            'category_id' => $o->category_id,
            'kind' => $o->kind,
            'risk_level' => $o->risk_level_id ? RiskLevel::whereKey($o->risk_level_id)->value('slug') : null,
            'responsible_department_id' => $o->responsible_department_id,
        ];
    }

    private function finish(Observation $o, User $u, string $classificationStatus): void
    {
        $o->update([
            'classification_status' => $classificationStatus,
            'classification_reviewed_by' => $u->id,
            'classification_reviewed_at' => now(),
        ]);
        $o->classification()->first()?->update(['admin_final' => self::snapshot($o)]);
    }

    private function applyResponsible(Observation $o, User $u, mixed $departmentId, string $note): void
    {
        if ($departmentId === null || (int) $departmentId === (int) $o->responsible_department_id) {
            return;
        }
        $this->observations->reroute($o, (int) $departmentId, $u, trim(self::NOTE.' '.$note));
        $o->refresh();
    }

    private function applyRisk(Observation $o, User $u, ?RiskLevel $to, string $note): void
    {
        if ($to === null || (int) $to->id === (int) $o->risk_level_id) {
            return;
        }
        $current = $o->risk_level_id ? RiskLevel::find($o->risk_level_id) : null;
        $reason = trim(self::NOTE.' '.$note);

        if ($current === null || (int) $to->sort_order < (int) $current->sort_order) {
            $this->observations->raiseRisk($o, $to, $u, $reason);
            $o->refresh();

            return;
        }

        // Lowering: ObservationService only raises, so mirror raiseRisk's ticket handling here.
        $o->update(['risk_level_id' => $to->id, 'risk_override_reason' => $reason]);
        $ticket = $o->ticket_id ? Ticket::whereKey($o->ticket_id)->lockForUpdate()->first() : null;
        if ($ticket === null || $ticket->status === 'closed') {
            return;
        }
        $catalog = $o->catalog_item_id ? ObservationCatalogItem::find($o->catalog_item_id) : null;
        $category = $o->category_id ? ObservationCategory::find($o->category_id) : null;
        $sla = $catalog?->sla_hours ?? $category?->default_sla_hours ?? $to->default_sla_hours;
        $sla = $sla === null ? null : (int) $sla;
        $oldPriority = $ticket->priority;
        $newPriority = $to->ticket_priority ?: 'medium';
        $ticket->update([
            'priority' => $newPriority,
            'sla_hours' => $sla,
            'due_at' => $sla ? $ticket->created_at->copy()->addHours($sla) : null,
        ]);
        $this->tickets->log($ticket, $u->id, 'priority', $oldPriority, $newPriority, 'تخفيض درجة الريسك: '.$reason);
    }

    private function categoryFor(Observation $o, mixed $categoryId): ?ObservationCategory
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }
        $category = ObservationCategory::find($categoryId);
        if ($category === null || (int) $category->department_id !== (int) $o->reporting_department_id) {
            throw ValidationException::withMessages(['category_id' => dt('الفئة من إدارة أخرى.', 'The category belongs to another department.')]);
        }

        return $category;
    }

    private function riskFor(mixed $riskId): ?RiskLevel
    {
        if ($riskId === null || $riskId === '') {
            return null;
        }
        $risk = RiskLevel::find($riskId);
        if ($risk === null) {
            throw ValidationException::withMessages(['risk_level_id' => dt('درجة الخطورة غير موجودة.', 'Unknown risk level.')]);
        }

        return $risk;
    }
}
