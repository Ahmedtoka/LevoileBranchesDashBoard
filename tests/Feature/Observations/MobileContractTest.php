<?php

namespace Tests\Feature\Observations;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Seeds;
use Tests\TestCase;

/** Fields the mobile app depends on (post-Plan H): ticket source / observation_id, visit recorder, evidence path, "other" category default. */
class MobileContractTest extends TestCase
{
    private Department $cctv;

    private Department $operation;

    private Branch $branch;

    private User $recorder;

    private RiskLevel $medium;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation');
        $this->branch = Seeds::branch('Saraya Mall');
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->medium = RiskLevel::create([
            'slug' => 'medium', 'name_ar' => 'متوسط', 'name_en' => 'Medium', 'color' => '#c58a00', 'sort_order' => 3,
            'default_sla_hours' => 72, 'requires_field_verification' => false, 'ticket_priority' => 'medium', 'active' => true,
        ]);
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

    private function observationTicket(): array
    {
        $visit = $this->visit(['status' => 'approved', 'submitted_at' => now(), 'approved_at' => now(), 'approved_by' => $this->recorder->id]);
        $ticket = Ticket::create([
            'reference' => 'OPE-0001', 'title' => 'باب الطوارئ مقفول', 'description' => 'x',
            'branch_id' => $this->branch->id, 'department_id' => $this->operation->id, 'created_by' => $this->recorder->id,
            'status' => 'open', 'priority' => 'medium', 'sla_hours' => 72, 'due_at' => now()->addHours(72), 'source' => 'observation',
        ]);
        $observation = Observation::create([
            'observation_visit_id' => $visit->id, 'branch_id' => $this->branch->id, 'reporting_department_id' => $this->cctv->id,
            'responsible_department_id' => $this->operation->id, 'kind' => 'issue', 'title' => $ticket->title, 'description' => $ticket->title,
            'event_date' => now()->toDateString(), 'status' => 'open', 'classification_status' => 'manual', 'created_by' => $this->recorder->id, 'ticket_id' => $ticket->id,
        ]);
        $ticket->update(['observation_id' => $observation->id]);

        return [$ticket->fresh(), $observation];
    }

    public function test_ticket_json_carries_source_and_observation_id(): void
    {
        [$ticket, $observation] = $this->observationTicket();
        Sanctum::actingAs($this->recorder);

        $show = $this->getJson("/api/tickets/{$ticket->id}")->assertOk();
        $this->assertSame('observation', $show->json('ticket.source'));
        $this->assertSame($observation->id, $show->json('ticket.observation_id'));

        // The list shapes (same item()) carry them too; a checklist ticket has source=visit and no observation.
        $plain = Ticket::create([
            'reference' => 'OPE-0002', 'title' => 'لمبة', 'description' => 'x', 'branch_id' => $this->branch->id,
            'department_id' => $this->operation->id, 'created_by' => $this->recorder->id, 'status' => 'open', 'priority' => 'low',
        ]);
        $raised = $this->getJson('/api/tickets/raised')->assertOk();
        $byId = collect($raised->json('data') ?? $raised->json())->keyBy('id');
        $this->assertSame('observation', $byId[$ticket->id]['source']);
        $this->assertSame($observation->id, $byId[$ticket->id]['observation_id']);
        $this->assertNotSame('observation', $byId[$plain->id]['source'] ?? null);
        $this->assertNull($byId[$plain->id]['observation_id']);
    }

