<?php

namespace Tests\Unit\Observations;

use App\Models\AiClassification;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\User;
use App\Services\Observations\Ai\ClassificationResult;
use App\Services\Observations\Ai\PromptBuilder;
use Tests\Support\Seeds;
use Tests\TestCase;

class PromptBuilderTest extends TestCase
{
    private Department $cctv;

    private Department $vm;

    private Department $maintenance;

    private User $user;

    /** @var array<string, RiskLevel> */
    private array $risks = [];

    private ObservationCategory $cctvCategory;

    private ObservationCatalogItem $cctvItem;

    private ObservationCategory $vmCategory;

    private ObservationCatalogItem $vmItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->vm = Seeds::department('vm', ['is_reporting' => true]);
        $this->maintenance = Seeds::department('maintenance');
        $this->user = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);

        foreach ([['critical', 24], ['high', 48], ['medium', 72], ['low', 168], ['none', null]] as $i => [$slug, $sla]) {
            $this->risks[$slug] = RiskLevel::create([
                'slug' => $slug, 'name_ar' => 'ريسك '.$slug, 'name_en' => ucfirst($slug), 'color' => '#000000',
                'sort_order' => $i + 1, 'default_sla_hours' => $sla, 'active' => true,
            ]);
        }

        $this->cctvCategory = ObservationCategory::create([
            'department_id' => $this->cctv->id, 'slug' => 'cameras', 'name_ar' => 'الكاميرات', 'name_en' => 'Cameras',
            'default_kind' => 'issue', 'default_risk_level_id' => $this->risks['high']->id,
            'default_responsible_department_id' => $this->maintenance->id, 'active' => true,
        ]);
        $this->cctvItem = ObservationCatalogItem::create([
            'category_id' => $this->cctvCategory->id, 'code' => 'CCTV-001', 'label_ar' => 'كاميرا معطلة',
            'label_en' => 'Camera down', 'keywords' => ['كاميرا', 'عطل'], 'kind' => 'issue',
            'risk_level_id' => $this->risks['high']->id, 'responsible_department_id' => $this->maintenance->id, 'active' => true,
        ]);

        $this->vmCategory = ObservationCategory::create([
            'department_id' => $this->vm->id, 'slug' => 'windows', 'name_ar' => 'الفاترينة', 'name_en' => 'Windows',
            'default_kind' => 'issue', 'active' => true,
        ]);
        $this->vmItem = ObservationCatalogItem::create([
            'category_id' => $this->vmCategory->id, 'code' => 'VM-001', 'label_ar' => 'مانيكان مكسورة',
            'label_en' => 'Broken mannequin', 'kind' => 'issue', 'active' => true,
        ]);
    }

    private function visit(Department $department): ObservationVisit
    {
        return ObservationVisit::create([
            'type' => 'field', 'department_id' => $department->id, 'user_id' => $this->user->id,
            'visit_date' => '2026-10-20', 'status' => 'submitted', 'source' => 'web',
        ]);
    }

    public function test_system_prompt_lists_catalog_and_examples(): void
    {
        $cctvVisit = $this->visit($this->cctv);
        AiClassification::create([
            'observation_visit_id' => $cctvVisit->id, 'request_hash' => 'h1',
            'input_text' => 'الكاميرا اللي عند الكاشير مش بتسجل',
            'admin_final' => ['catalog_item_id' => $this->cctvItem->id, 'risk_level' => 'critical'],
            'status' => 'ok',
        ]);
        AiClassification::create([
            'observation_visit_id' => $cctvVisit->id, 'request_hash' => 'h2',
            'input_text' => 'نص لسه ما اتراجعش', 'status' => 'ok',
        ]);
        AiClassification::create([
            'observation_visit_id' => $this->visit($this->vm)->id, 'request_hash' => 'h3',
            'input_text' => 'مثال من إدارة تانية', 'admin_final' => ['catalog_item_id' => $this->vmItem->id],
            'status' => 'ok',
        ]);

        $builder = new PromptBuilder;
        $system = $builder->system($this->cctv->id);

        // Catalog, categories, risk levels and departments come from the DB.
        $this->assertStringContainsString('كاميرا معطلة', $system);
        $this->assertStringContainsString('Camera down', $system);
        $this->assertStringContainsString('CCTV-001', $system);
        $this->assertStringContainsString('الكاميرات', $system);
        $this->assertStringContainsString('ريسك critical', $system);
        $this->assertStringContainsString('Maintenance', $system);

        // A previous admin decision of this department is an example.
        $this->assertStringContainsString('الكاميرا اللي عند الكاشير مش بتسجل', $system);
        $this->assertStringContainsString('"risk_level":"critical"', $system);

        // Other departments' catalog, unreviewed rows and foreign examples stay out.
        $this->assertStringNotContainsString('مانيكان مكسورة', $system);
        $this->assertStringNotContainsString('نص لسه ما اتراجعش', $system);
        $this->assertStringNotContainsString('مثال من إدارة تانية', $system);

        $this->assertSame($system, $builder->system($this->cctv->id), 'system prompt must be byte-stable for caching');
    }

    public function test_sanitises_bad_model_output(): void
    {
        $raw = [
            ['index' => 2, 'catalog_item_id' => $this->vmItem->id, 'category_id' => $this->vmCategory->id,
                'kind' => 'issue', 'risk_level' => 'low', 'responsible_department_id' => $this->maintenance->id,
                'confidence' => 0.5, 'reason' => 'بند إدارة تانية'],
            ['index' => 0, 'catalog_item_id' => 999999, 'category_id' => null, 'kind' => 'issue',
                'risk_level' => 'extreme', 'responsible_department_id' => 999999, 'reason' => 'سبب'],
            ['index' => 'one', 'catalog_item_id' => $this->cctvItem->id, 'risk_level' => 'high', 'confidence' => 0.9],
            ['index' => 1, 'catalog_item_id' => $this->cctvItem->id, 'category_id' => null, 'kind' => 'weird',
                'risk_level' => 'high', 'responsible_department_id' => $this->maintenance->id, 'confidence' => 1.7],
        ];

        $results = (new PromptBuilder)->sanitise($raw, $this->cctv->id);

        // The entry with a non-integer index is dropped; the rest are sorted by index.
        $this->assertCount(3, $results);
        $this->assertContainsOnlyInstancesOf(ClassificationResult::class, $results);
        $this->assertSame([0, 1, 2], array_map(fn (ClassificationResult $r) => $r->index, $results));

        [$unknown, $valid, $foreign] = $results;

        $this->assertNull($unknown->catalogItemId);
        $this->assertNull($unknown->categoryId);
        $this->assertSame('medium', $unknown->riskLevelSlug);
        $this->assertSame(0.0, $unknown->confidence);
        $this->assertNull($unknown->responsibleDepartmentId);
        $this->assertSame('سبب', $unknown->reason);

        $this->assertSame($this->cctvItem->id, $valid->catalogItemId);
        $this->assertSame($this->cctvCategory->id, $valid->categoryId);
        $this->assertSame('issue', $valid->kind);
        $this->assertSame('high', $valid->riskLevelSlug);
        $this->assertSame($this->maintenance->id, $valid->responsibleDepartmentId);
        $this->assertSame(1.0, $valid->confidence);
        $this->assertSame('', $valid->reason);

        // An item and category of ANOTHER department's catalog are not accepted.
        $this->assertNull($foreign->catalogItemId);
        $this->assertNull($foreign->categoryId);
        $this->assertSame('low', $foreign->riskLevelSlug);

        $this->assertSame([
            'index' => 1, 'catalog_item_id' => $this->cctvItem->id, 'category_id' => $this->cctvCategory->id,
            'kind' => 'issue', 'risk_level' => 'high', 'responsible_department_id' => $this->maintenance->id,
            'confidence' => 1.0, 'reason' => '',
        ], $valid->toArray());
    }

    public function test_schema_requires_all_fields(): void
    {
        $schema = (new PromptBuilder)->schema();

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['results'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);

        $point = $schema['properties']['results']['items'];
        $this->assertSame('object', $point['type']);
        $this->assertFalse($point['additionalProperties']);
        $this->assertEqualsCanonicalizing([
            'index', 'catalog_item_id', 'category_id', 'kind', 'risk_level',
            'responsible_department_id', 'confidence', 'reason',
        ], $point['required']);
        $this->assertSame(RiskLevel::SLUGS, $point['properties']['risk_level']['enum']);
        $this->assertSame(Observation::KINDS, $point['properties']['kind']['enum']);
    }
}
