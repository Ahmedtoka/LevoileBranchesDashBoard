<?php

namespace Tests\Feature\Tickets;

use App\Events\TicketStatusChanged;
use App\Exceptions\TicketTransitionDenied;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Seeds;
use Tests\TestCase;

class CloseHardeningTest extends TestCase
{
    private int $seq = 0;

    private function service(): TicketService
    {
        return app(TicketService::class);
    }

    private function ticket(Branch $branch, Department $department, User $creator, string $status, array $attrs = []): Ticket
    {
        $this->seq++;

        return Ticket::create(array_merge([
            'reference' => 'TST-'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'title' => 'Ticket '.$this->seq,
            'branch_id' => $branch->id,
            'department_id' => $department->id,
            'created_by' => $creator->id,
            'status' => $status,
            'priority' => 'medium',
        ], $attrs));
    }

    private function withAfterEvidence(Ticket $ticket, User $user): Ticket
    {
        $this->service()->addEvidence($ticket, 'evidence/x.jpg', 'after', $user);

        return $ticket;
    }

    private function assertDenied(callable $fn, int $status): void
    {
        try {
            $fn();
        } catch (TicketTransitionDenied $e) {
            $this->assertSame($status, $e->status);
            $this->assertNotSame('', $e->getMessage());

            return;
        }

        $this->fail('Expected TicketTransitionDenied with status '.$status);
    }

    public function test_close_requires_after_evidence(): void
    {
        $maintenance = Seeds::department('maintenance');
        $branch = Seeds::branch('Heliopolis');
        $creator = Seeds::user('store_manager', ['branch_id' => $branch->id]);
        $ticket = $this->ticket($branch, $maintenance, $creator, 'waiting_approval');
        // A before photo is not enough.
        $this->service()->addEvidence($ticket, 'evidence/before.jpg', 'before', $creator);

        $this->assertDenied(fn () => $this->service()->changeStatus($ticket, 'closed', $creator), 422);

        $fresh = $ticket->fresh();
        $this->assertSame('waiting_approval', $fresh->status);
        $this->assertNull($fresh->closed_at);
        $this->assertSame(0, TicketUpdate::where('ticket_id', $ticket->id)->where('action', 'status_change')->count());

        // A system call (no actor) is held to the same evidence rule.
        $this->assertDenied(fn () => $this->service()->changeStatus($ticket, 'closed'), 422);
        $this->assertSame('waiting_approval', $ticket->fresh()->status);
    }

    public function test_close_denied_for_unrelated_user(): void
    {
        $maintenance = Seeds::department('maintenance');
        $it = Seeds::department('it');
        $branch = Seeds::branch('Heliopolis');
        $creator = Seeds::user('store_manager', ['branch_id' => $branch->id]);
        $outsider = Seeds::user('department_employee', ['department_id' => $it->id]);
        $ticket = $this->withAfterEvidence($this->ticket($branch, $maintenance, $creator, 'waiting_approval'), $creator);

        $this->assertDenied(fn () => $this->service()->changeStatus($ticket, 'closed', $outsider), 403);

        $this->assertSame('waiting_approval', $ticket->fresh()->status);
        $this->assertFalse($this->service()->canClose($ticket, $outsider));
    }

    public function test_store_manager_of_branch_can_close(): void
    {
        $maintenance = Seeds::department('maintenance');
        $branch = Seeds::branch('Heliopolis');
        $reporter = Seeds::user('area_manager');

        // Variant 1: users.branch_id = ticket branch with the store_manager role.
        $storeManager = Seeds::user('store_manager', ['branch_id' => $branch->id]);
        $ticket = $this->withAfterEvidence($this->ticket($branch, $maintenance, $reporter, 'waiting_approval'), $reporter);

        $this->service()->changeStatus($ticket, 'closed', $storeManager);

        $fresh = $ticket->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertNotNull($fresh->closed_at);

        // Variant 2: branches.manager_id = user, no users.branch_id link.
        $other = Seeds::branch('Maadi');
        $branchManager = Seeds::user('store_manager');
        $other->update(['manager_id' => $branchManager->id]);
        $second = $this->withAfterEvidence($this->ticket($other, $maintenance, $reporter, 'waiting_approval'), $reporter);

        $this->service()->changeStatus($second, 'closed', $branchManager);

        $this->assertSame('closed', $second->fresh()->status);
        $this->assertNotNull($second->fresh()->closed_at);

        // Contrast: the store manager of Heliopolis cannot close a Maadi ticket.
        $third = $this->withAfterEvidence($this->ticket($other, $maintenance, $reporter, 'waiting_approval'), $reporter);
        $this->assertDenied(fn () => $this->service()->changeStatus($third, 'closed', $storeManager), 403);
    }