    public function test_evidence_upload_returns_the_relative_path_with_the_url(): void
    {
        Storage::fake('public');
        [$ticket] = $this->observationTicket();
        Sanctum::actingAs($this->recorder);

        $response = $this->post("/api/tickets/{$ticket->id}/evidence", [
            'photo' => UploadedFile::fake()->image('after.jpg'), 'kind' => 'after',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonStructure(['message', 'url', 'path']);

        $path = $response->json('path');
        $this->assertMatchesRegularExpression('#^evidence/tickets/'.$ticket->id.'/[^/]+\.jpg$#', $path);
        $this->assertSame(asset('storage/'.$path), $response->json('url'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_visit_items_carry_the_recorder_and_the_approver(): void
    {
        $manager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true, 'name' => 'مدير الكاميرات']);
        $visit = $this->visit(['status' => 'approved', 'submitted_at' => now(), 'approved_at' => now(), 'approved_by' => $manager->id]);
        Sanctum::actingAs($this->recorder);

        $list = $this->getJson('/api/observation-visits')->assertOk();
        $this->assertSame(['id' => $this->recorder->id, 'name' => $this->recorder->name], $list->json('data.0.user'));
        $this->assertSame(['id' => $manager->id, 'name' => 'مدير الكاميرات'], $list->json('data.0.approved_by'));

        $show = $this->getJson("/api/observation-visits/{$visit->id}")->assertOk();
        $this->assertSame(['id' => $this->recorder->id, 'name' => $this->recorder->name], $show->json('visit.user'));
        $this->assertSame(['id' => $manager->id, 'name' => 'مدير الكاميرات'], $show->json('visit.approved_by'));

        $draft = $this->visit();
        $this->assertNull($this->getJson("/api/observation-visits/{$draft->id}")->assertOk()->json('visit.approved_by'));
    }

    public function test_free_issue_point_defaults_to_the_departments_other_category_without_its_policy_defaults(): void
    {
        $other = ObservationCategory::create([
            'department_id' => $this->cctv->id, 'slug' => 'other', 'name_ar' => 'أخرى', 'name_en' => 'Other',
            'default_kind' => 'issue', 'default_risk_level_id' => $this->medium->id, 'default_responsible_department_id' => $this->operation->id, 'active' => true,
        ]);
        // Another department's "other" must never be picked.
        ObservationCategory::create([
            'department_id' => $this->operation->id, 'slug' => 'other', 'name_ar' => 'أخرى', 'name_en' => 'Other', 'default_kind' => 'issue', 'active' => true,
        ]);
        $visit = $this->visit();
        Sanctum::actingAs($this->recorder);

        $response = $this->postJson("/api/observation-visits/{$visit->id}/observations", ['items' => [
            ['kind' => 'issue', 'description' => 'باب المخزن مفتوح'],
            ['kind' => 'positive', 'description' => 'الفريق متعاون'],
            ['kind' => 'issue', 'description' => 'الكاشير لوحده', 'responsible_department_id' => $this->operation->id, 'risk_level_slug' => 'medium'],
        ]])->assertOk();

        // The free issue is labelled "other" but keeps the employee's (empty) routing: the manager classifies it.
        $this->assertSame($other->id, $response->json('data.0.category_id'));
        $this->assertSame('issue', $response->json('data.0.kind'));
        $this->assertNull($response->json('data.0.risk'));
        $this->assertNull($response->json('data.0.responsible_department'));
        $this->assertNull($response->json('data.0.catalog_item_id'));
        $this->assertSame('manual', $response->json('data.0.classification_status'));
        // Non-issue free points are not forced into a category.
        $this->assertNull($response->json('data.1.category_id'));
        $this->assertSame('positive', $response->json('data.1.kind'));
        // The employee's own routing on a free issue is kept.
        $this->assertSame($other->id, $response->json('data.2.category_id'));
        $this->assertSame('medium', $response->json('data.2.risk.slug'));
        $this->assertSame($this->operation->id, $response->json('data.2.responsible_department.id'));

        // Re-saving the same row (resumed draft) keeps "other"; a department without an "other" category gets null.
        $id = $response->json('data.0.id');
        $again = $this->postJson("/api/observation-visits/{$visit->id}/observations", ['items' => [
            ['id' => $id, 'kind' => 'issue', 'description' => 'باب المخزن مفتوح (معدّل)'],
        ]])->assertOk();
        $this->assertSame($other->id, $again->json('data.0.category_id'));

        $other->update(['slug' => 'misc']);
        $bare = $this->postJson("/api/observation-visits/{$visit->id}/observations", ['items' => [
            ['kind' => 'issue', 'description' => 'نقطة بدون فئة'],
        ]])->assertOk();
        $this->assertNull($bare->json('data.0.category_id'));
    }
}
