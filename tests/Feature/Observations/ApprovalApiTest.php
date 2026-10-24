<?php

namespace Tests\Feature\Observations;

use App\Models\Branch;
use App\Models\Department;
use App\Models\AiClassification;
use App\Models\Notification;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Observations\Ai\ClassificationBatch;
use App\Services\Observations\Ai\ClassificationResult;
use App\Services\Observations\Ai\ClassifierContract;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Seeds;
use Tests\TestCase;

/** Plan H: the manager approval gate over the API (submit -> to_approve list -> classify / edit -> approve). */
class ApprovalApiTest extends TestCase
{
    private Department $cctv;

    private Department $stock;

    private Department $operation;

    private Branch $branch;

    private User $recorder;

    private User $cctvManager;

    private User $stockManager;

    private User $ops;

    /** @var array<string, RiskLevel> */
    private array $risks = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        config(['services.anthropic.key' => null, 'observations.manager_approval' => true]);

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation', ['is_reporting' => true]);
        $this->branch = Seeds::branch('Saraya Mall');
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->cctvManager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);
        $this->stockManager = Seeds::user('department_manager', ['department_id' => $this->stock->id, 'is_department_manager' => true]);
        $this->ops = Seeds::user('ops_manager');

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
            'risk_level_id' => $this->risks['medium']->id,
            'title' => 'باب الطوارئ مقفول',
            'description' => 'باب الطوارئ مقفول',
            'event_date' => $visit->visit_date,
            'status' => 'open',
            'classification_status' => 'manual',
            'created_by' => $visit->user_id,
        ], $attrs));
    }

    private function submitted(array $attrs = []): ObservationVisit
    {
        return $this->visit(array_merge(['status' => 'submitted', 'submitted_at' => now()->subHour()], $attrs));
    }

    public function test_submit_creates_no_tickets_and_notifies_the_manager(): void
    {
        $visit = $this->visit();
        $this->observation($visit);
        $this->observation($visit, ['responsible_department_id' => null, 'description' => 'نقطة حرة', 'title' => 'نقطة حرة']);
        Sanctum::actingAs($this->recorder);

        $response = $this->postJson("/api/observation-visits/{$visit->id}/submit")->assertOk();

        $this->assertSame('submitted', $response->json('visit.status'));
        $this->assertSame([], $response->json('created_tickets'));
        $this->assertSame(1, $response->json('visit.pending_points'));
        $this->assertNull($response->json('visit.approved_at'));
        $this->assertNull($response->json('visit.approved_by'));
        $this->assertSame(0, Ticket::count());
        $this->assertSame(1, Notification::where('type', 'observation_visit_submitted')->where('user_id', $this->cctvManager->id)->count());

        // Read-only once submitted (owner policy), and the status filter accepts the new value.
        $this->postJson("/api/observation-visits/{$visit->id}/observations", ['items' => [['kind' => 'issue', 'description' => 'x']]])->assertForbidden();
        $this->getJson('/api/observation-visits?status=submitted&mine=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/observation-visits?status=approved&mine=1')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_to_approve_scope_lists_the_departments_the_user_approves_for(): void
    {
        $mine = $this->submitted();
        $this->observation($mine, ['responsible_department_id' => null]);
        $this->observation($mine);
        $other = $this->submitted(['department_id' => $this->stock->id, 'user_id' => $this->stockManager->id]);
        $this->visit(); // draft: never listed
        $this->submitted(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->cctvManager->id]);

        Sanctum::actingAs($this->cctvManager);
        $list = $this->getJson('/api/observation-visits?status=submitted&scope=to_approve')->assertOk();
        $this->assertSame([$mine->id], array_column($list->json('data'), 'id'));
        $this->assertSame(1, $list->json('data.0.pending_points'));
        $this->assertSame(2, $list->json('data.0.issues_count'));

        Sanctum::actingAs($this->ops);
        $all = $this->getJson('/api/observation-visits?scope=to_approve')->assertOk();
        $this->assertEqualsCanonicalizing([$mine->id, $other->id], array_column($all->json('data'), 'id'));

        Sanctum::actingAs($this->stockManager);
        $this->assertSame([$other->id], array_column($this->getJson('/api/observation-visits?scope=to_approve')->json('data'), 'id'));

        // An employee approves nothing; a manager of a non-reporting department is not a module user.
        Sanctum::actingAs($this->recorder);
        $this->getJson('/api/observation-visits?scope=to_approve')->assertOk()->assertJsonPath('meta.total', 0);
        $hr = Seeds::department('hr', ['is_reporting' => false]);
        Sanctum::actingAs(Seeds::user('department_manager', ['department_id' => $hr->id, 'is_department_manager' => true]));
        $this->getJson('/api/observation-visits?scope=to_approve')->assertForbidden();
    }

    public function test_approve_endpoint_creates_the_tickets(): void
    {
        $visit = $this->submitted();
        $issue = $this->observation($visit, ['risk_level_id' => $this->risks['high']->id]);
        $positive = $this->observation($visit, ['kind' => 'positive', 'status' => 'positive', 'responsible_department_id' => null]);
        Sanctum::actingAs($this->cctvManager);

        $response = $this->postJson("/api/observation-visits/{$visit->id}/approve")->assertOk()
            ->assertJsonStructure(['message', 'visit', 'created_tickets' => [['id', 'observation_id', 'ticket_id', 'reference', 'title', 'priority', 'department' => ['id', 'name']]]]);

        $this->assertSame('approved', $response->json('visit.status'));
        $this->assertSame(now()->toIso8601String(), $response->json('visit.approved_at'));
        $this->assertSame(['id' => $this->cctvManager->id, 'name' => $this->cctvManager->name], $response->json('visit.approved_by'));
        $this->assertCount(1, $response->json('created_tickets'));
        $this->assertSame($issue->id, $response->json('created_tickets.0.observation_id'));
        $this->assertSame('high', $response->json('created_tickets.0.priority'));
        $this->assertSame($this->operation->id, $response->json('created_tickets.0.department.id'));
        $this->assertSame($response->json('created_tickets.0.ticket_id'), (int) $issue->fresh()->ticket_id);
        $this->assertSame($this->recorder->id, Ticket::sole()->created_by);
        $this->assertNull($positive->fresh()->ticket_id);

        // Idempotent for the supervising manager; the visit stays approved.
        $again = $this->postJson("/api/observation-visits/{$visit->id}/approve")->assertOk();
        $this->assertSame($response->json('created_tickets.0.ticket_id'), $again->json('created_tickets.0.ticket_id'));
        $this->assertSame(1, Ticket::count());

        // The visit now shows the ticket to its owner.
        Sanctum::actingAs($this->recorder);
        $show = $this->getJson("/api/observation-visits/{$visit->id}")->assertOk();
        $this->assertSame('approved', $show->json('visit.status'));
        $this->assertNotNull($show->json('observations.0.ticket'));
    }

    public function test_approve_returns_422_per_unrouted_issue_and_403_outside_the_department(): void
    {
        $visit = $this->submitted();
        $this->observation($visit);
        $this->observation($visit, ['responsible_department_id' => null]);

        Sanctum::actingAs($this->stockManager);
        $this->postJson("/api/observation-visits/{$visit->id}/approve")->assertForbidden();
        Sanctum::actingAs($this->recorder);
        $this->postJson("/api/observation-visits/{$visit->id}/approve")->assertForbidden();

        Sanctum::actingAs($this->cctvManager);
        $this->postJson("/api/observation-visits/{$visit->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.responsible_department_id']);
        $this->assertSame('submitted', $visit->fresh()->status);
        $this->assertSame(0, Ticket::count());

        // A draft cannot be approved, even by ops.
        $draft = $this->visit();
        Sanctum::actingAs($this->ops);
        $this->postJson("/api/observation-visits/{$draft->id}/approve")->assertForbidden();
    }

    private function fakeClassifier(string $status, array $results): void
    {
        $this->app->instance(ClassifierContract::class, new class($status, $results) implements ClassifierContract
        {
            public array $calls = [];

            public function __construct(private string $status, private array $results) {}

            public function classify(int $departmentId, ?int $branchId, array $points): ClassificationBatch
            {
                $this->calls[] = [$departmentId, $branchId, $points];

                return new ClassificationBatch($this->status, $this->results, 'claude-test', 100, 20, 250, $this->status === 'failed' ? 'boom' : null);
            }
        });
    }

    public function test_classify_with_the_null_driver_is_skipped_and_changes_nothing(): void
    {
        $visit = $this->submitted();
        $free = $this->observation($visit, ['responsible_department_id' => null, 'risk_level_id' => null, 'description' => 'نقطة حرة', 'title' => 'نقطة حرة']);

        Sanctum::actingAs($this->recorder);
        $this->postJson("/api/observation-visits/{$visit->id}/classify")->assertForbidden();

        Sanctum::actingAs($this->cctvManager);
        $response = $this->postJson("/api/observation-visits/{$visit->id}/classify")->assertOk()
            ->assertJsonStructure(['status', 'message', 'observations' => [['id', 'visit_status', 'can_edit_classification']]]);

        $this->assertSame('skipped', $response->json('status'));
        $this->assertSame('الفحص غير متاح، اختر الفئة يدوياً', $response->json('message'));
        $this->assertSame('submitted', $response->json('observations.0.visit_status'));
        $this->assertTrue($response->json('observations.0.can_edit_classification'));
        $this->assertNull($response->json('observations.0.responsible_department'));
        $free->refresh();
        $this->assertNull($free->responsible_department_id);
        $this->assertSame('manual', $free->classification_status);
        $this->assertSame(1, AiClassification::where('observation_id', $free->id)->where('status', 'skipped')->count());

        // Only submitted visits are classified by the manager.
        $draft = $this->visit();
        $this->postJson("/api/observation-visits/{$draft->id}/classify")->assertForbidden();
    }

    public function test_classify_writes_suggestions_on_the_free_observations_only(): void
    {
        $category = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => 'emergency', 'name_ar' => 'طوارئ', 'name_en' => 'Emergency', 'default_kind' => 'issue', 'active' => true]);
        $visit = $this->submitted();
        $catalog = $this->observation($visit, ['catalog_item_id' => null, 'classification_status' => 'catalog', 'risk_level_id' => $this->risks['low']->id]);
        $catalogItem = \App\Models\ObservationCatalogItem::create(['category_id' => $category->id, 'code' => 'CCTV-X', 'label_ar' => 'بند', 'label_en' => 'Item', 'kind' => 'issue', 'keywords' => [], 'extra_fields' => [], 'active' => true]);
        $catalog->update(['catalog_item_id' => $catalogItem->id, 'category_id' => $category->id]);
        $first = $this->observation($visit, ['responsible_department_id' => null, 'risk_level_id' => null, 'description' => 'باب الطوارئ مقفول بجنزير', 'title' => 'باب الطوارئ مقفول بجنزير']);
        $second = $this->observation($visit, ['responsible_department_id' => null, 'risk_level_id' => null, 'description' => 'نقطة بلا اقتراح', 'title' => 'نقطة بلا اقتراح']);

        $this->fakeClassifier('ok', [new ClassificationResult(0, null, $category->id, 'issue', 'high', $this->stock->id, 0.9, 'سبب')]);
        Sanctum::actingAs($this->cctvManager);

        $response = $this->postJson("/api/observation-visits/{$visit->id}/classify")->assertOk();

        $this->assertSame('ok', $response->json('status'));
        $this->assertNull($response->json('message'));
        $this->assertCount(3, $response->json('observations'));
        $classifier = $this->app->make(ClassifierContract::class);
        $this->assertSame([[$this->cctv->id, $this->branch->id, [[0, 'باب الطوارئ مقفول بجنزير'], [1, 'نقطة بلا اقتراح']]]], $classifier->calls, 'only the rows without a catalog item');

        $first->refresh();
        $this->assertSame($this->stock->id, $first->responsible_department_id);
        $this->assertSame($this->risks['high']->id, $first->risk_level_id);
        $this->assertSame($category->id, $first->category_id);
        $this->assertSame('ai_pending_review', $first->classification_status);
        $row = AiClassification::where('observation_id', $first->id)->sole();
        $this->assertSame('ok', $row->status);
        $this->assertSame($this->stock->id, $row->suggested['responsible_department_id']);
        $this->assertSame($visit->id, (int) $row->observation_visit_id);
        $this->assertSame('باب الطوارئ مقفول بجنزير', $row->input_text);

        $second->refresh();
        $this->assertNull($second->responsible_department_id);
        $this->assertSame('manual', $second->classification_status);
        $this->assertNull(AiClassification::where('observation_id', $second->id)->sole()->suggested);

        $catalog->refresh();
        $this->assertSame($this->operation->id, $catalog->responsible_department_id);
        $this->assertSame('catalog', $catalog->classification_status);
        $this->assertSame(0, AiClassification::where('observation_id', $catalog->id)->count());

        $item = collect($response->json('observations'))->firstWhere('id', $first->id);
        $this->assertSame(['id' => $this->stock->id, 'name' => $this->stock->name], $item['responsible_department']);
        $this->assertSame('high', $item['risk']['slug']);
        $this->assertSame(1, $response->json('visit.pending_points'));
    }

    public function test_classify_failure_changes_nothing(): void
    {
        $visit = $this->submitted();
        $free = $this->observation($visit, ['responsible_department_id' => null, 'risk_level_id' => null]);
        $this->fakeClassifier('failed', []);
        Sanctum::actingAs($this->ops);

        $response = $this->postJson("/api/observation-visits/{$visit->id}/classify")->assertOk();

        $this->assertSame('failed', $response->json('status'));
        $this->assertSame('الفحص غير متاح، اختر الفئة يدوياً', $response->json('message'));
        $this->assertNull($free->fresh()->responsible_department_id);
        $this->assertSame('manual', $free->fresh()->classification_status);
        $this->assertSame('boom', AiClassification::where('observation_id', $free->id)->sole()->error);
    }

    public function test_manager_edits_an_observation_until_the_visit_is_approved(): void
    {
        $category = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => 'emergency', 'name_ar' => 'طوارئ', 'name_en' => 'Emergency', 'default_kind' => 'issue', 'active' => true]);
        $foreign = ObservationCategory::create(['department_id' => $this->stock->id, 'slug' => 'stock', 'name_ar' => 'مخزون', 'name_en' => 'Stock', 'default_kind' => 'issue', 'active' => true]);
        $visit = $this->submitted();
        $o = $this->observation($visit, ['responsible_department_id' => null, 'risk_level_id' => null, 'description' => 'نقطة حرة', 'title' => 'نقطة حرة']);

        Sanctum::actingAs($this->recorder);
        $this->putJson("/api/observations/{$o->id}", ['responsible_department_id' => $this->operation->id])->assertForbidden();
        $show = $this->getJson("/api/observations/{$o->id}")->assertOk();
        $this->assertFalse($show->json('observation.can_edit_classification'));
        $this->assertSame('submitted', $show->json('observation.visit_status'));

        Sanctum::actingAs($this->stockManager);
        $this->putJson("/api/observations/{$o->id}", ['responsible_department_id' => $this->operation->id])->assertForbidden();

        Sanctum::actingAs($this->cctvManager);
        $this->putJson("/api/observations/{$o->id}", ['category_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        $this->putJson("/api/observations/{$o->id}", ['risk_level_slug' => 'extreme'])
            ->assertUnprocessable()->assertJsonValidationErrors(['risk_level_slug']);

        $response = $this->putJson("/api/observations/{$o->id}", [
            'responsible_department_id' => $this->operation->id, 'risk_level_slug' => 'high', 'category_id' => $category->id,
            'description' => 'باب الطوارئ مقفول بجنزير', 'kind' => 'issue',
        ])->assertOk()->assertJsonStructure(['message', 'observation' => ['id', 'visit_status', 'can_edit_classification']]);

        $this->assertSame(['id' => $this->operation->id, 'name' => $this->operation->name], $response->json('observation.responsible_department'));
        $this->assertSame('high', $response->json('observation.risk.slug'));
        $this->assertSame($category->id, $response->json('observation.category_id'));
        $this->assertSame('باب الطوارئ مقفول بجنزير', $response->json('observation.description'));
        $this->assertSame('باب الطوارئ مقفول بجنزير', $response->json('observation.title'), 'a title derived from the text follows it');
        $this->assertTrue($response->json('observation.can_edit_classification'));

        // kind changes keep the draft status rules; clearing the responsible department is allowed.
        $this->putJson("/api/observations/{$o->id}", ['kind' => 'positive'])->assertOk()->assertJsonPath('observation.status', 'positive');
        $this->putJson("/api/observations/{$o->id}", ['kind' => 'issue', 'responsible_department_id' => null])->assertOk()
            ->assertJsonPath('observation.status', 'open')->assertJsonPath('observation.responsible_department', null);

        // Once approved nothing moves (403) and the item says so.
        $this->putJson("/api/observations/{$o->id}", ['responsible_department_id' => $this->operation->id])->assertOk();
        $this->postJson("/api/observation-visits/{$visit->id}/approve")->assertOk();
        $this->putJson("/api/observations/{$o->id}", ['risk_level_slug' => 'low'])->assertForbidden();
        $this->assertFalse($this->getJson("/api/observations/{$o->id}")->json('observation.can_edit_classification'));
        $this->assertSame('approved', $this->getJson("/api/observations/{$o->id}")->json('observation.visit_status'));
    }
}
