<?php

namespace Tests\Feature\Observations;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationPhoto;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Services\Observations\ObservationService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\Seeds;
use Tests\TestCase;

/** Plan H task 2: submit = awaiting the manager (no tickets); approveVisit creates the tickets. */
class ApprovalServiceTest extends TestCase
{
    private Department $cctv;

    private Department $operation;

    private Branch $branch;

    private User $recorder;

    private User $cctvManager;

    private User $secondCctvManager;

    private User $operationManager;

    /** @var array<string, RiskLevel> */
    private array $risks = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        config(['observations.manager_approval' => true]);

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation', ['is_reporting' => true]);
        $this->branch = Seeds::branch('Saraya Mall');
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->cctvManager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);
        $this->secondCctvManager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);
        $this->operationManager = Seeds::user('department_manager', ['department_id' => $this->operation->id, 'is_department_manager' => true]);

        $levels = [['critical', 24, 'critical'], ['high', 48, 'high'], ['medium', 72, 'medium'], ['low', 168, 'low'], ['none', null, null]];
        foreach ($levels as $i => [$slug, $sla, $priority]) {
            $this->risks[$slug] = RiskLevel::create([
                'slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug, 'color' => '#000000',
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

    private function service(): ObservationService
    {
        return app(ObservationService::class);
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
            'title' => 'ملاحظة',
            'description' => 'ملاحظة',
            'event_date' => $visit->visit_date,
            'status' => 'open',
            'classification_status' => 'manual',
            'created_by' => $this->recorder->id,
        ], $attrs));
    }

    public function test_submit_marks_submitted_notifies_managers_and_creates_no_tickets(): void
    {
        $visit = $this->visit();
        $this->observation($visit);
        $this->observation($visit, ['responsible_department_id' => null]); // allowed at submit: the manager fills it in

        $result = $this->service()->submitVisit($visit, $this->recorder);

        $this->assertSame('submitted', $result['visit']->status);
        $this->assertSame([], $result['created_tickets']);
        $visit->refresh();
        $this->assertSame('submitted', $visit->status);
        $this->assertTrue($visit->submitted_at->equalTo(now()));
        $this->assertNull($visit->approved_at);
        $this->assertNull($visit->approved_by);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Observation::whereNotNull('ticket_id')->count());

        $notifications = Notification::where('type', 'observation_visit_submitted')->get();
        $this->assertEqualsCanonicalizing([$this->cctvManager->id, $this->secondCctvManager->id], $notifications->pluck('user_id')->all());
        $this->assertSame(2, Notification::count(), 'no ticket notifications at submit');
        $this->assertStringContainsString($visit->code, $notifications->first()->body);
        $this->assertSame('/observations/visits/to-approve/'.$visit->id, $notifications->first()->link);
    }

    public function test_submit_by_the_manager_does_not_notify_themself_and_silent_sends_nothing(): void
    {
        $own = $this->visit(['user_id' => $this->cctvManager->id]);
        $this->observation($own, ['created_by' => $this->cctvManager->id]);
        $this->service()->submitVisit($own, $this->cctvManager);
        $this->assertSame([$this->secondCctvManager->id], Notification::pluck('user_id')->all());

        $silent = $this->visit();
        $this->observation($silent);
        $this->service()->submitVisit($silent, $this->recorder, ['silent' => true]);
        $this->assertSame(1, Notification::count());
    }

    public function test_submit_is_idempotent_and_refuses_an_approved_visit_edit(): void
    {
        $visit = $this->visit();
        $this->observation($visit);

        $this->service()->submitVisit($visit, $this->recorder);
        $again = $this->service()->submitVisit($visit->fresh(), $this->recorder);

        $this->assertSame('submitted', $again['visit']->status);
        $this->assertSame([], $again['created_tickets']);
        $this->assertSame(2, Notification::count(), 'managers notified once');

        // A stale in-memory draft is caught by the locked re-read.
        $stale = $visit->fresh();
        $stale->status = 'draft';
        $this->service()->submitVisit($stale, $this->recorder);
        $this->assertSame(2, Notification::count());
    }

    public function test_approve_creates_tickets_exactly_as_the_old_submit_did(): void
    {
        $category = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => 'opening', 'name_ar' => 'فتح', 'name_en' => 'Opening', 'default_kind' => 'issue', 'active' => true]);
        $item = ObservationCatalogItem::create([
            'category_id' => $category->id, 'code' => 'CCTV-OPEN-LATE', 'label_ar' => 'تأخير فتح الفرع', 'label_en' => 'Late opening', 'kind' => 'issue',
            'risk_level_id' => $this->risks['high']->id, 'responsible_department_id' => $this->operation->id, 'keywords' => [], 'extra_fields' => [], 'active' => true,
        ]);
        $visit = $this->visit();
        $fromCatalog = $this->observation($visit, ['category_id' => $category->id, 'catalog_item_id' => $item->id, 'risk_level_id' => $this->risks['high']->id, 'classification_status' => 'catalog']);
        ObservationPhoto::create(['observation_id' => $fromCatalog->id, 'path' => 'obs/before.jpg', 'kind' => 'before', 'source' => 'camera', 'uploaded_by' => $this->recorder->id]);
        $free = $this->observation($visit, ['category_id' => $category->id, 'description' => 'نص حر', 'title' => 'نص حر']);
        $good = $this->observation($visit, ['kind' => 'assessment', 'rating' => 'good', 'responsible_department_id' => null]);
        $positive = $this->observation($visit, ['kind' => 'positive', 'status' => 'positive', 'responsible_department_id' => null]);

        $this->service()->submitVisit($visit, $this->recorder);
        Notification::query()->delete();

        $result = $this->service()->approveVisit($visit->fresh(), $this->cctvManager);

        $visit->refresh();
        $this->assertSame('approved', $result['visit']->status);
        $this->assertSame('approved', $visit->status);
        $this->assertSame($this->cctvManager->id, $visit->approved_by);
        $this->assertTrue($visit->approved_at->equalTo(now()));
        $this->assertCount(2, $result['created_tickets']);
        $this->assertSame(2, Ticket::count());

        $fromCatalog->refresh();
        $ticket = Ticket::findOrFail($fromCatalog->ticket_id);
        $this->assertSame(['observation_id' => $fromCatalog->id, 'ticket_id' => $ticket->id, 'reference' => $ticket->reference], $result['created_tickets'][0]);
        $this->assertSame('observation', $ticket->source);
        $this->assertSame($this->operation->id, $ticket->department_id);
        $this->assertSame('تأخير فتح الفرع', $ticket->title);
        $this->assertSame('high', $ticket->priority);
        $this->assertSame(48, (int) $ticket->sla_hours);
        $this->assertTrue($ticket->due_at->equalTo(now()->addHours(48)));
        $this->assertSame('CCTV-OPEN-LATE', $ticket->category);
        // The ticket is raised in the recorder's name (the observation's author), as before.
        $this->assertSame($this->recorder->id, $ticket->created_by);
        $this->assertSame(1, TicketUpdate::where('ticket_id', $ticket->id)->where('evidence_kind', 'before')->count());
        $this->assertSame(1, (int) $item->fresh()->usage_count);
        $this->assertSame('open', $fromCatalog->status);

        $this->assertNotNull($free->fresh()->ticket_id);
        $this->assertSame('medium', Ticket::findOrFail($free->fresh()->ticket_id)->priority);
        $this->assertSame('closed_no_action', $good->fresh()->status);
        $this->assertNull($good->fresh()->ticket_id);
        $this->assertSame('positive', $positive->fresh()->status);

        // The responsible department's manager is notified per ticket; nobody else for medium/high without area/branch manager.
        $this->assertSame(2, Notification::where('user_id', $this->operationManager->id)->where('type', 'ticket_new')->count());
    }

    public function test_approve_requires_a_responsible_department_on_every_issue(): void
    {
        $visit = $this->visit();
        $ok = $this->observation($visit);
        $this->observation($visit, ['responsible_department_id' => null]);
        $this->observation($visit, ['kind' => 'activity', 'status' => 'closed_no_action', 'responsible_department_id' => null]);
        $this->observation($visit, ['responsible_department_id' => null, 'branch_id' => null]);
        $this->service()->submitVisit($visit, $this->recorder, ['silent' => true]);

        try {
            $this->service()->approveVisit($visit->fresh(), $this->cctvManager);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['items.1.responsible_department_id', 'items.3.responsible_department_id', 'items.3.branch_id'],
                array_keys($e->errors())
            );
        }

        $this->assertSame(0, Ticket::count());
        $this->assertSame('submitted', $visit->fresh()->status);
        $this->assertNull($visit->fresh()->approved_at);
        $this->assertNull($ok->fresh()->ticket_id);
    }

    public function test_approve_refuses_a_draft_and_is_idempotent(): void
    {
        $visit = $this->visit();
        $this->observation($visit);

        try {
            $this->service()->approveVisit($visit, $this->cctvManager);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('visit', $e->errors());
        }
        $this->assertSame('draft', $visit->fresh()->status);

        $this->service()->submitVisit($visit, $this->recorder, ['silent' => true]);
        $first = $this->service()->approveVisit($visit->fresh(), $this->cctvManager);
        $tickets = Ticket::count();
        $notifications = Notification::count();

        $second = $this->service()->approveVisit($visit->fresh(), $this->secondCctvManager);

        $this->assertSame(1, $tickets);
        $this->assertSame($tickets, Ticket::count());
        $this->assertSame($notifications, Notification::count());
        $this->assertSame($first['created_tickets'], $second['created_tickets']);
        $this->assertSame($this->cctvManager->id, $visit->fresh()->approved_by, 'the first approver stays');

        // A stale in-memory `submitted` copy is caught by the locked re-read.
        $stale = $visit->fresh();
        $stale->status = 'submitted';
        $third = $this->service()->approveVisit($stale, $this->cctvManager);
        $this->assertSame($tickets, Ticket::count());
        $this->assertSame($first['created_tickets'], $third['created_tickets']);
    }

    public function test_approve_silent_and_due_from_options(): void
    {
        $visit = $this->visit();
        $observation = $this->observation($visit, ['risk_level_id' => $this->risks['critical']->id]);
        $this->service()->submitVisit($visit, $this->recorder, ['silent' => true]);

        $result = $this->service()->approveVisit($visit->fresh(), $this->cctvManager, ['silent' => true, 'due_from' => now()->subDay()]);

        $this->assertCount(1, $result['created_tickets']);
        $this->assertSame(0, Notification::count());
        $ticket = Ticket::findOrFail($observation->fresh()->ticket_id);
        $this->assertNull($ticket->assigned_to);
        $this->assertTrue($ticket->due_at->equalTo(now()->subDay()->addHours(24)));
    }

    public function test_flag_off_keeps_the_old_immediate_ticket_behaviour(): void
    {
        config(['observations.manager_approval' => false]);
        $visit = $this->visit();
        $observation = $this->observation($visit);

        $result = $this->service()->submitVisit($visit, $this->recorder);

        $visit->refresh();
        $this->assertSame('approved', $visit->status, 'tickets created, so the visit is approved at once');
        $this->assertSame($this->recorder->id, $visit->approved_by);
        $this->assertNotNull($visit->submitted_at);
        $this->assertCount(1, $result['created_tickets']);
        $this->assertSame($observation->fresh()->ticket_id, $result['created_tickets'][0]['ticket_id']);
        $this->assertSame(0, Notification::where('type', 'observation_visit_submitted')->count());

        // Missing responsible: the whole submit fails and the visit stays a draft (old behaviour).
        $bad = $this->visit();
        $this->observation($bad, ['responsible_department_id' => null]);
        try {
            $this->service()->submitVisit($bad, $this->recorder);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.responsible_department_id', $e->errors());
        }
        $this->assertSame('draft', $bad->fresh()->status);
        $this->assertNull($bad->fresh()->submitted_at);

        // approveVisit after a flag-off submit is a no-op.
        $again = $this->service()->approveVisit($visit->fresh(), $this->cctvManager);
        $this->assertSame($result['created_tickets'], $again['created_tickets']);
        $this->assertSame(1, Ticket::count());
    }
}
