<?php

namespace App\Services\Observations;

use App\Models\AiClassification;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationPhoto;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ObservationService
{
    public function __construct(private ?RiskResolver $resolver = null)
    {
        $this->resolver ??= new RiskResolver;
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

        return $this->createWithRetry(fn () => ObservationVisit::create([
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

        return DB::transaction(function () use ($v, $items, $u) {
            $saved = [];
            foreach (array_values($items) as $i => $item) {
                $saved[] = $this->saveItem($v, (array) $item, $i, $u);
            }

            return (new Collection($saved))->map(fn (Observation $o) => $o->fresh(['photos']));
        });
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
            $observation = $this->createWithRetry(fn () => Observation::create($attributes));
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

    /** Code / reference are max+1 without a lock: retry once if a concurrent insert took the number. */
    private function createWithRetry(callable $create): mixed
    {
        try {
            return $create();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }

            return $create();
        }
    }
}
