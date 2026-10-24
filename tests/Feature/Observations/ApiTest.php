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
use App\Models\Ticket;
use App\Models\User;
use App\Services\Observations\Ai\ClassificationBatch;
use App\Services\Observations\Ai\ClassificationResult;
use App\Services\Observations\Ai\ClassifierContract;
use App\Services\Observations\ObservationService;
use App\Services\TicketService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Seeds;
use Tests\TestCase;

class ApiTest extends TestCase
{
    private Department $cctv;

    private Department $operation;

    private Department $stock;

    private Branch $branch;

    private Branch $otherBranch;

    private User $recorder;

    private User $operationManager;

    /** @var array<string, RiskLevel> */
    private array $risks = [];

    private const VISIT_ITEM = [
        'id', 'code', 'type', 'status', 'source', 'department' => ['id', 'name'], 'branch', 'visit_date', 'started_at',
        'checkin_latitude', 'checkin_longitude', 'checkin_simulated', 'observations_count', 'issues_count', 'submitted_at',
    ];

    private const OBSERVATION_ITEM = [
        'id', 'reference', 'kind', 'status', 'classification_status', 'title', 'description', 'product_or_area',
        'extra_values', 'risk', 'risk_level', 'responsible_department', 'cc_departments', 'branch', 'reporting_department' => ['id', 'name'],
        'event_date', 'ticket', 'photos', 'repeat_count', 'created_by' => ['id', 'name'], 'created_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 10:00:00');
        config(['services.anthropic.key' => null]);

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation', ['is_reporting' => true]);
        $this->stock = Seeds::department('stock_control', ['is_reporting' => true]);

        $this->branch = Seeds::branch('Saraya Mall');
        $this->otherBranch = Seeds::branch('City Stars Mall');
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->operationManager = Seeds::user('department_manager', ['department_id' => $this->operation->id, 'is_department_manager' => true]);

