<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired by TicketService::changeStatus, assign and declineByTechnician after the status change is saved, logged and notified. */
class TicketStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public Ticket $ticket, public ?string $from, public string $to) {}
}
