<?php

namespace Tests\Feature\Observations;

use App\Models\AiClassification;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationPhoto;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\User;
use App\Services\Observations\ObservationService;
use App\Services\Observations\RiskResolver;
use Illuminate\Validation\ValidationException;
use Tests\Support\Seeds;
use Tests\TestCase;

class ObservationServiceDraftTest extends TestCase
{
    private Department $cctv;

    private Department $operation;

    private Branch $branch;

    private User $user;

    /** @var array<string, RiskLevel> */
    private array $risks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation', ['is_reporting' => true]);
        $this->branch = Seeds::branch('Saraya Mall');
        $this->user = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);

        foreach ([['critical', 24], ['high', 48], ['medium', 72], ['low', 168], ['none', null]] as $i => [$slug, $sla]) {
            $this->risks[$slug] = RiskLevel::create([
                'slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug, 'color' => '#000000',
                'sort_order' => $i + 1, 'default_sla_hours' => $sla,
                'requires_field_verification' => in_array($slug, ['critical', 'high'], true),
                'ticket_priority' => 'medium', 'active' => true,
            ]);
        }
    }

    private function category(array $attrs = []): ObservationCategory
    {
        return ObservationCategory::create(array_merge([
            'department_id' => $this->cctv->id, 'slug' => 'cat-'.uniqid(), 'name_ar' => 'فئة', 'name_en' => 'Cat',
            'default_kind' => 'issue', 'active' => true,
        ], $attrs));
    }

    private function catalog(ObservationCategory $category, array $attrs = []): ObservationCatalogItem
    {
        return ObservationCatalogItem::create(array_merge([
            'category_id' => $category->id, 'code' => 'ITEM-'.uniqid(), 'label_ar' => 'بند', 'label_en' => 'Item',
            'keywords' => [], 'kind' => 'issue', 'extra_fields' => [], 'active' => true,
        ], $attrs));
    }

    private function draftVisit(): ObservationVisit
    {
        return (new ObservationService)->createVisit($this->user, [
            'type' => 'field', 'branch_id' => $this->branch->id,
            'visit_date' => now()->toDateString(), 'source' => 'mobile',
        ]);
    }

    public function test_create_field_visit_requires_branch(): void
    {
        try {
            (new ObservationService)->createVisit($this->user, [
                'type' => 'field', 'visit_date' => now()->toDateString(), 'source' => 'mobile',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }

        $this->assertSame(0, ObservationVisit::count());
    }

    public function test_create_visit_defaults_department_for_area_manager(): void
    {
        $manager = Seeds::user('area_manager');

        $visit = (new ObservationService)->createVisit($manager, [
            'type' => 'field', 'branch_id' => $this->branch->id,
            'visit_date' => now()->toDateString(), 'source' => 'mobile',
            'checkin_latitude' => 30.01, 'checkin_longitude' => 31.2, 'simulated' => true,
        ]);

        $this->assertSame($this->operation->id, $visit->department_id);
        $this->assertSame('draft', $visit->status);
        $this->assertSame($manager->id, $visit->user_id);
        $this->assertMatchesRegularExpression('/^OV-\d{5}$/', $visit->code);
        $this->assertNotNull($visit->started_at);
        $this->assertTrue($visit->checkin_simulated);
        $this->assertEqualsWithDelta(30.01, $visit->checkin_latitude, 0.0001);
    }

    public function test_create_visit_rejects_bad_type_source_and_missing_department(): void
    {
        $service = new ObservationService;
        $base = ['type' => 'desk', 'visit_date' => now()->toDateString(), 'source' => 'web'];

        foreach ([['type' => 'x'], ['source' => 'x'], ['visit_date' => null]] as $bad) {
            try {
                $service->createVisit($this->user, array_merge($base, $bad));
                $this->fail('Expected ValidationException for '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey(array_key_first($bad), $e->errors());
            }
        }

        // user outside any reporting department and no `operation` department to fall back to
        Department::where('slug', 'operation')->delete();
        $this->expectException(ValidationException::class);
        $service->createVisit(Seeds::user('area_manager'), $base);
    }

    public function test_upsert_from_catalog_sets_risk_and_responsible(): void
    {
        $category = $this->category();
        $catalog = $this->catalog($category, [
            'risk_level_id' => $this->risks['high']->id,
            'responsible_department_id' => $this->operation->id,
            'cc_department_ids' => [$this->cctv->id],
        ]);
        $visit = $this->draftVisit();

        $saved = (new ObservationService)->upsertObservations($visit, [[
            'catalog_item_id' => $catalog->id,
            'description' => 'الكاميرا مش شغالة عند الكاشير',
            'photos' => ['obs/a.jpg', 'obs/b.jpg'],
        ]], $this->user);

        $this->assertCount(1, $saved);
        $o = $saved->first();
        $this->assertSame($this->risks['high']->id, $o->risk_level_id);
        $this->assertSame($this->operation->id, $o->responsible_department_id);
        $this->assertSame([$this->cctv->id], $o->cc_department_ids);
        $this->assertSame($category->id, $o->category_id);
        $this->assertSame('بند', $o->title);
        $this->assertSame('issue', $o->kind);
        $this->assertSame('open', $o->status);
        $this->assertSame('catalog', $o->classification_status);
        $this->assertSame($this->branch->id, $o->branch_id);
        $this->assertSame($visit->department_id, $o->reporting_department_id);
        $this->assertSame($this->user->id, $o->created_by);
        $this->assertSame($visit->visit_date->toDateString(), $o->event_date->toDateString());
        $this->assertMatchesRegularExpression('/^OB-\d{5}$/', $o->reference);
        $this->assertSame(2, $o->photos()->where('kind', 'before')->where('source', 'gallery')->count());
    }

    public function test_escalation_rule_raises_risk(): void
    {
        $category = $this->category();
        $catalog = $this->catalog($category, [
            'risk_level_id' => $this->risks['medium']->id,
            'responsible_department_id' => $this->operation->id,
            'escalation_rules' => [['field' => 'minutes_late', 'gte' => 30, 'risk' => 'high']],
        ]);
        $service = new ObservationService;
        $visit = $this->draftVisit();

        $late = $service->upsertObservations($visit, [[
            'catalog_item_id' => $catalog->id, 'description' => 'تأخر الفتح',
            'extra_values' => ['minutes_late' => 35],
        ]], $this->user)->first();
        $this->assertSame($this->risks['high']->id, $late->risk_level_id);

        $slightlyLate = $service->upsertObservations($visit, [[
            'catalog_item_id' => $catalog->id, 'description' => 'تأخر الفتح',
            'extra_values' => ['minutes_late' => 10],
        ]], $this->user)->first();
        $this->assertSame($this->risks['medium']->id, $slightlyLate->risk_level_id);

        $notNumeric = $service->upsertObservations($visit, [[
            'catalog_item_id' => $catalog->id, 'description' => 'تأخر الفتح',
            'extra_values' => ['minutes_late' => 'كتير'],
        ]], $this->user)->first();
        $this->assertSame($this->risks['medium']->id, $notNumeric->risk_level_id);
    }

    public function test_resolver_handles_single_rule_object_lte_eq_and_sla_recompute(): void
    {
        $resolver = new RiskResolver;
        $category = $this->category();

        $lte = $this->catalog($category, [
            'risk_level_id' => $this->risks['low']->id,
            'escalation_rules' => ['field' => 'stock', 'lte' => 2, 'risk' => 'critical'],
        ]);
        $hit = $resolver->resolve(['extra_values' => ['stock' => 2]], $lte, $category);
        $this->assertSame($this->risks['critical']->id, $hit['risk_level_id']);
        $this->assertSame(24, $hit['sla_hours']);
        $miss = $resolver->resolve(['extra_values' => ['stock' => 3]], $lte, $category);
        $this->assertSame($this->risks['low']->id, $miss['risk_level_id']);
        $this->assertSame(168, $miss['sla_hours']);

        $eq = $this->catalog($category, [
            'risk_level_id' => $this->risks['low']->id,
            'sla_hours' => 6,
            'escalation_rules' => [['field' => 'count', 'eq' => 5, 'risk' => 'high']],
        ]);
        $hit = $resolver->resolve(['extra_values' => ['count' => '5']], $eq, $category);
        $this->assertSame($this->risks['high']->id, $hit['risk_level_id']);
        $this->assertSame(6, $hit['sla_hours']); // explicit item SLA is kept
    }

    public function test_resolver_precedence_and_sla(): void
    {
        $resolver = new RiskResolver;
        $category = $this->category([
            'default_risk_level_id' => $this->risks['low']->id,
            'default_responsible_department_id' => $this->operation->id,
            'default_cc_department_ids' => [$this->cctv->id],
            'default_kind' => 'assessment',
        ]);

        $fromCategory = $resolver->resolve(['risk_level_slug' => 'critical'], null, $category);
        $this->assertSame($this->risks['low']->id, $fromCategory['risk_level_id']);
        $this->assertSame($this->operation->id, $fromCategory['responsible_department_id']);
        $this->assertSame([$this->cctv->id], $fromCategory['cc_department_ids']);
        $this->assertSame(168, $fromCategory['sla_hours']);
        $this->assertSame('assessment', $fromCategory['kind']);

        $fromAi = $resolver->resolve(['risk_level_slug' => 'critical', 'kind' => 'issue', 'responsible_department_id' => $this->cctv->id], null, null);
        $this->assertSame($this->risks['critical']->id, $fromAi['risk_level_id']);
        $this->assertSame($this->cctv->id, $fromAi['responsible_department_id']);
        $this->assertSame(24, $fromAi['sla_hours']);
        $this->assertSame('issue', $fromAi['kind']);

        $nothing = $resolver->resolve(['kind' => 'issue'], null, null);
        $this->assertNull($nothing['risk_level_id']);
        $this->assertNull($nothing['sla_hours']);
        $this->assertSame([], $nothing['cc_department_ids']);

        $catalog = $this->catalog($category, ['risk_level_id' => $this->risks['medium']->id, 'sla_hours' => 12]);
        $withSla = $resolver->resolve([], $catalog, $category);
        $this->assertSame($this->risks['medium']->id, $withSla['risk_level_id']);
        $this->assertSame(12, $withSla['sla_hours']);
        $this->assertSame('issue', $withSla['kind']);
    }

    public function test_override_requires_reason(): void
    {
        $catalog = $this->catalog($this->category(), [
            'risk_level_id' => $this->risks['high']->id,
            'responsible_department_id' => $this->operation->id,
        ]);
        $service = new ObservationService;
        $visit = $this->draftVisit();

        try {
            $service->upsertObservations($visit, [[
                'catalog_item_id' => $catalog->id, 'description' => 'x', 'risk_level_slug' => 'low',
            ]], $this->user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.risk_override_reason', $e->errors());
        }
        $this->assertSame(0, Observation::count());

        $saved = $service->upsertObservations($visit, [[
            'catalog_item_id' => $catalog->id, 'description' => 'x', 'risk_level_slug' => 'low',
            'risk_override_reason' => 'خطر محدود',
        ]], $this->user)->first();
        $this->assertSame($this->risks['low']->id, $saved->risk_level_id);
        $this->assertSame('خطر محدود', $saved->risk_override_reason);

        // same slug as the resolved risk is not an override
        $same = $service->upsertObservations($visit, [[
            'catalog_item_id' => $catalog->id, 'description' => 'x', 'risk_level_slug' => 'high',
        ]], $this->user)->first();
        $this->assertSame($this->risks['high']->id, $same->risk_level_id);
        $this->assertNull($same->risk_override_reason);
    }

    public function test_free_text_item_uses_employee_slug_without_override_reason(): void
    {
        $visit = $this->draftVisit();

        $o = (new ObservationService)->upsertObservations($visit, [[
            'kind' => 'issue', 'description' => str_repeat('ن', 200), 'risk_level_slug' => 'medium',
            'responsible_department_id' => $this->operation->id, 'product_or_area' => 'المدخل',
        ]], $this->user)->first();

        $this->assertSame($this->risks['medium']->id, $o->risk_level_id);
        $this->assertSame($this->operation->id, $o->responsible_department_id);
        $this->assertSame(120, mb_strlen($o->title));
        $this->assertSame('manual', $o->classification_status);
        $this->assertSame('المدخل', $o->product_or_area);
    }

    public function test_kind_decides_status_and_ai_classification_is_linked(): void
    {
        $visit = $this->draftVisit();
        $ai = AiClassification::create([
            'observation_visit_id' => $visit->id, 'request_hash' => 'h', 'input_text' => 't', 'status' => 'ok',
        ]);

        $saved = (new ObservationService)->upsertObservations($visit, [
            ['kind' => 'positive', 'description' => 'ممتاز'],
            ['kind' => 'activity', 'description' => 'جرد'],
            ['kind' => 'issue', 'description' => 'مشكلة', 'ai_classification_id' => $ai->id, 'risk_level_slug' => 'low'],
        ], $this->user);

        $this->assertSame(['positive', 'closed_no_action', 'open'], $saved->pluck('status')->all());
        $this->assertSame('ai_pending_review', $saved[2]->classification_status);
        $this->assertSame($saved[2]->id, $ai->fresh()->observation_id);
    }

    public function test_update_existing_draft_row_and_photos_are_not_duplicated(): void
    {
        $service = new ObservationService;
        $visit = $this->draftVisit();
        $first = $service->upsertObservations($visit, [[
            'kind' => 'issue', 'description' => 'قديم', 'photos' => ['a.jpg'],
        ]], $this->user)->first();

        $updated = $service->upsertObservations($visit, [[
            'id' => $first->id, 'kind' => 'issue', 'description' => 'جديد', 'photos' => ['a.jpg', 'b.jpg'],
        ]], $this->user)->first();

        $this->assertSame($first->id, $updated->id);
        $this->assertSame('جديد', $updated->description);
        $this->assertSame(1, Observation::count());
        $this->assertSame(2, ObservationPhoto::where('observation_id', $first->id)->count());
    }

    public function test_cannot_update_observation_of_another_visit(): void
    {
        $service = new ObservationService;
        $other = $this->draftVisit();
        $foreign = $service->upsertObservations($other, [['kind' => 'issue', 'description' => 'x']], $this->user)->first();
        $visit = $this->draftVisit();

        try {
            $service->upsertObservations($visit, [['id' => $foreign->id, 'kind' => 'issue', 'description' => 'y']], $this->user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.id', $e->errors());
        }
        $this->assertSame('x', $foreign->fresh()->description);
    }

    public function test_upsert_refused_after_submit(): void
    {
        $service = new ObservationService;
        $visit = $this->draftVisit();
        $visit->update(['status' => 'submitted', 'submitted_at' => now()]);

        $this->expectException(ValidationException::class);
        $service->upsertObservations($visit, [['kind' => 'issue', 'description' => 'x']], $this->user);
    }

    public function test_delete_draft_observation_removes_photos_and_refuses_after_submit(): void
    {
        $service = new ObservationService;
        $visit = $this->draftVisit();
        $o = $service->upsertObservations($visit, [['kind' => 'issue', 'description' => 'x', 'photos' => ['a.jpg']]], $this->user)->first();

        $service->deleteDraftObservation($o);
        $this->assertSame(0, Observation::count());
        $this->assertSame(0, ObservationPhoto::count());

        $o2 = $service->upsertObservations($visit, [['kind' => 'issue', 'description' => 'x']], $this->user)->first();
        $visit->update(['status' => 'submitted']);

        $this->expectException(ValidationException::class);
        $service->deleteDraftObservation($o2->fresh());
    }
}
