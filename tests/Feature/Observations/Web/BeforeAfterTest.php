<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationPhoto;
use App\Models\ObservationVerification;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use Tests\Support\Seeds;
use Tests\TestCase;

class BeforeAfterTest extends TestCase
{
    private Department $cctv;

    private Department $maintenance;

    private User $recorder;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->maintenance = Seeds::department('maintenance');
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->ops = Seeds::user('ops_manager');
    }

    private function observation($branch, array $attrs = []): Observation
    {
        $visit = ObservationVisit::create([
            'type' => 'field', 'department_id' => $this->cctv->id, 'branch_id' => $branch->id,
            'user_id' => $this->recorder->id, 'visit_date' => now()->toDateString(), 'source' => 'web',
        ]);

        return Observation::create(array_merge([
            'observation_visit_id' => $visit->id,
            'branch_id' => $branch->id,
            'reporting_department_id' => $this->cctv->id,
            'responsible_department_id' => $this->maintenance->id,
            'kind' => 'issue',
            'title' => 'Observation',
            'description' => 'Observation description',
            'event_date' => now()->toDateString(),
            'created_by' => $this->recorder->id,
            'status' => 'open',
            'classification_status' => 'catalog',
        ], $attrs));
    }

    public function test_before_after_lists_branch_observations_with_photos(): void
    {
        $branch = Seeds::branch('Heliopolis');
        $other = Seeds::branch('Maadi');

        $shown = $this->observation($branch, ['status' => 'verified_closed', 'description' => 'Wall was dirty']);
        ObservationPhoto::create(['observation_id' => $shown->id, 'path' => 'observations/before-x.jpg', 'kind' => 'before', 'uploaded_by' => $this->recorder->id]);
        $ticket = Ticket::create([
            'reference' => 'MAI-0001', 'title' => 'Ticket', 'branch_id' => $branch->id, 'department_id' => $this->maintenance->id,
            'status' => 'closed', 'priority' => 'medium', 'observation_id' => $shown->id, 'created_by' => $this->recorder->id,
        ]);
        $shown->update(['ticket_id' => $ticket->id]);
        TicketUpdate::create(['ticket_id' => $ticket->id, 'action' => 'created', 'to_status' => 'open', 'note' => 'Older note']);
        TicketUpdate::create([
            'ticket_id' => $ticket->id, 'action' => 'status_change', 'to_status' => 'closed', 'note' => 'Wall was repainted',
            'evidence_path' => 'tickets/after-y.jpg', 'evidence_kind' => 'after', 'created_at' => now()->addMinute(),
        ]);
        ObservationVerification::create(['observation_id' => $shown->id, 'verified_by' => $this->ops->id, 'result' => 'resolved', 'notes' => 'Verified on site', 'verified_at' => now()]);

        $closed = $this->observation($branch, ['status' => 'closed_no_action']);
        $elsewhere = $this->observation($other);
        $bare = $this->observation($branch); // no ticket, no photos, no verification: must still render

        $response = $this->actingAs($this->ops)->get(route('observations.beforeAfter', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee($shown->reference)
            ->assertSee($bare->reference)
            ->assertSee('Wall was dirty')
            ->assertSee(asset('storage/observations/before-x.jpg'))
            ->assertSee(asset('storage/tickets/after-y.jpg'))
            ->assertSee('Wall was repainted')
            ->assertSee('Verified on site')
            ->assertDontSee($closed->reference)
            ->assertDontSee($elsewhere->reference);

        // The status filter is restricted to the allowed list and narrows the table.
        $this->actingAs($this->ops)->get(route('observations.beforeAfter', ['branch_id' => $branch->id, 'status' => 'open']))
            ->assertOk()->assertSee($bare->reference)->assertDontSee($shown->reference);
        $this->actingAs($this->ops)->get(route('observations.beforeAfter', ['branch_id' => $branch->id, 'status' => 'closed_no_action']))
            ->assertOk()->assertDontSee($closed->reference);

        // Without a branch: only the select and the hint.
        $this->actingAs($this->ops)->get(route('observations.beforeAfter'))
            ->assertOk()->assertDontSee($shown->reference);

        // A store manager of another branch cannot read this branch.
        $manager = Seeds::user('store_manager', ['branch_id' => $other->id]);
        $this->actingAs($manager)->get(route('observations.beforeAfter', ['branch_id' => $branch->id]))->assertForbidden();
        $this->actingAs($manager)->get(route('observations.beforeAfter', ['branch_id' => $other->id]))
            ->assertOk()->assertSee($elsewhere->reference)->assertDontSee($shown->reference);
    }
}
