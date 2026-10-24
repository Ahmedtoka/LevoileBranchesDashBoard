<?php

namespace Tests\Feature\Observations;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
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
}
