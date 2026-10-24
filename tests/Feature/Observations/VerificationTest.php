<?php

namespace Tests\Feature\Observations;

use App\Events\TicketStatusChanged;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Observation;
use App\Models\ObservationPhoto;
use App\Models\ObservationVerification;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Services\Observations\ObservationService;
use App\Services\TicketService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\Seeds;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    private Department $cctv;

    private Department $operation;

    private Department $maintenance;

    private Branch $branch;

    private User $recorder;

    private User $operationManager;

    private User $maintenanceManager;

    private User $technician;

    private User $admin;

    /** @var array<string, RiskLevel> */
    private array $risks = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 10:00:00');

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation', ['is_reporting' => true]);
        $this->maintenance = Seeds::department('maintenance', ['is_reporting' => true]);

        $this->branch = Seeds::branch('Saraya Mall', ['manager_id' => Seeds::user('store_manager')->id]);
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->operationManager = Seeds::user('department_manager', ['department_id' => $this->operation->id, 'is_department_manager' => true]);
        $this->maintenanceManager = Seeds::user('department_manager', ['department_id' => $this->maintenance->id, 'is_department_manager' => true]);
        $this->technician = Seeds::user('department_employee', ['department_id' => $this->operation->id]);
        $this->technician->branches()->attach($this->branch->id);
        $this->admin = Seeds::user('ops_manager');

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

    private function tickets(): TicketService
    {
        return app(TicketService::class);
    }

    /** A submitted issue of the given risk, with one before photo and its (open, unassigned) ticket. */
    private function submitted(string $risk, bool $silent = true): Observation
    {
        $visit = ObservationVisit::create([
            'type' => 'field', 'department_id' => $this->cctv->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->recorder->id, 'visit_date' => now()->toDateString(), 'started_at' => now(),
            'status' => 'draft', 'source' => 'mobile',
        ]);
        $observation = Observation::create([
            'observation_visit_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'reporting_department_id' => $visit->department_id,
            'responsible_department_id' => $this->operation->id,
            'kind' => 'issue',
            'risk_level_id' => $this->risks[$risk]->id,
            'title' => 'باب الطوارئ مقفول',
            'description' => 'باب الطوارئ مقفول',
            'event_date' => $visit->visit_date,
            'status' => 'open',
            'classification_status' => 'manual',
            'created_by' => $this->recorder->id,
        ]);
        ObservationPhoto::create(['observation_id' => $observation->id, 'path' => 'obs/before.jpg', 'kind' => 'before', 'source' => 'camera', 'uploaded_by' => $this->recorder->id]);

        $this->service()->submitVisit($visit, $this->recorder, ['silent' => $silent]);
        $this->service()->approveVisit($visit->fresh(), $this->operationManager, ['silent' => $silent]);

        return $observation->fresh();
    }

    /** Technician uploads an after photo, the department manager approves (closes) the ticket. */
    private function approve(Ticket $ticket, string $path = 'tickets/after-1.jpg'): void
    {
        $this->tickets()->addEvidence($ticket, $path, 'after', $this->technician);
        $this->tickets()->changeStatus($ticket->fresh(), 'closed', $this->operationManager);
    }

    public function test_ticket_close_on_low_risk_closes_observation(): void
    {
        $this->assertCount(1, Event::getRawListeners()[TicketStatusChanged::class] ?? []);

        $observation = $this->submitted('medium');
        $this->assertSame('open', $observation->status);
        $ticket = $observation->ticket;

        $this->tickets()->assign($ticket, $this->technician, $this->operationManager);
        $this->assertSame('in_progress', $observation->fresh()->status);

        // A waiting-approval step does not move the observation.
        $this->tickets()->changeStatus($ticket->fresh(), 'waiting_approval', $this->technician);
        $this->assertSame('in_progress', $observation->fresh()->status);

        $this->approve($ticket);

        $observation->refresh();
        $this->assertSame('verified_closed', $observation->status);
        $verification = ObservationVerification::where('observation_id', $observation->id)->sole();
        $this->assertSame('resolved', $verification->result);
        $this->assertSame($this->operationManager->id, $verification->verified_by);
        $this->assertSame('اعتماد بصورة بعد', $verification->notes);
        $this->assertNotNull($verification->verified_at);
        $after = ObservationPhoto::where('observation_id', $observation->id)->where('kind', 'after')->sole();
        $this->assertSame('tickets/after-1.jpg', $after->path);
        $this->assertSame('gallery', $after->source);
        $this->assertSame($this->technician->id, $after->uploaded_by);

        // A ticket outside the module goes through the same event without touching anything.
        $plain = $this->tickets()->createManual(['title' => 'لمبة', 'branch_id' => $this->branch->id, 'department_id' => $this->operation->id, 'created_by' => $this->admin->id], $this->admin->id, ['silent' => true]);
        $this->tickets()->assign($plain, $this->technician, $this->admin);
        $this->assertSame('assigned', $plain->fresh()->status);
        $this->assertSame(1, ObservationVerification::count());

        // Submitting with auto-assignment: the assignment event runs before the ticket is linked, so the
        // status is derived from the saved ticket instead of being reset to open.
        $autoAssigned = $this->submitted('medium', silent: false);
        $this->assertSame('assigned', $autoAssigned->ticket->status);
        $this->assertSame($this->technician->id, $autoAssigned->ticket->assigned_to);
        $this->assertSame('in_progress', $autoAssigned->status);
    }

    public function test_ticket_close_on_high_risk_waits_for_verification(): void
    {
        $observation = $this->submitted('high');

        $this->approve($observation->ticket);

        $observation->refresh();
        $this->assertSame('waiting_verification', $observation->status);
        $this->assertSame(0, ObservationVerification::count());
        $this->assertSame(1, ObservationPhoto::where('observation_id', $observation->id)->where('kind', 'after')->where('path', 'tickets/after-1.jpg')->count());
        $this->assertSame(
            [$observation->id],
            $this->service()->pendingVerification($this->branch, $this->recorder)->pluck('id')->all()
        );
    }

    public function test_verify_resolved_closes(): void
    {
        $observation = $this->submitted('high');
        $this->approve($observation->ticket);

        $verification = $this->service()->verify($observation->fresh(), $this->recorder, 'resolved', 'تم الإصلاح فعلاً', 'obs/after-field.jpg');

        $this->assertInstanceOf(ObservationVerification::class, $verification);
        $this->assertSame('resolved', $verification->result);
        $this->assertSame($this->recorder->id, $verification->verified_by);
        $this->assertSame('verified_closed', $observation->fresh()->status);
        $photo = ObservationPhoto::where('observation_id', $observation->id)->where('path', 'obs/after-field.jpg')->sole();
        $this->assertSame('after', $photo->kind);
        $this->assertSame('camera', $photo->source);
        $this->assertSame($this->recorder->id, $photo->uploaded_by);

        // Only a waiting_verification row can be verified.
        $this->expectException(ValidationException::class);
        $this->service()->verify($observation->fresh(), $this->recorder, 'resolved');
    }

    public function test_verify_not_resolved_reopens_with_new_ticket(): void
    {
        $observation = $this->submitted('high');
        $first = $observation->ticket;
        $this->tickets()->assign($first, $this->technician, $this->operationManager);
        $this->approve($first);

        try {
            $this->service()->verify($observation->fresh(), $this->recorder, 'not_resolved', '  ');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('notes', $e->errors());
        }

        $partial = $this->service()->verify($observation->fresh(), $this->recorder, 'partially', 'نص المشكلة اتحل');
        $this->assertSame('partially', $partial->result);
        $this->assertSame('waiting_verification', $observation->fresh()->status);

        $this->service()->verify($observation->fresh(), $this->recorder, 'not_resolved', 'الباب لسه مقفول');

        $observation->refresh();
        $this->assertSame('reopened', $observation->status);
        $this->assertSame(1, $observation->repeat_count);
        $this->assertSame($this->risks['high']->id, $observation->risk_level_id);
        $this->assertSame(2, Ticket::where('observation_id', $observation->id)->count());
        $second = Ticket::findOrFail($observation->ticket_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($observation->id, $second->observation_id);
        $this->assertSame(1, (int) $second->reopen_count);
        $this->assertStringStartsWith('إعادة فتح: ', $second->title);
        $this->assertSame('high', $second->priority);
        $this->assertSame('closed', $first->fresh()->status);
        $this->assertSame('obs/before.jpg', TicketUpdate::where('ticket_id', $second->id)->where('evidence_kind', 'before')->value('evidence_path'));
        $this->assertSame(1, Notification::where('user_id', $this->operationManager->id)->where('type', 'ticket_new')->where('ticket_id', $second->id)->count());
        $this->assertSame(1, Notification::where('user_id', $this->technician->id)->where('type', 'observation_reopened')->where('ticket_id', $second->id)->count());
        // The new ticket was auto-assigned; the observation keeps its reopened status.
        $this->assertSame('assigned', $second->status);

        // A late status change on the old ticket does not move the observation.
        event(new TicketStatusChanged($first->fresh(), 'waiting_approval', 'closed'));
        $this->assertSame('reopened', $observation->fresh()->status);

        // Second failed fix: the third occurrence raises the risk one level.
        $this->approve($second, 'tickets/after-2.jpg');
        $this->assertSame('waiting_verification', $observation->fresh()->status);
        $this->service()->verify($observation->fresh(), $this->recorder, 'not_resolved', 'لسه');

        $observation->refresh();
        $this->assertSame(2, $observation->repeat_count);
        $this->assertSame($this->risks['critical']->id, $observation->risk_level_id);
        $this->assertSame($this->risks['high']->id, $observation->escalated_from_risk_level_id);
        $third = Ticket::findOrFail($observation->ticket_id);
        $this->assertSame(2, (int) $third->reopen_count);
        $this->assertSame('critical', $third->priority);
        $this->assertSame(24, (int) $third->sla_hours);
        $this->assertSame(3, $observation->tickets()->count());
    }

    public function test_verify_denied_for_other_department(): void
    {
        $observation = $this->submitted('high');
        $this->approve($observation->ticket);
        $outsider = Seeds::user('department_employee', ['department_id' => Seeds::department('hr', ['is_reporting' => true])->id]);

        try {
            $this->service()->verify($observation->fresh(), $outsider, 'resolved');
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(0, ObservationVerification::count());
        $this->assertSame('waiting_verification', $observation->fresh()->status);
    }

    public function test_original_dates_unchanged_after_reopen(): void
    {
        $observation = $this->submitted('high');
        $this->approve($observation->ticket);
        $eventDate = $observation->event_date->toDateString();
        $createdAt = $observation->created_at->toDateTimeString();

        Carbon::setTestNow('2026-11-05 15:00:00');
        $this->service()->verify($observation->fresh(), $this->recorder, 'not_resolved', 'لسه موجودة');

        $observation->refresh();
        $this->assertSame('reopened', $observation->status);
        $this->assertSame($eventDate, $observation->event_date->toDateString());
        $this->assertSame($createdAt, $observation->created_at->toDateTimeString());
        $this->assertSame('2026-10-22 10:00:00', $createdAt);
        $this->assertTrue($observation->ticket->due_at->equalTo(now()->addHours(48)));
    }

    public function test_reroute_moves_open_ticket(): void
    {
        $observation = $this->submitted('medium');
        $ticket = $observation->ticket;
        $this->tickets()->assign($ticket, $this->technician, $this->operationManager);
        $this->assertSame('in_progress', $observation->fresh()->status);

        try {
            $this->service()->reroute($observation->fresh(), 999999, $this->admin, 'x');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('responsible_department_id', $e->errors());
        }

        $result = $this->service()->reroute($observation->fresh(), $this->maintenance->id, $this->admin, 'مش تبع التشغيل');

        $this->assertSame($this->maintenance->id, $result->responsible_department_id);
        $this->assertSame('open', $result->status);
        $ticket->refresh();
        $this->assertSame($this->maintenance->id, $ticket->department_id);
        $this->assertNull($ticket->assigned_to);
        $this->assertNull($ticket->assigned_at);
        $this->assertSame('open', $ticket->status);
        $log = TicketUpdate::where('ticket_id', $ticket->id)->latest('id')->first();
        $this->assertSame('status_change', $log->action);
        $this->assertSame('assigned', $log->from_status);
        $this->assertSame('open', $log->to_status);
        $this->assertSame('إعادة توجيه: مش تبع التشغيل', $log->note);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(1, Notification::where('user_id', $this->maintenanceManager->id)->where('type', 'ticket_new')->where('ticket_id', $ticket->id)->count());
    }

    public function test_raise_risk_updates_ticket_priority_and_due(): void
    {
        $observation = $this->submitted('low');
        $ticket = $observation->ticket;
        $this->assertSame('low', $ticket->priority);

        Carbon::setTestNow('2026-10-22 13:00:00');

        foreach (['low', 'none'] as $notHigher) {
            try {
                $this->service()->raiseRisk($observation->fresh(), $this->risks[$notHigher], $this->admin, 'سبب');
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('risk_level_id', $e->errors());
            }
        }

        $result = $this->service()->raiseRisk($observation->fresh(), $this->risks['high'], $this->admin, 'خطر على العملاء');

        $this->assertSame($this->risks['high']->id, $result->risk_level_id);
        $this->assertSame('خطر على العملاء', $result->risk_override_reason);
        $ticket->refresh();
        $this->assertSame('high', $ticket->priority);
        $this->assertSame(48, (int) $ticket->sla_hours);
        $this->assertTrue($ticket->due_at->equalTo(Carbon::parse('2026-10-22 10:00:00')->addHours(48)));
        $log = TicketUpdate::where('ticket_id', $ticket->id)->where('action', 'priority')->sole();
        $this->assertSame('low', $log->from_status);
        $this->assertSame('high', $log->to_status);
        $this->assertSame('رفع درجة الريسك: خطر على العملاء', $log->note);
        $this->assertSame($this->admin->id, $log->user_id);
    }
}
