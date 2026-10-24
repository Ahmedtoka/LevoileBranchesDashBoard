<?php

namespace Tests\Feature\Tickets;

use App\Exceptions\TicketTransitionDenied;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Seeds;
use Tests\TestCase;

/** Plan H task 4: the responsible department answers a ticket with "جاري العمل عليها" or "تم الحل" plus a note. */
class ReplyTest extends TestCase
{
    private Department $operation;

    private Department $maintenance;

    private Branch $branch;

    private User $recorder;

    private User $operationManager;

    private User $operationEmployee;

    private User $maintenanceEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation');
        $this->maintenance = Seeds::department('maintenance');
        $this->branch = Seeds::branch('Saraya Mall');
        $this->recorder = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $this->operationManager = Seeds::user('department_manager', ['department_id' => $this->operation->id, 'is_department_manager' => true]);
        $this->operationEmployee = Seeds::user('department_employee', ['department_id' => $this->operation->id]);
        $this->maintenanceEmployee = Seeds::user('department_employee', ['department_id' => $this->maintenance->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): TicketService
    {
        return app(TicketService::class);
    }

    private function ticket(array $attrs = [], bool $withObservation = true): Ticket
    {
        $ticket = Ticket::create(array_merge([
            'reference' => 'OPE-'.str_pad((string) (Ticket::count() + 1), 4, '0', STR_PAD_LEFT), 'title' => 'باب الطوارئ مقفول', 'description' => 'x',
            'branch_id' => $this->branch->id, 'department_id' => $this->operation->id, 'created_by' => $this->recorder->id,
            'status' => 'open', 'priority' => 'medium', 'sla_hours' => 72, 'due_at' => now()->addHours(72), 'source' => 'observation',
        ], $attrs));

        if ($withObservation) {
            $visit = ObservationVisit::create(['type' => 'field', 'department_id' => $this->recorder->department_id, 'branch_id' => $this->branch->id, 'user_id' => $this->recorder->id, 'visit_date' => now()->toDateString(), 'source' => 'mobile', 'status' => 'approved', 'submitted_at' => now(), 'approved_at' => now(), 'approved_by' => $this->recorder->id]);
            $o = Observation::create([
                'observation_visit_id' => $visit->id, 'branch_id' => $this->branch->id, 'reporting_department_id' => $this->recorder->department_id,
                'responsible_department_id' => $this->operation->id, 'kind' => 'issue', 'title' => $ticket->title, 'description' => $ticket->title,
                'event_date' => now()->toDateString(), 'status' => 'open', 'classification_status' => 'manual', 'created_by' => $this->recorder->id, 'ticket_id' => $ticket->id,
            ]);
            $ticket->update(['observation_id' => $o->id]);
        }

        return $ticket->fresh();
    }

    public function test_working_assigns_the_actor_and_walks_the_chain_in_one_call(): void
    {
        $ticket = $this->ticket();

        $result = $this->service()->reply($ticket, $this->operationEmployee, 'working', 'بدأنا الشغل عليها');

        $this->assertSame('in_progress', $result->status);
        $this->assertSame($this->operationEmployee->id, $result->assigned_to);
        $this->assertNotNull($result->assigned_at);
        $chain = TicketUpdate::where('ticket_id', $ticket->id)->orderBy('id')->get();
        $this->assertSame(
            [['open', 'assigned'], ['assigned', 'on_the_way'], ['on_the_way', 'in_progress']],
            $chain->map(fn ($u) => [$u->from_status, $u->to_status])->all()
        );
        $this->assertSame('بدأنا الشغل عليها', $chain->last()->note);
        $this->assertSame($this->operationEmployee->id, $chain->last()->user_id);
        // The observation follows its ticket (SyncObservationFromTicket).
        $this->assertSame('in_progress', Observation::where('ticket_id', $ticket->id)->value('status'));

        // A second "working" only adds the note; the assignee stays.
        $again = $this->service()->reply($result->fresh(), $this->operationManager, 'working', 'لسه شغالين');
        $this->assertSame('in_progress', $again->status);
        $this->assertSame($this->operationEmployee->id, $again->assigned_to);
        $this->assertSame('لسه شغالين', TicketUpdate::where('ticket_id', $ticket->id)->latest('id')->value('note'));
        $this->assertSame(4, TicketUpdate::where('ticket_id', $ticket->id)->count());
    }

    public function test_working_from_an_assigned_or_postponed_ticket_keeps_the_assignee(): void
    {
        $assigned = $this->ticket(['status' => 'assigned', 'assigned_to' => $this->operationEmployee->id, 'assigned_at' => now()], false);
        $result = $this->service()->reply($assigned, $this->operationManager, 'working', 'المدير بيتابع');
        $this->assertSame('in_progress', $result->status);
        $this->assertSame($this->operationEmployee->id, $result->assigned_to);
        $this->assertSame(['on_the_way', 'in_progress'], TicketUpdate::where('ticket_id', $assigned->id)->orderBy('id')->pluck('to_status')->all(), 'already assigned: no new assignment row');

        $postponed = $this->ticket(['status' => 'postponed', 'assigned_to' => $this->operationEmployee->id], false);
        $result = $this->service()->reply($postponed, $this->operationEmployee, 'working', 'رجعنا نشتغل');
        $this->assertSame('in_progress', $result->status);
        $this->assertSame([['postponed', 'in_progress']], TicketUpdate::where('ticket_id', $postponed->id)->get()->map(fn ($u) => [$u->from_status, $u->to_status])->all());
    }

    public function test_solved_sends_for_approval_with_the_after_photo(): void
    {
        $ticket = $this->ticket();

        $result = $this->service()->reply($ticket, $this->operationEmployee, 'solved', 'تم تركيب الباب', 'evidence/tickets/1/after.jpg');

        $this->assertSame('waiting_approval', $result->status);
        $this->assertNotNull($result->resolved_at);
        $this->assertSame($this->operationEmployee->id, $result->assigned_to);
        $updates = TicketUpdate::where('ticket_id', $ticket->id)->orderBy('id')->get();
        $this->assertSame(['assigned', 'on_the_way', 'in_progress', null, 'waiting_approval'], $updates->pluck('to_status')->all());
        $evidence = $updates->firstWhere('action', 'evidence');
        $this->assertSame('after', $evidence->evidence_kind);
        $this->assertSame('evidence/tickets/1/after.jpg', $evidence->evidence_path);
        $this->assertSame('تم تركيب الباب', $updates->last()->note);
        $this->assertSame(1, Notification::where('user_id', $this->recorder->id)->where('type', 'ticket_approval')->where('ticket_id', $ticket->id)->count());

        // The approver can now close it (after evidence present).
        $this->service()->changeStatus($result->fresh(), 'closed', $this->recorder);
        $this->assertSame('closed', $ticket->fresh()->status);
    }

    public function test_solved_without_photo_and_photo_already_uploaded_is_not_duplicated(): void
    {
        $plain = $this->ticket([], false);
        $result = $this->service()->reply($plain, $this->operationManager, 'solved', 'اتحلت بدون صورة');
        $this->assertSame('waiting_approval', $result->status);
        $this->assertSame(0, TicketUpdate::where('ticket_id', $plain->id)->where('action', 'evidence')->count());

        $uploaded = $this->ticket(['status' => 'in_progress', 'assigned_to' => $this->operationEmployee->id], false);
        $this->service()->addEvidence($uploaded, 'evidence/tickets/2/after.jpg', 'after', $this->operationEmployee);
        $result = $this->service()->reply($uploaded->fresh(), $this->operationEmployee, 'solved', 'الصورة مرفوعة قبل كده', 'evidence/tickets/2/after.jpg');
        $this->assertSame('waiting_approval', $result->status);
        $this->assertSame(1, TicketUpdate::where('ticket_id', $uploaded->id)->where('evidence_kind', 'after')->count());
    }

    public function test_note_is_required_and_state_validated(): void
    {
        $ticket = $this->ticket([], false);

        foreach (['', '  ', 'ok'] as $bad) {
            try {
                $this->service()->reply($ticket, $this->operationEmployee, 'working', $bad);
                $this->fail('Expected ValidationException for note "'.$bad.'"');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('note', $e->errors());
            }
        }
        try {
            $this->service()->reply($ticket, $this->operationEmployee, 'done', 'ملاحظة صحيحة');
            $this->fail('Expected ValidationException for state');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('state', $e->errors());
        }
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertSame(0, TicketUpdate::count());
    }

    public function test_other_departments_are_refused_and_finished_tickets_cannot_be_replied(): void
    {
        $ticket = $this->ticket([], false);

        $this->assertFalse($this->service()->canReply($ticket, $this->maintenanceEmployee));
        $this->assertFalse($this->service()->canReply($ticket, $this->recorder));
        $this->assertTrue($this->service()->canReply($ticket, $this->operationEmployee));
        $this->assertTrue($this->service()->canReply($ticket, $this->operationManager));
        $this->assertTrue($this->service()->canReply($ticket, Seeds::user('ops_manager')));

        try {
            $this->service()->reply($ticket, $this->maintenanceEmployee, 'working', 'مش إدارتي');
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
        }
        $this->assertSame('open', $ticket->fresh()->status);

        foreach (['waiting_approval', 'closed'] as $status) {
            $finished = $this->ticket(['status' => $status], false);
            try {
                $this->service()->reply($finished, $this->operationManager, 'working', 'محاولة');
                $this->fail('Expected TicketTransitionDenied from '.$status);
            } catch (TicketTransitionDenied $e) {
                $this->assertSame(422, $e->status);
            }
            $this->assertSame($status, $finished->fresh()->status);
        }
    }

    public function test_reply_endpoint(): void
    {
        $ticket = $this->ticket();

        Sanctum::actingAs($this->maintenanceEmployee);
        $this->postJson("/api/tickets/{$ticket->id}/reply", ['state' => 'working', 'note' => 'مش إدارتي'])->assertForbidden();

        Sanctum::actingAs($this->operationEmployee);
        $this->postJson("/api/tickets/{$ticket->id}/reply", ['state' => 'working'])->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->postJson("/api/tickets/{$ticket->id}/reply", ['state' => 'fixed', 'note' => 'ملاحظة'])->assertUnprocessable()->assertJsonValidationErrors(['state']);
        $this->postJson("/api/tickets/{$ticket->id}/reply", ['state' => 'working', 'note' => 'ab'])->assertUnprocessable()->assertJsonValidationErrors(['note']);

        $working = $this->postJson("/api/tickets/{$ticket->id}/reply", ['state' => 'working', 'note' => 'جاري العمل'])->assertOk()
            ->assertJsonStructure(['message', 'ticket' => ['id', 'reference', 'status', 'assignee', 'assigned_to']]);
        $this->assertSame('in_progress', $working->json('ticket.status'));
        $this->assertSame($this->operationEmployee->id, $working->json('ticket.assigned_to'));

        $solved = $this->postJson("/api/tickets/{$ticket->id}/reply", ['state' => 'solved', 'note' => 'تم الحل', 'photo_path' => 'evidence/tickets/9/after.jpg'])->assertOk();
        $this->assertSame('waiting_approval', $solved->json('ticket.status'));
        $this->assertSame(1, TicketUpdate::where('ticket_id', $ticket->id)->where('evidence_kind', 'after')->count());

        $this->postJson("/api/tickets/{$ticket->id}/reply", ['state' => 'working', 'note' => 'تاني'])->assertUnprocessable();
        $this->assertSame('in_progress', Observation::where('ticket_id', $ticket->id)->value('status'), 'the observation only moves on close');
    }
}