    public function test_department_manager_can_close_own_department_ticket(): void
    {
        $maintenance = Seeds::department('maintenance');
        $it = Seeds::department('it');
        $branch = Seeds::branch('Heliopolis');
        $creator = Seeds::user('store_manager', ['branch_id' => $branch->id]);
        $ownManager = Seeds::user('department_manager', ['department_id' => $maintenance->id, 'is_department_manager' => true]);
        $otherManager = Seeds::user('department_manager', ['department_id' => $it->id, 'is_department_manager' => true]);

        $ticket = $this->withAfterEvidence($this->ticket($branch, $maintenance, $creator, 'waiting_approval'), $creator);
        $this->service()->changeStatus($ticket, 'closed', $ownManager);
        $this->assertSame('closed', $ticket->fresh()->status);

        $second = $this->withAfterEvidence($this->ticket($branch, $maintenance, $creator, 'waiting_approval'), $creator);
        $this->assertDenied(fn () => $this->service()->changeStatus($second, 'closed', $otherManager), 403);
        $this->assertSame('waiting_approval', $second->fresh()->status);
    }

    public function test_silent_create_skips_notifications_and_assignment(): void
    {
        $maintenance = Seeds::department('maintenance');
        $branch = Seeds::branch('Heliopolis');
        $creator = Seeds::user('area_manager');
        Seeds::user('department_manager', ['department_id' => $maintenance->id, 'is_department_manager' => true]);
        $tech = Seeds::user('department_employee', ['department_id' => $maintenance->id, 'is_department_manager' => false]);
        $tech->branches()->attach($branch->id);

        $attrs = [
            'title' => 'Broken lamp',
            'branch_id' => $branch->id,
            'department_id' => $maintenance->id,
            'created_by' => $creator->id,
            'priority' => 'medium',
        ];

        $silent = $this->service()->createManual($attrs, $creator->id, ['silent' => true]);

        $silent->refresh();
        $this->assertSame(0, Notification::count());
        $this->assertNull($silent->assigned_to);
        $this->assertSame('open', $silent->status);
        $this->assertSame(1, TicketUpdate::where('ticket_id', $silent->id)->count());
        $this->assertSame(1, TicketUpdate::where('ticket_id', $silent->id)->where('action', 'created')->count());

        // Contrast: the default create notifies the manager and auto-assigns the technician.
        $loud = $this->service()->createManual($attrs, $creator->id);

        $loud->refresh();
        $this->assertGreaterThan(0, Notification::where('ticket_id', $loud->id)->count());
        $this->assertSame($tech->id, $loud->assigned_to);
        $this->assertSame('assigned', $loud->status);
    }

    public function test_approve_endpoint_respects_transition(): void
    {
        $maintenance = Seeds::department('maintenance');
        $branch = Seeds::branch('Heliopolis');
        $creator = Seeds::user('store_manager', ['branch_id' => $branch->id]);
        Sanctum::actingAs($creator);

        // in_progress -> closed is not in the cycle.
        $working = $this->withAfterEvidence($this->ticket($branch, $maintenance, $creator, 'in_progress'), $creator);
        $this->postJson('/api/tickets/'.$working->id.'/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', 'انتقال غير مسموح في دورة الحالة.');
        $this->assertSame('in_progress', $working->fresh()->status);

        // waiting_approval with an after photo closes.
        $ready = $this->withAfterEvidence($this->ticket($branch, $maintenance, $creator, 'waiting_approval'), $creator);
        $this->postJson('/api/tickets/'.$ready->id.'/approve')
            ->assertOk()
            ->assertJson(['message' => 'Ticket approved and closed.']);
        $this->assertSame('closed', $ready->fresh()->status);
        $this->assertNotNull($ready->fresh()->closed_at);

        // waiting_approval without an after photo is refused.
        $noPhoto = $this->ticket($branch, $maintenance, $creator, 'waiting_approval');
        $this->postJson('/api/tickets/'.$noPhoto->id.'/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', dt('لا يمكن الإقفال بدون صورة بعد الإصلاح.', 'Cannot close without an after photo.'));
        $this->assertSame('waiting_approval', $noPhoto->fresh()->status);
    }

