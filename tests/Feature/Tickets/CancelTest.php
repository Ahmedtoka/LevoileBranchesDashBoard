<?php

namespace Tests\Feature\Tickets;

use App\Events\TicketStatusChanged;
use App\Exceptions\TicketTransitionDenied;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Services\TicketService;
use Illuminate\Support\Facades\Event;
use Tests\Support\Seeds;
use Tests\TestCase;

class CancelTest extends TestCase
{
    private function ticket(string $status = 'in_progress'): Ticket
    {
        return Ticket::create([
            'reference' => 'TST-0001',
            'title' => 'Ticket',
            'branch_id' => Seeds::branch('Heliopolis')->id,
            'department_id' => Seeds::department('maintenance')->id,
            'created_by' => Seeds::user('store_manager')->id,
            'status' => $status,
            'priority' => 'medium',
        ]);
    }

    public function test_cancel_closes_without_after_evidence_and_fires_no_status_event(): void
    {
        Event::fake([TicketStatusChanged::class]);
        $ticket = $this->ticket();
        $ops = Seeds::user('ops_manager');

        $result = app(TicketService::class)->cancel($ticket, $ops, 'أُغلقت بدون إجراء: duplicate');

        $fresh = $ticket->fresh();
        $this->assertSame('closed', $result->status);
        $this->assertSame('closed', $fresh->status);
        $this->assertNotNull($fresh->closed_at);

        $rows = TicketUpdate::where('ticket_id', $ticket->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('cancelled', $rows[0]->action);
        $this->assertSame('in_progress', $rows[0]->from_status);
        $this->assertSame('closed', $rows[0]->to_status);
        $this->assertSame($ops->id, (int) $rows[0]->user_id);
        $this->assertSame('أُغلقت بدون إجراء: duplicate', $rows[0]->note);

        Event::assertNotDispatched(TicketStatusChanged::class);
    }

    public function test_cancel_requires_close_permission(): void
    {
        $ticket = $this->ticket();
        $outsider = Seeds::user('department_employee', ['department_id' => Seeds::department('cctv')->id]);

        try {
            app(TicketService::class)->cancel($ticket, $outsider, 'nope');
            $this->fail('Expected TicketTransitionDenied');
        } catch (TicketTransitionDenied $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertSame('in_progress', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->closed_at);
        $this->assertSame(0, TicketUpdate::where('ticket_id', $ticket->id)->count());
    }

    public function test_cancel_refuses_an_already_closed_ticket(): void
    {
        $ticket = $this->ticket('closed');

        try {
            app(TicketService::class)->cancel($ticket, Seeds::user('super_admin'), 'again');
            $this->fail('Expected TicketTransitionDenied');
        } catch (TicketTransitionDenied $e) {
            $this->assertSame(422, $e->status);
        }

        $this->assertSame(0, TicketUpdate::where('ticket_id', $ticket->id)->count());
    }
}
