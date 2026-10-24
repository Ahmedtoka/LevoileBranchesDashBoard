<?php

namespace App\Listeners;

use App\Events\TicketStatusChanged;
use App\Services\Observations\ObservationService;
use Illuminate\Support\Facades\Log;

/** Moves an observation along with its current ticket (spec section 7). Never breaks the ticket flow. */
class SyncObservationFromTicket
{
    public function handle(TicketStatusChanged $event): void
    {
        try {
            app(ObservationService::class)->syncFromTicket($event->ticket, $event->from, $event->to);
        } catch (\Throwable $e) {
            Log::error('Observation sync from ticket failed', [
                'ticket_id' => $event->ticket->id,
                'from' => $event->from,
                'to' => $event->to,
                'exception' => $e,
            ]);
        }
    }
}