    public function test_area_manager_of_branch_can_close(): void
    {
        $maintenance = Seeds::department('maintenance');
        $creator = Seeds::user('store_manager');

        // (a) areas.manager_id of the branch's area, no branch_user row.
        $areaManager = Seeds::user('area_manager');
        $east = Area::create(['name' => 'East', 'slug' => 'east', 'manager_id' => $areaManager->id]);
        // The legacy `branches.area` text column is filled, as it is on live branches.
        $heliopolis = Seeds::branch('Heliopolis', ['area_id' => $east->id, 'area' => 'East']);
        $first = $this->withAfterEvidence($this->ticket($heliopolis, $maintenance, $creator, 'waiting_approval'), $creator);

        $this->service()->changeStatus($first, 'closed', $areaManager);
        $this->assertSame('closed', $first->fresh()->status);

        // (b) only a branch_user row for the branch.
        $coverer = Seeds::user('area_manager');
        $maadi = Seeds::branch('Maadi');
        $coverer->branches()->attach($maadi->id);
        $second = $this->withAfterEvidence($this->ticket($maadi, $maintenance, $creator, 'waiting_approval'), $creator);

        $this->service()->changeStatus($second, 'closed', $coverer);
        $this->assertSame('closed', $second->fresh()->status);

        // (c) area manager of a different area, no branch_user row: denied.
        $westManager = Seeds::user('area_manager');
        Area::create(['name' => 'West', 'slug' => 'west', 'manager_id' => $westManager->id]);
        $third = $this->withAfterEvidence($this->ticket($heliopolis, $maintenance, $creator, 'waiting_approval'), $creator);

        $this->assertDenied(fn () => $this->service()->changeStatus($third, 'closed', $westManager), 403);
        $this->assertSame('waiting_approval', $third->fresh()->status);
    }

    public function test_dept_status_endpoint_respects_transition(): void
    {
        $maintenance = Seeds::department('maintenance');
        $branch = Seeds::branch('Heliopolis');
        $creator = Seeds::user('store_manager', ['branch_id' => $branch->id]);
        $manager = Seeds::user('department_manager', ['department_id' => $maintenance->id, 'is_department_manager' => true]);
        Sanctum::actingAs($manager);

        $working = $this->withAfterEvidence($this->ticket($branch, $maintenance, $creator, 'in_progress'), $creator);
        $this->postJson('/api/departments/ticket/'.$working->id.'/status', ['status' => 'closed'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'انتقال غير مسموح في دورة الحالة.');
        $this->assertSame('in_progress', $working->fresh()->status);

        $this->postJson('/api/departments/ticket/'.$working->id.'/status', ['status' => 'waiting_approval'])
            ->assertOk()
            ->assertJsonPath('ticket.status', 'waiting_approval');
        $this->assertSame('waiting_approval', $working->fresh()->status);
    }

    public function test_web_transition_flashes_denial(): void
    {
        $maintenance = Seeds::department('maintenance');
        $branch = Seeds::branch('Heliopolis');
        $creator = Seeds::user('store_manager', ['branch_id' => $branch->id]);
        $ticket = $this->ticket($branch, $maintenance, $creator, 'waiting_approval');

        // The web SetLocale middleware defaults to Arabic.
        $denial = 'لا يمكن الإقفال بدون صورة بعد الإصلاح.';

        $this->actingAs($creator)
            ->from('/tickets/'.$ticket->id)
            ->post('/tickets/'.$ticket->id.'/transition', ['status' => 'closed'])
            ->assertRedirect('/tickets/'.$ticket->id)
            ->assertSessionHas('status', $denial)
            ->assertSessionHasErrors(['status' => $denial]);

        $this->assertSame('waiting_approval', $ticket->fresh()->status);
    }

    public function test_status_change_fires_event(): void
    {
        Event::fake([TicketStatusChanged::class]);

        $maintenance = Seeds::department('maintenance');
        $branch = Seeds::branch('Heliopolis');
        $creator = Seeds::user('store_manager', ['branch_id' => $branch->id]);
        $ticket = $this->ticket($branch, $maintenance, $creator, 'on_the_way');

        $this->service()->changeStatus($ticket, 'in_progress', $creator);

        Event::assertDispatched(TicketStatusChanged::class, fn (TicketStatusChanged $e) => $e->ticket->id === $ticket->id
            && $e->from === 'on_the_way'
            && $e->to === 'in_progress');
        Event::assertDispatchedTimes(TicketStatusChanged::class, 1);
    }
}
