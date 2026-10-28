<?php

namespace Tests\Feature\Observations;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Seeds;
use Tests\TestCase;

/** 2026-10-29: GET /api/branch/tickets — the store manager's Tickets tab in the simplified app. */
class BranchTicketsApiTest extends TestCase
{
    public function test_store_manager_lists_every_ticket_of_the_branches_they_manage(): void
    {
        $operation = Seeds::department('operation');
        $store = Seeds::user('store_manager');
        $own = Seeds::branch('Saraya Mall', ['manager_id' => $store->id]);
        $also = Seeds::branch('Arabia Mall');
        $store->update(['branch_id' => $also->id]);
        $other = Seeds::branch('City Stars');

        $make = fn (Branch $b, string $status, string $ref) => Ticket::create([
            'reference' => $ref, 'title' => $ref, 'branch_id' => $b->id, 'department_id' => $operation->id,
            'status' => $status, 'source' => 'observation', 'priority' => 'high',
        ]);
        $make($own, 'waiting_approval', 'T-WAIT');
        $make($own, 'closed', 'T-CLOSED');
        $make($also, 'open', 'T-OPEN');
        $make($other, 'waiting_approval', 'T-OTHER');

        Sanctum::actingAs($store);
        $all = $this->getJson('/api/branch/tickets?status=all')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['T-WAIT', 'T-CLOSED', 'T-OPEN'], array_column($all, 'reference'));

        $this->assertSame(['T-WAIT'], array_column($this->getJson('/api/branch/tickets?status=waiting_approval')->json('data'), 'reference'));
        $this->assertSame(['T-OPEN'], array_column($this->getJson('/api/branch/tickets?status=open')->json('data'), 'reference'));
        $this->assertSame(['T-CLOSED'], array_column($this->getJson('/api/branch/tickets?status=closed')->json('data'), 'reference'));

        // A user who manages no branch gets nothing (never another branch's tickets).
        Sanctum::actingAs(Seeds::user('department_employee', ['department_id' => $operation->id]));
        $this->getJson('/api/branch/tickets')->assertOk()->assertJsonCount(0, 'data');
    }
}