        $levels = [['critical', 24, 'critical'], ['high', 48, 'high'], ['medium', 72, 'medium'], ['low', 168, 'low'], ['none', null, null]];
        foreach ($levels as $i => [$slug, $sla, $priority]) {
            $this->risks[$slug] = RiskLevel::create([
                'slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug, 'color' => '#00000'.$i,
                'sort_order' => $i + 1, 'default_sla_hours' => $sla,
                'requires_field_verification' => in_array($slug, ['critical', 'high'], true),
                'ticket_priority' => $priority, 'active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function category(Department $department, array $attrs = []): ObservationCategory
    {
        return ObservationCategory::create(array_merge([
            'department_id' => $department->id, 'slug' => 'cat-'.uniqid(), 'name_ar' => 'فئة', 'name_en' => 'Cat',
            'default_kind' => 'issue', 'default_risk_level_id' => $this->risks['medium']->id,
            'default_responsible_department_id' => $this->operation->id, 'active' => true,
        ], $attrs));
    }

    private function catalog(ObservationCategory $category, array $attrs = []): ObservationCatalogItem
    {
        return ObservationCatalogItem::create(array_merge([
            'category_id' => $category->id, 'code' => 'ITEM-'.uniqid(), 'label_ar' => 'بند', 'label_en' => 'Item',
            'keywords' => ['باب'], 'kind' => 'issue', 'extra_fields' => [], 'active' => true,
        ], $attrs));
    }

    private function visit(array $attrs = []): ObservationVisit
    {
        return ObservationVisit::create(array_merge([
            'type' => 'field', 'department_id' => $this->cctv->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->recorder->id, 'visit_date' => now()->toDateString(), 'started_at' => now(),
            'status' => 'draft', 'source' => 'mobile',
        ], $attrs));
    }

    private function observation(ObservationVisit $visit, array $attrs = []): Observation
    {
        return Observation::create(array_merge([
            'observation_visit_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'reporting_department_id' => $visit->department_id,
            'responsible_department_id' => $this->operation->id,
            'kind' => 'issue',
            'risk_level_id' => $this->risks['high']->id,
            'title' => 'باب الطوارئ مقفول',
            'description' => 'باب الطوارئ مقفول',
            'event_date' => $visit->visit_date,
            'status' => 'open',
            'classification_status' => 'manual',
            'created_by' => $visit->user_id,
        ], $attrs));
    }

    /** A submitted high-risk issue whose ticket was approved with an after photo: waiting_verification. */
    private function waitingVerification(): Observation
    {
        $visit = $this->visit();
        $observation = $this->observation($visit);
        ObservationPhoto::create(['observation_id' => $observation->id, 'path' => 'obs/before.jpg', 'kind' => 'before', 'source' => 'camera', 'uploaded_by' => $this->recorder->id]);
        app(ObservationService::class)->submitVisit($visit, $this->recorder, ['silent' => true]);

        $ticket = $observation->fresh()->ticket;
        $tickets = app(TicketService::class);
        $tickets->addEvidence($ticket, 'tickets/after.jpg', 'after', $this->operationManager);
        $tickets->changeStatus($ticket->fresh(), 'closed', $this->operationManager);

        $observation->refresh();
        $this->assertSame('waiting_verification', $observation->status);

        return $observation;
    }

    public function test_meta_returns_user_department_catalog(): void
    {
        $own = $this->category($this->cctv, ['sort_order' => 2]);
        $first = $this->category($this->cctv, ['sort_order' => 1]);
        $this->category($this->cctv, ['active' => false]);
        $foreign = $this->category($this->stock);
        $itemB = $this->catalog($own, ['sort_order' => 1]);
        $itemA = $this->catalog($first, ['sort_order' => 5]);
        $this->catalog($own, ['active' => false]);
        $this->catalog($foreign);
        $this->risks['none']->update(['active' => false]);

        Sanctum::actingAs($this->recorder);
        $response = $this->getJson('/api/observations/meta?department_id='.$this->stock->id)->assertOk();

        $response->assertJsonStructure([
            'reporting_departments' => [['id', 'slug', 'name']],
            'risk_levels' => [['id', 'slug', 'name_ar', 'name_en', 'color', 'requires_field_verification']],
            'categories' => [['id', 'slug', 'name_ar', 'name_en', 'default_kind', 'risk_level_id', 'responsible_department_id']],
            'catalog_items' => [['id', 'category_id', 'code', 'label_ar', 'label_en', 'keywords', 'kind', 'risk_level_id', 'responsible_department_id', 'extra_fields']],
            'responsible_departments' => [['id', 'slug', 'name']],
            'areas',
            'branches' => [['id', 'name', 'area_id', 'latitude', 'longitude']],
            'default_department_id',
            'department_id',

        ]);
        // A department employee reports only for their own department, whatever the query asks for.
        $this->assertSame([['id' => $this->cctv->id, 'slug' => 'cctv', 'name' => $this->cctv->name]], $response->json('reporting_departments'));
        $this->assertSame($this->cctv->id, $response->json('default_department_id'));
        $this->assertSame($this->cctv->id, $response->json('department_id'));
        $this->assertSame([$first->id, $own->id], array_column($response->json('categories'), 'id'));
        $this->assertSame([$itemA->id, $itemB->id], array_column($response->json('catalog_items'), 'id'));
        $this->assertSame(['critical', 'high', 'medium', 'low'], array_column($response->json('risk_levels'), 'slug'));
        $this->assertSame($this->operation->id, $response->json('categories.0.responsible_department_id'));
        $this->assertSame($this->operation->id, $response->json('categories.0.default_responsible_department_id'));
        $this->assertSame($this->risks['medium']->id, $response->json('categories.0.risk_level_id'));
        $this->assertNull($response->json('catalog_items.0.responsible_department_id'));
        $this->assertSame(['باب'], $response->json('catalog_items.0.keywords'));
        $this->assertCount(2, $response->json('branches'));

        // Management may pick any reporting department.
        Sanctum::actingAs(Seeds::user('ops_manager'));
        $response = $this->getJson('/api/observations/meta?department_id='.$this->stock->id)->assertOk();
        $this->assertSame([$foreign->id], array_column($response->json('categories'), 'id'));
        $this->assertEqualsCanonicalizing([$this->cctv->id, $this->operation->id, $this->stock->id], array_column($response->json('reporting_departments'), 'id'));
        $this->assertSame($this->stock->id, $response->json('department_id'));
    }

    public function test_full_mobile_flow(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->recorder);

        $store = $this->postJson('/api/observation-visits', [
            'type' => 'field', 'branch_id' => $this->branch->id, 'visit_date' => '2026-10-22',
            'latitude' => 30.05, 'longitude' => 31.2, 'source' => 'mobile', 'general_notes' => 'زيارة صباحية',
        ])->assertCreated()->assertJsonStructure(['message', 'visit' => self::VISIT_ITEM]);
        $visitId = $store->json('visit.id');
        $this->assertSame('draft', $store->json('visit.status'));
        $this->assertSame(['id' => $this->branch->id, 'name' => 'Saraya Mall'], $store->json('visit.branch'));
        $this->assertSame('2026-10-22', $store->json('visit.visit_date'));
        $visit = ObservationVisit::findOrFail($visitId);
        $this->assertSame('mobile', $visit->source);
        $this->assertSame($this->cctv->id, (int) $visit->department_id);
        $this->assertEqualsWithDelta(30.05, $visit->checkin_latitude, 0.0001);
        $this->assertSame('زيارة صباحية', $visit->general_notes);

        $upload = $this->postJson("/api/observation-visits/{$visitId}/photos", [
            'photo' => UploadedFile::fake()->image('a.jpg'),
        ])->assertOk()->assertJsonStructure(['path', 'url', 'kind']);
        $path = $upload->json('path');
        $this->assertStringStartsWith("evidence/observations/{$visitId}/", $path);
        $this->assertSame('before', $upload->json('kind'));
        $this->assertSame(asset('storage/'.$path), $upload->json('url'));
        Storage::disk('public')->assertExists($path);

        $check = $this->postJson("/api/observation-visits/{$visitId}/ai-check", [
            'points' => [['index' => 0, 'text' => 'باب الطوارئ مقفول بجنزير']],
        ])->assertOk()->assertJsonStructure(['status', 'message', 'results', 'classification_ids']);
        $this->assertSame('skipped', $check->json('status'));
        $this->assertSame('الفحص غير متاح، اختر الفئة يدوياً', $check->json('message'));
        $this->assertSame([], $check->json('results'));
        $this->assertCount(1, $check->json('classification_ids'));
        $classification = AiClassification::findOrFail($check->json('classification_ids.0'));
        $this->assertSame('skipped', $classification->status);
        $this->assertSame($visitId, (int) $classification->observation_visit_id);
        $this->assertSame('باب الطوارئ مقفول بجنزير', $classification->input_text);
        $this->assertSame(sha1($this->cctv->id.'|باب الطوارئ مقفول بجنزير'), $classification->request_hash);

        $upsert = $this->postJson("/api/observation-visits/{$visitId}/observations", [
            'items' => [[
                'kind' => 'issue', 'description' => 'باب الطوارئ مقفول بجنزير', 'risk_level_slug' => 'high',
                'responsible_department_id' => $this->operation->id, 'photos' => [$path],
                'ai_classification_id' => $classification->id,
            ]],
        ])->assertOk()->assertJsonStructure(['data' => [self::OBSERVATION_ITEM]]);
        $this->assertSame('ai_pending_review', $upsert->json('data.0.classification_status'));
        $this->assertSame(['slug' => 'high', 'name_ar' => 'high', 'color' => '#000001'], $upsert->json('data.0.risk'));
        $this->assertSame($upsert->json('data.0.risk'), $upsert->json('data.0.risk_level'));
        $this->assertSame($path, $upsert->json('data.0.photos.0.path'));
        $this->assertSame(asset('storage/'.$path), $upsert->json('data.0.photos.0.url'));
        $this->assertNull($upsert->json('data.0.ticket'));
        $observationId = $upsert->json('data.0.id');

        $this->getJson("/api/observation-visits/{$visitId}")->assertOk()
            ->assertJsonStructure(['visit' => self::VISIT_ITEM, 'observations' => [self::OBSERVATION_ITEM]])
            ->assertJsonPath('visit.observations_count', 1);

        $this->getJson('/api/observation-visits?status=draft&mine=1')->assertOk()
            ->assertJsonStructure(['data' => [self::VISIT_ITEM], 'meta' => ['current_page', 'last_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $visitId);

        $submit = $this->postJson("/api/observation-visits/{$visitId}/submit")->assertOk()
            ->assertJsonStructure(['message', 'visit' => self::VISIT_ITEM, 'created_tickets' => [['id', 'observation_id', 'ticket_id', 'reference', 'title', 'priority', 'department' => ['id', 'name']]]]);
        $this->assertSame('submitted', $submit->json('visit.status'));
        $this->assertCount(1, $submit->json('created_tickets'));
        $this->assertSame($observationId, $submit->json('created_tickets.0.observation_id'));
        $this->assertSame('high', $submit->json('created_tickets.0.priority'));
        $this->assertSame($this->operation->id, $submit->json('created_tickets.0.department.id'));
        $this->assertSame(1, $submit->json('visit.issues_count'));

        $this->assertSame(1, Ticket::where('observation_id', $observationId)->count());
        $observation = Observation::findOrFail($observationId);
        $this->assertSame('open', $observation->status);
        $this->assertSame($submit->json('created_tickets.0.ticket_id'), (int) $observation->ticket_id);

        // A submitted visit is read-only.
        $this->postJson("/api/observation-visits/{$visitId}/submit")->assertForbidden()->assertJsonPath('message', 'غير مصرح.');
        $this->getJson('/api/observation-visits?status=draft')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_ai_check_persists_one_row_per_point(): void
    {
        $category = $this->category($this->cctv);
        $item = $this->catalog($category);
        $this->app->instance(ClassifierContract::class, new class($item) implements ClassifierContract
        {
            public function __construct(private ObservationCatalogItem $item) {}

            public function classify(int $departmentId, ?int $branchId, array $points): ClassificationBatch
            {
                return new ClassificationBatch('ok', [
                    new ClassificationResult(7, $this->item->id, $this->item->category_id, 'issue', 'high', null, 0.9, 'سبب'),
                ], 'claude-test', 120, 40, 350, null);
            }
        });
        $visit = $this->visit();
        Sanctum::actingAs($this->recorder);

        $response = $this->postJson("/api/observation-visits/{$visit->id}/ai-check", [
            'points' => [['index' => 3, 'text' => 'نقطة أولى'], ['index' => 7, 'text' => 'نقطة ثانية']],
        ])->assertOk();

        $this->assertSame('ok', $response->json('status'));
        $this->assertNull($response->json('message'));
        $ids = $response->json('classification_ids');
        $this->assertCount(2, $ids);
        $this->assertCount(1, $response->json('results'));
        $this->assertSame(7, $response->json('results.0.index'));
        $this->assertSame($item->id, $response->json('results.0.catalog_item_id'));
        $this->assertSame('high', $response->json('results.0.risk_level'));
        $this->assertSame($ids[1], $response->json('results.0.ai_classification_id'));

        [$first, $second] = [AiClassification::findOrFail($ids[0]), AiClassification::findOrFail($ids[1])];
        $this->assertSame(['ok', 'ok'], [$first->status, $second->status]);
        $this->assertNull($first->suggested);
        $this->assertSame(0.9, $second->suggested['confidence']);
        $this->assertSame([120, 40], [$first->input_tokens, $first->output_tokens]);
        $this->assertSame([null, null], [$second->input_tokens, $second->output_tokens]);
        $this->assertSame([350, 350], [$first->latency_ms, $second->latency_ms]);
        $this->assertSame('claude-test', $second->model);

        $this->postJson("/api/observation-visits/{$visit->id}/ai-check", ['points' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['points']);
    }

    public function test_upsert_stores_employee_final(): void
    {
        $category = $this->category($this->cctv);
        $item = $this->catalog($category, ['risk_level_id' => $this->risks['critical']->id]);
        $visit = $this->visit();
        $classification = AiClassification::create([
            'observation_visit_id' => $visit->id, 'request_hash' => sha1('x'), 'input_text' => 'كاميرا مطفية',
            'suggested' => ['index' => 0, 'catalog_item_id' => $item->id, 'category_id' => $category->id, 'kind' => 'issue',
                'risk_level' => 'high', 'responsible_department_id' => null, 'confidence' => 0.8, 'reason' => 'x'],
            'status' => 'ok',
        ]);
        Sanctum::actingAs($this->recorder);

        $response = $this->postJson("/api/observation-visits/{$visit->id}/observations", [
            'items' => [[
                'catalog_item_id' => $item->id, 'category_id' => $category->id, 'kind' => 'issue',
                'description' => 'كاميرا مطفية', 'ai_classification_id' => $classification->id,
            ]],
        ])->assertOk();

        $observationId = $response->json('data.0.id');
        $classification->refresh();
        $this->assertSame($observationId, (int) $classification->observation_id);
        $this->assertSame([
            'catalog_item_id' => $item->id,
            'category_id' => $category->id,
            'kind' => 'issue',
            'risk_level' => 'critical',
            'responsible_department_id' => $this->operation->id,
        ], $classification->employee_final);
        $this->assertSame('catalog', $response->json('data.0.classification_status'));
        $this->assertSame($item->id, $response->json('data.0.catalog_item_id'));
    }

    public function test_upsert_rejects_foreign_classification_and_mismatched_category(): void
    {
        $category = $this->category($this->cctv);
        $otherCategory = $this->category($this->cctv);
        $item = $this->catalog($category);
        $visit = $this->visit();
        $foreign = AiClassification::create([
            'observation_visit_id' => $this->visit()->id, 'request_hash' => sha1('y'), 'input_text' => 'y', 'status' => 'skipped',
        ]);
        Sanctum::actingAs($this->recorder);

        $this->postJson("/api/observation-visits/{$visit->id}/observations", [
            'items' => [
                ['catalog_item_id' => $item->id, 'category_id' => $otherCategory->id, 'kind' => 'issue', 'description' => 'a'],
                ['kind' => 'issue', 'description' => 'b', 'ai_classification_id' => $foreign->id],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.category_id', 'items.1.ai_classification_id']);

        $this->assertSame(0, Observation::count());

        // Someone else's draft: the policy refuses.
        Sanctum::actingAs(Seeds::user('department_employee', ['department_id' => $this->cctv->id]));
        $this->postJson("/api/observation-visits/{$visit->id}/observations", [
            'items' => [['kind' => 'issue', 'description' => 'c']],
        ])->assertForbidden();
    }

    public function test_index_is_scoped_for_store_manager(): void
    {
        $own = $this->observation($this->visit(), ['title' => 'فرعي']);
        $this->observation($this->visit(['branch_id' => $this->otherBranch->id]), ['title' => 'فرع تاني']);
        $manager = Seeds::user('store_manager', ['branch_id' => $this->branch->id]);
        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/observations')->assertOk()
            ->assertJsonStructure(['data' => [self::OBSERVATION_ITEM], 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        $this->assertSame([$own->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(30, $response->json('meta.per_page'));

        // A row of another branch is out of scope for show.
        $other = Observation::where('branch_id', $this->otherBranch->id)->sole();
        $this->getJson("/api/observations/{$other->id}")->assertForbidden();
    }

    public function test_index_filters(): void
    {
        $visit = $this->visit();
        $high = $this->observation($visit, ['title' => 'باب مقفول', 'kind' => 'issue']);
        $low = $this->observation($visit, ['title' => 'إضاءة', 'description' => 'لمبة محروقة', 'risk_level_id' => $this->risks['low']->id]);
        $positive = $this->observation($visit, ['title' => 'نظافة', 'kind' => 'positive', 'status' => 'positive', 'risk_level_id' => null]);
        Sanctum::actingAs(Seeds::user('ops_manager'));

        $ids = fn (string $query) => array_column($this->getJson('/api/observations?'.$query)->assertOk()->json('data'), 'id');

        $this->assertSame([$positive->id, $low->id, $high->id], $ids(''));
        $this->assertSame([$low->id], $ids('risk=low'));
        $this->assertSame([$low->id], $ids('q=محروقة'));
        $this->assertSame([$positive->id], $ids('kind=positive'));
        $this->assertSame([$positive->id], $ids('status=positive'));
        $this->assertSame([], $ids('branch_id='.$this->otherBranch->id));
        $this->assertSame([], $ids('from=2026-10-23'));
        $this->assertCount(3, $ids('department_id='.$this->cctv->id.'&responsible_department_id='.$this->operation->id.'&to=2026-10-22'));
    }

    public function test_visit_index_is_scoped(): void
    {
        $cctvVisit = $this->visit();
        $stockUser = Seeds::user('department_employee', ['department_id' => $this->stock->id]);
        $stockVisit = $this->visit(['department_id' => $this->stock->id, 'user_id' => $stockUser->id, 'status' => 'submitted']);
        $ids = fn (string $query = '') => array_column($this->getJson('/api/observation-visits?'.$query)->assertOk()->json('data'), 'id');

        // Global role: every visit, not only its own.
        Sanctum::actingAs(Seeds::user('ops_manager'));
        $this->assertSame([$stockVisit->id, $cctvVisit->id], $ids());
        $this->assertSame([$stockVisit->id], $ids('status=submitted'));
        $this->assertSame([$cctvVisit->id], $ids('department_id='.$this->cctv->id));
        $this->assertSame([], $ids('mine=1'));

        // Department employee: own department's visits only.
        Sanctum::actingAs(Seeds::user('department_employee', ['department_id' => $this->cctv->id]));
        $this->assertSame([$cctvVisit->id], $ids());
        $this->assertSame([], $ids('branch_id='.$this->otherBranch->id));

        // The other department's visit cannot be opened.
        $this->getJson("/api/observation-visits/{$stockVisit->id}")->assertForbidden();
    }

    public function test_non_reporting_employee_gets_403(): void
    {
        $sales = Seeds::department('sales_floor', ['is_reporting' => false]);
        Sanctum::actingAs(Seeds::user('department_employee', ['department_id' => $sales->id]));

        $this->getJson('/api/observations/meta')->assertForbidden()->assertJsonPath('message', 'غير مصرح.');
        $this->postJson('/api/observation-visits', [
            'type' => 'desk', 'visit_date' => '2026-10-22',
        ])->assertForbidden()->assertJsonPath('message', 'غير مصرح.');
        $this->getJson('/api/observations')->assertForbidden();
        $this->assertSame(0, ObservationVisit::count());
    }

    public function test_store_field_visit_requires_branch(): void
    {
        Sanctum::actingAs($this->recorder);

        $this->postJson('/api/observation-visits', ['type' => 'field', 'visit_date' => '2026-10-22'])
            ->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
        $this->postJson('/api/observation-visits', ['type' => 'walk'])
            ->assertUnprocessable()->assertJsonValidationErrors(['type', 'visit_date']);

        // A desk visit for all branches; no bearer token means the web page.
        $response = $this->postJson('/api/observation-visits', ['type' => 'desk', 'visit_date' => '2026-10-22'])->assertCreated();
        $this->assertNull($response->json('visit.branch'));
        $this->assertSame('web', ObservationVisit::findOrFail($response->json('visit.id'))->source);
    }

    public function test_submit_returns_422_per_item_without_responsible(): void
    {
        $visit = $this->visit();
        Sanctum::actingAs($this->recorder);

        $this->postJson("/api/observation-visits/{$visit->id}/observations", [
            'items' => [['kind' => 'issue', 'description' => 'مشكلة بدون إدارة']],
        ])->assertOk();

        $this->postJson("/api/observation-visits/{$visit->id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.responsible_department_id']);

        $this->assertSame('draft', $visit->fresh()->status);
        $this->assertSame(0, Ticket::count());
    }

    public function test_verify_endpoint_closes_waiting_observation(): void
    {
        $observation = $this->waitingVerification();

        $hr = Seeds::department('hr', ['is_reporting' => true]);
        Sanctum::actingAs(Seeds::user('department_employee', ['department_id' => $hr->id]));
        $this->postJson("/api/observations/{$observation->id}/verify", ['result' => 'resolved'])->assertForbidden();

        Sanctum::actingAs($this->recorder);
        $this->postJson("/api/observations/{$observation->id}/verify", ['result' => 'maybe'])
            ->assertUnprocessable()->assertJsonValidationErrors(['result']);
        $this->postJson("/api/observations/{$observation->id}/verify", ['result' => 'not_resolved'])
            ->assertUnprocessable()->assertJsonValidationErrors(['notes']);

        $response = $this->postJson("/api/observations/{$observation->id}/verify", [
            'result' => 'resolved', 'notes' => 'تمام', 'photo_path' => 'evidence/observations/check.jpg',
        ])->assertOk()->assertJsonStructure([
            'message',
            'verification' => ['id', 'result', 'notes', 'verified_by' => ['id', 'name'], 'verified_at'],
            'observation' => self::OBSERVATION_ITEM,
        ]);

        $this->assertSame('verified_closed', $response->json('observation.status'));
        $this->assertSame($this->recorder->id, $response->json('verification.verified_by.id'));
        $this->assertSame('verified_closed', $observation->fresh()->status);
        $this->assertTrue(ObservationPhoto::where('observation_id', $observation->id)->where('path', 'evidence/observations/check.jpg')->exists());
    }

    public function test_pending_verification_lists_branch_rows(): void
    {
        $observation = $this->waitingVerification();
        $this->observation($this->visit(), ['status' => 'open']);

        Sanctum::actingAs($this->recorder);
        $response = $this->getJson("/api/branches/{$this->branch->id}/observations/pending-verification")->assertOk()
            ->assertJsonStructure(['data' => [self::OBSERVATION_ITEM]]);
        $this->assertSame([$observation->id], array_column($response->json('data'), 'id'));
        $this->assertSame('waiting_verification', $response->json('data.0.status'));
        $this->assertSame($observation->ticket_id, $response->json('data.0.ticket.id'));

        // A store manager of another branch sees nothing of this branch.
        Sanctum::actingAs(Seeds::user('store_manager', ['branch_id' => $this->otherBranch->id]));
        $this->getJson("/api/branches/{$this->branch->id}/observations/pending-verification")->assertOk()->assertJsonPath('data', []);
    }

    public function test_login_payload_has_reporting_flags(): void
    {
        $this->postJson('/api/login', ['email' => $this->recorder->email, 'password' => 'password'])->assertOk()
            ->assertJsonPath('user.is_reporting_user', true)
            ->assertJsonPath('user.reporting_departments', [['id' => $this->cctv->id, 'slug' => 'cctv', 'name' => $this->cctv->name]]);

        $sales = Seeds::department('sales_floor', ['is_reporting' => false]);
        $plain = Seeds::user('department_employee', ['department_id' => $sales->id]);
        $this->postJson('/api/login', ['email' => $plain->email, 'password' => 'password'])->assertOk()
            ->assertJsonPath('user.is_reporting_user', false)
            ->assertJsonPath('user.reporting_departments', []);

        Sanctum::actingAs($this->recorder);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.is_reporting_user', true);
    }

    public function test_destroy_draft_observation(): void
    {
        $visit = $this->visit();
        $observation = $this->observation($visit);
        ObservationPhoto::create(['observation_id' => $observation->id, 'path' => 'obs/a.jpg', 'kind' => 'before', 'source' => 'camera', 'uploaded_by' => $this->recorder->id]);

        Sanctum::actingAs(Seeds::user('department_employee', ['department_id' => $this->cctv->id]));
        $this->deleteJson("/api/observations/{$observation->id}")->assertForbidden();

        Sanctum::actingAs($this->recorder);
        $this->deleteJson("/api/observations/{$observation->id}")->assertOk()->assertJsonStructure(['message']);
        $this->assertNull(Observation::find($observation->id));
        $this->assertSame(0, ObservationPhoto::count());

        $submitted = $this->observation($this->visit(['status' => 'submitted']));
        $this->deleteJson("/api/observations/{$submitted->id}")->assertForbidden();
        $this->assertNotNull($submitted->fresh());
    }

    public function test_observation_show_shape(): void
    {
        $observation = $this->waitingVerification();
        AiClassification::create([
            'observation_visit_id' => $observation->observation_visit_id, 'observation_id' => $observation->id,
            'request_hash' => sha1('z'), 'input_text' => 'باب', 'status' => 'skipped',
        ]);
        $observation->update(['cc_department_ids' => [$this->stock->id]]);

        Sanctum::actingAs($this->recorder);
        $response = $this->getJson("/api/observations/{$observation->id}")->assertOk()->assertJsonStructure([
            'observation' => self::OBSERVATION_ITEM,
            'photos' => [['id', 'kind', 'path', 'url']],
            'ticket' => ['id', 'reference', 'title', 'status', 'priority', 'due_at', 'is_overdue'],
            'verifications',
            'classification' => ['id', 'status', 'suggested', 'employee_final', 'admin_final'],
        ]);

        $this->assertSame($observation->reference, $response->json('observation.reference'));
        $this->assertSame([['id' => $this->stock->id, 'name' => $this->stock->name]], $response->json('observation.cc_departments'));
        $this->assertSame(['id' => $this->operation->id, 'name' => $this->operation->name], $response->json('observation.responsible_department'));
        $this->assertSame($observation->ticket_id, $response->json('ticket.id'));
        $this->assertFalse($response->json('ticket.is_overdue'));
        $this->assertSame(['before', 'after'], array_column($response->json('photos'), 'kind'));
        $this->assertSame('2026-10-22', $response->json('observation.event_date'));
        $this->assertSame([], $response->json('verifications'));
        $this->assertSame('skipped', $response->json('classification.status'));
    }
}
