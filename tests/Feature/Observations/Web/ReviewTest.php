<?php

namespace Tests\Feature\Observations\Web;

use App\Models\AiClassification;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use Tests\Support\Seeds;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    private Department $cctv;

    private Department $operation;

    private Department $maintenance;

    private Branch $branch;

    private User $employee;

    private RiskLevel $high;

    private RiskLevel $medium;

    private RiskLevel $low;

    private ObservationCategory $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation');
        $this->maintenance = Seeds::department('maintenance');
        $this->branch = Seeds::branch('Saraya Mall');
        $this->employee = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->high = RiskLevel::create(['slug' => 'high', 'name_ar' => 'عالية', 'name_en' => 'High', 'color' => '#f97316', 'sort_order' => 2, 'default_sla_hours' => 48, 'requires_field_verification' => true, 'ticket_priority' => 'high', 'active' => true]);
        $this->medium = RiskLevel::create(['slug' => 'medium', 'name_ar' => 'متوسطة', 'name_en' => 'Medium', 'color' => '#eab308', 'sort_order' => 3, 'default_sla_hours' => 72, 'requires_field_verification' => false, 'ticket_priority' => 'medium', 'active' => true]);
        $this->low = RiskLevel::create(['slug' => 'low', 'name_ar' => 'منخفضة', 'name_en' => 'Low', 'color' => '#22c55e', 'sort_order' => 4, 'default_sla_hours' => 168, 'requires_field_verification' => false, 'ticket_priority' => 'low', 'active' => true]);
        $this->cashier = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => 'cashier', 'name_ar' => 'الكاشير', 'name_en' => 'Cashier', 'default_kind' => 'issue', 'default_risk_level_id' => $this->high->id, 'default_responsible_department_id' => $this->operation->id, 'sort_order' => 1, 'active' => true]);
    }

    private function pending(array $attrs = [], ?array $suggested = null, ?Department $dept = null): Observation
    {
        $dept ??= $this->cctv;
        $visit = ObservationVisit::create(['type' => 'field', 'department_id' => $dept->id, 'branch_id' => $this->branch->id, 'user_id' => $this->employee->id, 'visit_date' => now()->toDateString(), 'source' => 'mobile', 'status' => 'submitted']);
        $o = Observation::create(array_merge([
            'observation_visit_id' => $visit->id, 'branch_id' => $this->branch->id, 'reporting_department_id' => $dept->id,
            'responsible_department_id' => $this->operation->id, 'category_id' => $this->cashier->id, 'kind' => 'issue',
            'risk_level_id' => $this->medium->id, 'title' => 'عد النقدية بمفرده', 'description' => 'الكاشير عد النقدية بمفرده بعد الإغلاق',
            'event_date' => now()->toDateString(), 'created_by' => $this->employee->id, 'status' => 'open',
            'classification_status' => 'ai_pending_review',
        ], $attrs));
        AiClassification::create([
            'observation_visit_id' => $visit->id, 'observation_id' => $o->id, 'request_hash' => sha1($o->description), 'input_text' => $o->description,
            'suggested' => $suggested ?? ['catalog_item_id' => null, 'category_id' => $this->cashier->id, 'kind' => 'issue', 'risk_level' => 'medium', 'responsible_department_id' => $this->operation->id, 'confidence' => 0.6, 'reason' => 'x'],
            'employee_final' => ['catalog_item_id' => null, 'category_id' => $this->cashier->id, 'kind' => 'issue', 'risk_level' => 'medium', 'responsible_department_id' => $this->operation->id],
            'status' => 'ok', 'model' => 'test',
        ]);

        return $o;
    }

    private function openTicket(Observation $o, string $status = 'assigned', ?User $assignee = null): Ticket
    {
        $ticket = Ticket::create([
            'reference' => 'OPE-'.$o->id, 'title' => $o->title, 'branch_id' => $o->branch_id, 'department_id' => $o->responsible_department_id,
            'status' => $status, 'priority' => 'medium', 'sla_hours' => 72, 'due_at' => now()->addHours(72), 'source' => 'observation',
            'observation_id' => $o->id, 'created_by' => $this->employee->id, 'assigned_to' => $assignee?->id, 'assigned_at' => $assignee ? now() : null,
        ]);
        $o->update(['ticket_id' => $ticket->id, 'status' => $assignee ? 'in_progress' : 'open']);

        return $ticket;
    }

    private function item(string $code = 'CCTV-CASH-ALONE', array $attrs = []): ObservationCatalogItem
    {
        return ObservationCatalogItem::create(array_merge([
            'category_id' => $this->cashier->id, 'code' => $code, 'label_ar' => 'عد النقدية بمفرده', 'label_en' => 'Counting cash alone', 'kind' => 'issue',
            'risk_level_id' => $this->high->id, 'responsible_department_id' => $this->operation->id, 'keywords' => ['نقدية'], 'extra_fields' => [], 'usage_count' => 4, 'sort_order' => 1, 'active' => true,
        ], $attrs));
    }

    public function test_queue_lists_only_pending_in_scope(): void
    {
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $mine = $this->pending(['title' => 'CCTV-PENDING']);
        $this->pending(['title' => 'CCTV-REVIEWED', 'classification_status' => 'ai_reviewed']);
        $this->pending(['title' => 'CCTV-CATALOG', 'classification_status' => 'catalog']);
        $other = $this->pending(['title' => 'STOCK-PENDING'], null, $stock);

        $manager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);
        $this->actingAs($manager)->get('/observations/review')
            ->assertOk()->assertSee('CCTV-PENDING')->assertDontSee('CCTV-REVIEWED')->assertDontSee('CCTV-CATALOG')->assertDontSee('STOCK-PENDING');

        $admin = Seeds::user('super_admin');
        $this->actingAs($admin)->get('/observations/review')->assertOk()->assertSee('CCTV-PENDING')->assertSee('STOCK-PENDING');
        $this->actingAs($admin)->get('/observations/review?department_id='.$stock->id)->assertOk()->assertDontSee('CCTV-PENDING')->assertSee('STOCK-PENDING');

        // Out of scope: list 403, and actions on another department's observation 403.
        $this->actingAs($this->employee)->get('/observations/review')->assertForbidden();
        $this->actingAs(Seeds::user('ops_manager'))->get('/observations/review')->assertForbidden();
        $this->actingAs($manager)->post('/observations/review/'.$other->id.'/approve')->assertForbidden();
        $this->assertSame('ai_pending_review', $other->fresh()->classification_status);
        $this->assertSame('ai_pending_review', $mine->fresh()->classification_status);
    }

    public function test_approve_sets_admin_final(): void
    {
        $o = $this->pending();
        $manager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);

        $this->actingAs($manager)->from('/observations/review')
            ->post('/observations/review/'.$o->id.'/approve')
            ->assertRedirect('/observations/review')->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertSame('ai_reviewed', $o->classification_status);
        $this->assertSame($manager->id, $o->classification_reviewed_by);
        $this->assertNotNull($o->classification_reviewed_at);
        $final = $o->classification->admin_final;
        $this->assertSame(['catalog_item_id' => null, 'category_id' => $this->cashier->id, 'kind' => 'issue', 'risk_level' => 'medium', 'responsible_department_id' => $this->operation->id], $final);

        // Approving twice is a no-op 422-free redirect; the row stays reviewed.
        $this->actingAs($manager)->post('/observations/review/'.$o->id.'/approve')->assertRedirect();
        $this->assertSame('ai_reviewed', $o->fresh()->classification_status);
    }

    public function test_update_reroutes_open_ticket(): void
    {
        $o = $this->pending();
        $tech = Seeds::user('department_employee', ['department_id' => $this->operation->id]);
        $ticket = $this->openTicket($o, 'assigned', $tech);
        $admin = Seeds::user('super_admin');
        $other = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => 'floor', 'name_ar' => 'الفلور', 'name_en' => 'Floor', 'default_kind' => 'issue', 'sort_order' => 2, 'active' => true]);

        $this->actingAs($admin)->from('/observations/review')
            ->put('/observations/review/'.$o->id, [
                'category_id' => $other->id,
                'risk_level_id' => $this->high->id,
                'responsible_department_id' => $this->maintenance->id,
                'cc_department_ids' => [$this->operation->id],
                'note' => 'الصيانة هي المسؤولة',
            ])
            ->assertRedirect('/observations/review')->assertSessionHasNoErrors();

        $o->refresh();
        $ticket->refresh();
        $this->assertSame($other->id, $o->category_id);
        $this->assertSame($this->high->id, $o->risk_level_id);
        $this->assertSame($this->maintenance->id, $o->responsible_department_id);
        $this->assertSame([$this->operation->id], $o->cc_department_ids);
        $this->assertSame('ai_reviewed', $o->classification_status);
        $this->assertSame('open', $o->status);
        $this->assertSame(['catalog_item_id' => null, 'category_id' => $other->id, 'kind' => 'issue', 'risk_level' => 'high', 'responsible_department_id' => $this->maintenance->id], $o->classification->admin_final);

        // Ticket moved to the new department, unassigned, back to open, with the new priority.
        $this->assertSame($this->maintenance->id, $ticket->department_id);
        $this->assertNull($ticket->assigned_to);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('high', $ticket->priority);
        $this->assertSame(48, (int) $ticket->sla_hours);
        $this->assertTrue(TicketUpdate::where('ticket_id', $ticket->id)->where('action', 'status_change')->where('note', 'like', '%إعادة توجيه%')->exists());
        $this->assertTrue(TicketUpdate::where('ticket_id', $ticket->id)->where('action', 'priority')->where('to_status', 'high')->exists());

        // Lowering the risk is allowed too and updates the ticket priority.
        $this->actingAs($admin)->put('/observations/review/'.$o->id, ['risk_level_id' => $this->low->id])->assertSessionHasNoErrors();
        $this->assertSame($this->low->id, $o->fresh()->risk_level_id);
        $this->assertSame('low', $ticket->fresh()->priority);

        // A category of another department is rejected.
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $foreign = ObservationCategory::create(['department_id' => $stock->id, 'slug' => 'x', 'name_ar' => 'x', 'name_en' => 'X', 'default_kind' => 'issue', 'sort_order' => 1, 'active' => true]);
        $this->actingAs($admin)->put('/observations/review/'.$o->id, ['category_id' => $foreign->id])->assertSessionHasErrors('category_id');

        // A closed ticket is left alone.
        $o2 = $this->pending();
        $t2 = $this->openTicket($o2, 'closed');
        $this->actingAs($admin)->put('/observations/review/'.$o2->id, ['responsible_department_id' => $this->maintenance->id, 'risk_level_id' => $this->high->id])->assertSessionHasNoErrors();
        $this->assertSame($this->maintenance->id, $o2->fresh()->responsible_department_id);
        $this->assertSame($this->operation->id, $t2->fresh()->department_id);
        $this->assertSame('medium', $t2->fresh()->priority);
    }

    public function test_merge_updates_observation_and_usage(): void
    {
        $o = $this->pending();
        $ticket = $this->openTicket($o, 'open');
        $item = $this->item();
        $manager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);

        $this->actingAs($manager)->from('/observations/review')
            ->post('/observations/review/'.$o->id.'/merge', ['catalog_item_id' => $item->id])
            ->assertRedirect('/observations/review')->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertSame($item->id, $o->catalog_item_id);
        $this->assertSame($this->cashier->id, $o->category_id);
        $this->assertSame($this->high->id, $o->risk_level_id, 'risk inherited from the item');
        $this->assertSame($this->operation->id, $o->responsible_department_id);
        $this->assertSame('catalog', $o->classification_status);
        $this->assertSame($manager->id, $o->classification_reviewed_by);
        $this->assertSame(5, (int) $item->fresh()->usage_count);
        $this->assertSame($item->id, $o->classification->admin_final['catalog_item_id']);
        $this->assertSame('high', $o->classification->admin_final['risk_level']);
        $this->assertSame('high', $ticket->fresh()->priority);

        // Merging again into the same item does not count twice.
        $this->actingAs($manager)->post('/observations/review/'.$o->id.'/merge', ['catalog_item_id' => $item->id])->assertRedirect();
        $this->assertSame(5, (int) $item->fresh()->usage_count);

        // Admin override of the item's risk; item of another department is rejected.
        $o2 = $this->pending();
        $this->actingAs($manager)->post('/observations/review/'.$o2->id.'/merge', ['catalog_item_id' => $item->id, 'risk_level_id' => $this->low->id])->assertSessionHasNoErrors();
        $this->assertSame($this->low->id, $o2->fresh()->risk_level_id);
        $this->assertSame(6, (int) $item->fresh()->usage_count);

        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $foreignCat = ObservationCategory::create(['department_id' => $stock->id, 'slug' => 'x', 'name_ar' => 'x', 'name_en' => 'X', 'default_kind' => 'issue', 'sort_order' => 1, 'active' => true]);
        $foreign = $this->item('STO-X', ['category_id' => $foreignCat->id]);
        $o3 = $this->pending();
        $this->actingAs($manager)->post('/observations/review/'.$o3->id.'/merge', ['catalog_item_id' => $foreign->id])->assertSessionHasErrors('catalog_item_id');
        $this->assertNull($o3->fresh()->catalog_item_id);
    }

    public function test_promote_creates_item_and_links(): void
    {
        $o = $this->pending();
        $manager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);

        $this->actingAs($manager)->from('/observations/review')
            ->post('/observations/review/'.$o->id.'/promote', [
                'category_id' => $this->cashier->id,
                'code' => 'cctv-cash-alone',
                'label_ar' => 'عد النقدية بمفرده',
                'label_en' => 'Counting cash alone',
                'keywords' => 'نقدية, عد',
                'kind' => 'issue',
                'risk_level_id' => $this->high->id,
                'responsible_department_id' => $this->operation->id,
                'sla_hours' => 24,
            ])
            ->assertRedirect('/observations/review')->assertSessionHasNoErrors();

        $item = ObservationCatalogItem::where('code', 'CCTV-CASH-ALONE')->firstOrFail();
        $this->assertSame($this->cashier->id, $item->category_id);
        $this->assertSame(['نقدية', 'عد'], $item->keywords);
        $this->assertSame(1, (int) $item->usage_count);
        $this->assertTrue($item->active);

        $o->refresh();
        $this->assertSame($item->id, $o->catalog_item_id);
        $this->assertSame('catalog', $o->classification_status);
        $this->assertSame($this->high->id, $o->risk_level_id);
        $this->assertSame($item->id, $o->classification->admin_final['catalog_item_id']);

        // Category of another department is rejected (a manager cannot create items there).
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $foreignCat = ObservationCategory::create(['department_id' => $stock->id, 'slug' => 'x', 'name_ar' => 'x', 'name_en' => 'X', 'default_kind' => 'issue', 'sort_order' => 1, 'active' => true]);
        $o2 = $this->pending();
        $this->actingAs($manager)->post('/observations/review/'.$o2->id.'/promote', [
            'category_id' => $foreignCat->id, 'code' => 'STO-NEW', 'label_ar' => 'x', 'label_en' => 'x', 'kind' => 'issue',
        ])->assertSessionHasErrors('category_id');
        $this->assertFalse(ObservationCatalogItem::where('code', 'STO-NEW')->exists());
    }

    public function test_bulk_merge_links_every_pending_observation(): void
    {
        $item = $this->item();
        $a = $this->pending([], ['catalog_item_id' => $item->id, 'category_id' => $this->cashier->id, 'kind' => 'issue', 'risk_level' => 'high', 'responsible_department_id' => $this->operation->id, 'confidence' => 0.9, 'reason' => 'x']);
        $b = $this->pending([], ['catalog_item_id' => $item->id, 'category_id' => $this->cashier->id, 'kind' => 'issue', 'risk_level' => 'high', 'responsible_department_id' => $this->operation->id, 'confidence' => 0.8, 'reason' => 'x']);
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $foreign = $this->pending([], null, $stock);
        $manager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);

        // The queue groups the two suggestions for the same item.
        $this->actingAs($manager)->get('/observations/review')->assertOk()->assertSee('CCTV-CASH-ALONE');

        $this->actingAs($manager)
            ->post('/observations/review/bulk-merge', ['catalog_item_id' => $item->id, 'observation_ids' => [$a->id, $b->id, $foreign->id]])
            ->assertForbidden();
        $this->assertNull($a->fresh()->catalog_item_id);

        $this->actingAs($manager)->from('/observations/review')
            ->post('/observations/review/bulk-merge', ['catalog_item_id' => $item->id, 'observation_ids' => [$a->id, $b->id]])
            ->assertRedirect('/observations/review')->assertSessionHasNoErrors();

        $this->assertSame($item->id, $a->fresh()->catalog_item_id);
        $this->assertSame($item->id, $b->fresh()->catalog_item_id);
        $this->assertSame('catalog', $b->fresh()->classification_status);
        $this->assertSame(6, (int) $item->fresh()->usage_count);
    }
}
