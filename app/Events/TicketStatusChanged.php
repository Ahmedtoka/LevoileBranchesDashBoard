<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired by TicketService::changeStatus after every status change is saved and logged. */
class TicketStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public Ticket $ticket, public ?string $from, public string $to) {}
}
