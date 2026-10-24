<?php

namespace Tests\Feature\Observations\Web;

use App\Models\AiClassification;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationPhoto;
use App\Models\ObservationVerification;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use Tests\Support\Seeds;
use Tests\TestCase;

class DetailTest extends TestCase
{
    private Department $cctv;

    private Department $maintenance;

    private User $recorder;

    private User $ops;

    /** @var array<string, RiskLevel> */
    private array $risks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->maintenance = Seeds::department('maintenance');
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id, 'name' => 'Recorder Person']);
        $this->ops = Seeds::user('ops_manager');

        $levels = [['critical', 24, 'critical'], ['high', 48, 'high'], ['medium', 72, 'medium'], ['low', 168, 'low']];
        foreach ($levels as $i => [$slug, $sla, $priority]) {
            $this->risks[$slug] = RiskLevel::create([
                'slug' => $slug, 'name_ar' => 'ريسك '.$slug, 'name_en' => 'Risk '.$slug, 'color' => '#000000',
                'sort_order' => $i + 1, 'default_sla_hours' => $sla, 'ticket_priority' => $priority,
                'requires_field_verification' => $i < 2, 'active' => true,
            ]);
        }
    }

    private function observation(?Branch $branch, array $attrs = [], string $visitType = 'field'): Observation
    {
        $visit = ObservationVisit::create([
            'type' => $visitType,
            'department_id' => $this->cctv->id,
            'branch_id' => $branch?->id,
            'user_id' => $this->recorder->id,
            'visit_date' => now()->toDateString(),
            'source' => 'web',
        ]);

        return Observation::create(array_merge([
            'observation_visit_id' => $visit->id,
            'branch_id' => $branch?->id,
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

    private function ticketFor(Observation $observation, string $reference, array $attrs = []): Ticket
    {
        $ticket = Ticket::create(array_merge([
            'reference' => $reference,
            'title' => 'Ticket',
            'branch_id' => $observation->branch_id,
            'department_id' => $this->maintenance->id,
            'status' => 'open',
            'priority' => 'medium',
            'observation_id' => $observation->id,
            'created_by' => $this->recorder->id,
        ], $attrs));
        $observation->update(['ticket_id' => $ticket->id]);

        return $ticket;
    }

    public function test_show_activity_without_ticket(): void
    {
        $observation = $this->observation(null, [
            'kind' => 'activity',
            'status' => 'closed_no_action',
            'responsible_department_id' => null,
            'description' => 'Desk activity without branch',
        ], 'desk');

        $this->actingAs($this->ops)->get(route('observations.show', $observation))
            ->assertOk()
            ->assertSee($observation->reference)
            ->assertSee('Desk activity without branch')
            ->assertSee('Recorder Person')
            ->assertDontSee(route('observations.closeNoAction', $observation)) // already closed: no close form
            ->assertDontSee(route('observations.raiseRisk', $observation)); // no risk: no raise form

        $this->actingAs(Seeds::user('super_admin'))->get(route('observations.show', $observation))->assertOk();
        $this->actingAs(Seeds::user('sales'))->get(route('observations.show', $observation))->assertForbidden();
    }

    public function test_show_issue_with_ticket_photos_verifications_and_classification(): void
    {
        $branch = Seeds::branch('Heliopolis');
        $category = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => 'cams', 'name_ar' => 'كاميرات', 'name_en' => 'Cameras', 'default_kind' => 'issue', 'active' => true]);
        $item = ObservationCatalogItem::create([
            'category_id' => $category->id, 'code' => 'CAM-1', 'label_ar' => 'كاميرا عطلانة', 'label_en' => 'Broken camera', 'kind' => 'issue',
            'extra_fields' => [['key' => 'cam_no', 'label_ar' => 'رقم الكاميرا', 'label_en' => 'Camera number']],
            'active' => true,
        ]);
        $observation = $this->observation($branch, [
            'category_id' => $category->id, 'catalog_item_id' => $item->id,
            'risk_level_id' => $this->risks['high']->id, 'extra_values' => ['cam_no' => 'C-17'],
            'repeat_count' => 2, 'escalated_from_risk_level_id' => $this->risks['medium']->id,
        ]);
        $old = $this->ticketFor($observation, 'MAI-0001', ['status' => 'closed', 'closed_at' => now()->subDay()]);
        TicketUpdate::create(['ticket_id' => $old->id, 'action' => 'status_change', 'to_status' => 'closed', 'note' => 'Old ticket note']);
        $ticket = $this->ticketFor($observation, 'MAI-0002', ['due_at' => now()->subHour()]);
        TicketUpdate::create(['ticket_id' => $ticket->id, 'action' => 'created', 'to_status' => 'open', 'note' => 'Current ticket note']);
        ObservationPhoto::create(['observation_id' => $observation->id, 'path' => 'observations/before-1.jpg', 'kind' => 'before', 'uploaded_by' => $this->recorder->id]);
        ObservationPhoto::create(['observation_id' => $observation->id, 'path' => 'observations/after-1.jpg', 'kind' => 'after', 'uploaded_by' => $this->recorder->id]);
        ObservationVerification::create(['observation_id' => $observation->id, 'verified_by' => $this->ops->id, 'result' => 'not_resolved', 'notes' => 'Still broken', 'verified_at' => now()]);
        AiClassification::create([
            'observation_id' => $observation->id, 'request_hash' => sha1('x'), 'input_text' => 'cam broken', 'status' => 'ok',
            'suggested' => ['catalog_item_id' => $item->id, 'category_id' => $category->id, 'kind' => 'issue', 'risk_level' => 'medium', 'responsible_department_id' => $this->maintenance->id, 'confidence' => 0.82, 'reason' => 'Looks like a camera fault'],
            'employee_final' => ['catalog_item_id' => $item->id, 'category_id' => $category->id, 'kind' => 'issue', 'risk_level' => 'high', 'responsible_department_id' => $this->maintenance->id],
        ]);

        $this->actingAs($this->ops)->get(route('observations.show', $observation))
            ->assertOk()
            ->assertSee('Heliopolis')
            ->assertSee('كاميرا عطلانة')
            ->assertSee('رقم الكاميرا')->assertSee('C-17')
            ->assertSee('MAI-0001')->assertSee('MAI-0002')
            ->assertSee('Old ticket note')->assertSee('Current ticket note')
            ->assertSee(route('tickets.show', $ticket))
            ->assertSee('متأخر')
            ->assertSee(asset('storage/observations/before-1.jpg'))
            ->assertSee(asset('storage/observations/after-1.jpg'))
            ->assertSee('Still broken')
            ->assertSee('Looks like a camera fault')
            ->assertSee('ريسك medium')->assertSee($this->maintenance->name)
            ->assertSee(route('observations.reroute', $observation))
            ->assertSee(route('observations.raiseRisk', $observation))
            ->assertSee(route('observations.closeNoAction', $observation));

        // A recorder can view but gets no admin actions.
        $this->actingAs($this->recorder)->get(route('observations.show', $observation))
            ->assertOk()
            ->assertDontSee(route('observations.reroute', $observation))
            ->assertDontSee(route('observations.raiseRisk', $observation))
            ->assertDontSee(route('observations.closeNoAction', $observation));
    }

    public function test_close_no_action_requires_reason_and_cancels_ticket(): void
    {
        $observation = $this->observation(Seeds::branch('Heliopolis'), ['risk_level_id' => $this->risks['medium']->id]);
        $ticket = $this->ticketFor($observation, 'MAI-0001');
        $url = route('observations.closeNoAction', $observation);
        $show = route('observations.show', $observation);

        // Missing reason: validation error, nothing changes.
        $this->actingAs($this->ops)->from($show)->post($url, [])
            ->assertRedirect($show)
            ->assertSessionHasErrors('reason');
        $this->assertSame('open', $observation->fresh()->status);
        $this->assertSame('open', $ticket->fresh()->status);

        // Not allowed for a department employee, even with a reason.
        $this->actingAs($this->recorder)->post($url, ['reason' => 'Duplicate'])->assertForbidden();
        $this->assertSame('open', $observation->fresh()->status);

        $this->actingAs($this->ops)->from($show)->post($url, ['reason' => 'Duplicate entry'])
            ->assertRedirect($show)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $fresh = $observation->fresh();
        $this->assertSame('closed_no_action', $fresh->status);
        $this->assertSame('Duplicate entry', $fresh->close_reason);
        $this->assertSame($this->ops->id, (int) $fresh->closed_by);
        $this->assertNotNull($fresh->closed_at);
        $this->assertSame($this->ops->id, $fresh->closer->id);

        $ticket = $ticket->fresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->closed_at);
        $this->assertTrue(TicketUpdate::where('ticket_id', $ticket->id)
            ->where('action', 'cancelled')
            ->where('user_id', $this->ops->id)
            ->where('note', 'أُغلقت بدون إجراء: Duplicate entry')
            ->exists());

        // The page shows the closing reason and no longer offers the action.
        $this->actingAs($this->ops)->get($show)->assertOk()->assertSee('Duplicate entry')->assertDontSee($url);

        // Closing again is refused.
        $this->actingAs($this->ops)->from($show)->post($url, ['reason' => 'Again'])
            ->assertSessionHasErrors('status');
        $this->assertSame('Duplicate entry', $observation->fresh()->close_reason);
    }

    public function test_raise_risk_updates_ticket_priority(): void
    {
        $observation = $this->observation(Seeds::branch('Heliopolis'), ['risk_level_id' => $this->risks['medium']->id]);
        $ticket = $this->ticketFor($observation, 'MAI-0001');
        $url = route('observations.raiseRisk', $observation);
        $show = route('observations.show', $observation);

        // Reason missing: error, nothing changes.
        $this->actingAs($this->ops)->from($show)->post($url, ['risk_level_slug' => 'high'])
            ->assertSessionHasErrors('reason');
        $this->assertSame($this->risks['medium']->id, $observation->fresh()->risk_level_id);
        $this->assertSame('medium', $ticket->fresh()->priority);

        // A lower level is refused by the service.
        $this->actingAs($this->ops)->from($show)->post($url, ['risk_level_slug' => 'low', 'reason' => 'x'])
            ->assertSessionHasErrors();
        $this->assertSame($this->risks['medium']->id, $observation->fresh()->risk_level_id);

        $this->actingAs($this->recorder)->post($url, ['risk_level_slug' => 'high', 'reason' => 'Customer complaint'])->assertForbidden();

        $this->actingAs($this->ops)->from($show)->post($url, ['risk_level_slug' => 'high', 'reason' => 'Customer complaint'])
            ->assertRedirect($show)
            ->assertSessionHasNoErrors();

        $fresh = $observation->fresh();
        $this->assertSame($this->risks['high']->id, $fresh->risk_level_id);
        $this->assertSame('Customer complaint', $fresh->risk_override_reason);
        $this->assertSame('high', $ticket->fresh()->priority);
        $this->assertTrue(TicketUpdate::where('ticket_id', $ticket->id)
            ->where('action', 'priority')->where('from_status', 'medium')->where('to_status', 'high')
            ->exists());
    }

    public function test_reroute_moves_ticket_to_new_department(): void
    {
        $operation = Seeds::department('operation');
        $observation = $this->observation(Seeds::branch('Heliopolis'));
        $ticket = $this->ticketFor($observation, 'MAI-0001');
        $url = route('observations.reroute', $observation);
        $show = route('observations.show', $observation);

        $this->actingAs($this->ops)->from($show)->post($url, [])
            ->assertSessionHasErrors('responsible_department_id');
        $this->actingAs($this->recorder)->post($url, ['responsible_department_id' => $operation->id])->assertForbidden();

        $this->actingAs($this->ops)->from($show)
            ->post($url, ['responsible_department_id' => $operation->id, 'reason' => 'Wrong department'])
            ->assertRedirect($show)
            ->assertSessionHasNoErrors();

        $this->assertSame($operation->id, $observation->fresh()->responsible_department_id);
        $this->assertSame($operation->id, $ticket->fresh()->department_id);
        $this->assertTrue(TicketUpdate::where('ticket_id', $ticket->id)->where('note', 'إعادة توجيه: Wrong department')->exists());
    }
}
