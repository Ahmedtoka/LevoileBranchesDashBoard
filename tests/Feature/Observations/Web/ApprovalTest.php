<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Seeds;
use Tests\TestCase;

/** Plan H task 5: /observations/visits/to-approve and the ticket page reply buttons. */
class ApprovalTest extends TestCase
{
    private Department $cctv;

    private Department $stock;

    private Department $operation;

    private Branch $branch;

    private User $recorder;

    private User $cctvManager;

    private User $stockManager;

    private User $ops;

    private RiskLevel $high;

    private RiskLevel $medium;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        config(['services.anthropic.key' => null, 'observations.manager_approval' => true]);

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation');
        $this->branch = Seeds::branch('Saraya Mall');
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->cctvManager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);
        $this->stockManager = Seeds::user('department_manager', ['department_id' => $this->stock->id, 'is_department_manager' => true]);
        $this->ops = Seeds::user('ops_manager');
        $this->high = RiskLevel::create(['slug' => 'high', 'name_ar' => 'عالية', 'name_en' => 'High', 'color' => '#f97316', 'sort_order' => 2, 'default_sla_hours' => 48, 'requires_field_verification' => true, 'ticket_priority' => 'high', 'active' => true]);
        $this->medium = RiskLevel::create(['slug' => 'medium', 'name_ar' => 'متوسطة', 'name_en' => 'Medium', 'color' => '#eab308', 'sort_order' => 3, 'default_sla_hours' => 72, 'requires_field_verification' => false, 'ticket_priority' => 'medium', 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function submitted(array $attrs = []): ObservationVisit
    {
        return ObservationVisit::create(array_merge([
            'type' => 'field', 'department_id' => $this->cctv->id, 'branch_id' => $this->branch->id, 'user_id' => $this->recorder->id,
            'visit_date' => now()->toDateString(), 'started_at' => now()->subHours(2), 'submitted_at' => now()->subHour(), 'status' => 'submitted', 'source' => 'mobile',
        ], $attrs));
    }

    private function observation(ObservationVisit $visit, array $attrs = []): Observation
    {
        return Observation::create(array_merge([
            'observation_visit_id' => $visit->id, 'branch_id' => $visit->branch_id, 'reporting_department_id' => $visit->department_id,
            'responsible_department_id' => $this->operation->id, 'kind' => 'issue', 'risk_level_id' => $this->medium->id,
            'title' => 'باب الطوارئ مقفول', 'description' => 'باب الطوارئ مقفول', 'event_date' => $visit->visit_date,
            'status' => 'open', 'classification_status' => 'manual', 'created_by' => $visit->user_id,
        ], $attrs));
    }

    public function test_list_shows_the_visits_the_user_approves_for(): void
    {
        $mine = $this->submitted();
        $this->observation($mine, ['title' => 'CCTV-POINT', 'description' => 'CCTV-POINT']);
        $other = $this->submitted(['department_id' => $this->stock->id, 'user_id' => $this->stockManager->id]);
        $this->observation($other, ['title' => 'STOCK-POINT', 'description' => 'STOCK-POINT']);
        $approved = $this->submitted(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->cctvManager->id]);

        $this->actingAs($this->cctvManager)->get('/observations/visits/to-approve')->assertOk()
            ->assertSee($mine->code)->assertDontSee($other->code)->assertDontSee($approved->code);
        $this->actingAs($this->ops)->get('/observations/visits/to-approve')->assertOk()->assertSee($mine->code)->assertSee($other->code);
        $this->actingAs($this->stockManager)->get('/observations/visits/to-approve')->assertOk()->assertDontSee($mine->code)->assertSee($other->code);

        $this->actingAs($this->recorder)->get('/observations/visits/to-approve')->assertForbidden();
        $hr = Seeds::department('hr', ['is_reporting' => false]);
        $this->actingAs(Seeds::user('department_manager', ['department_id' => $hr->id, 'is_department_manager' => true]))->get('/observations/visits/to-approve')->assertForbidden();

        // Visit page: own department only; an approved visit is still readable (its tickets).
        $this->actingAs($this->cctvManager)->get('/observations/visits/to-approve/'.$mine->id)->assertOk()->assertSee('CCTV-POINT');
        $this->actingAs($this->cctvManager)->get('/observations/visits/to-approve/'.$other->id)->assertForbidden();
        $this->actingAs($this->cctvManager)->get('/observations/visits/to-approve/'.$approved->id)->assertOk();
        $this->actingAs($this->recorder)->get('/observations/visits/to-approve/'.$mine->id)->assertForbidden();
    }

    public function test_sidebar_link_follows_the_approver_rule(): void
    {
        $hr = Seeds::department('hr', ['is_reporting' => false]);
        foreach ([$this->cctvManager, $this->ops, Seeds::user('super_admin')] as $user) {
            $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('/observations/visits/to-approve');
        }
        foreach ([$this->recorder, Seeds::user('department_manager', ['department_id' => $hr->id, 'is_department_manager' => true]), Seeds::user('store_manager')] as $user) {
            $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('/observations/visits/to-approve');
        }
    }

    public function test_classify_edit_and_approve_from_the_page(): void
    {
        $category = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => 'emergency', 'name_ar' => 'طوارئ', 'name_en' => 'Emergency', 'default_kind' => 'issue', 'active' => true]);
        $foreign = ObservationCategory::create(['department_id' => $this->stock->id, 'slug' => 'stock', 'name_ar' => 'مخزون', 'name_en' => 'Stock', 'default_kind' => 'issue', 'active' => true]);
        $visit = $this->submitted();
        $routed = $this->observation($visit);
        $free = $this->observation($visit, ['responsible_department_id' => null, 'risk_level_id' => null, 'title' => 'نقطة حرة', 'description' => 'نقطة حرة']);
        $page = '/observations/visits/to-approve/'.$visit->id;

        // "فحص" with the Null driver: flash, nothing changes.
        $this->actingAs($this->cctvManager)->from($page)->post($page.'/classify')->assertRedirect($page)
            ->assertSessionHas('status', 'الفحص غير متاح، اختر الفئة يدوياً');
        $this->assertNull($free->fresh()->responsible_department_id);

        // Approve with an unrouted issue: refused, errors flashed, no tickets.
        $this->actingAs($this->cctvManager)->from($page)->post($page.'/approve')->assertRedirect($page)
            ->assertSessionHasErrors(['items.1.responsible_department_id']);
        $this->assertSame(0, Ticket::count());
        $this->assertSame('submitted', $visit->fresh()->status);

        // Edit the point: foreign category refused, then a real correction.
        $this->actingAs($this->cctvManager)->from($page)->put($page.'/observations/'.$free->id, ['category_id' => $foreign->id])
            ->assertRedirect($page)->assertSessionHasErrors(['category_id']);
        $this->actingAs($this->cctvManager)->from($page)->put($page.'/observations/'.$free->id, [
            'responsible_department_id' => $this->operation->id, 'risk_level_slug' => 'high', 'category_id' => $category->id, 'description' => 'باب الطوارئ مقفول بجنزير', 'kind' => 'issue',
        ])->assertRedirect($page)->assertSessionHas('status');
        $free->refresh();
        $this->assertSame($this->operation->id, $free->responsible_department_id);
        $this->assertSame($this->high->id, $free->risk_level_id);
        $this->assertSame('باب الطوارئ مقفول بجنزير', $free->description);

        // Another department's manager and the recorder may not touch it.
        $this->actingAs($this->stockManager)->put($page.'/observations/'.$free->id, ['risk_level_slug' => 'medium'])->assertForbidden();
        $this->actingAs($this->recorder)->post($page.'/approve')->assertForbidden();

        // Approve: tickets created, visit approved.
        $this->actingAs($this->cctvManager)->from($page)->post($page.'/approve')->assertRedirect($page)->assertSessionHas('status');
        $this->assertSame('approved', $visit->fresh()->status);
        $this->assertSame($this->cctvManager->id, $visit->fresh()->approved_by);
        $this->assertSame(2, Ticket::count());
        $this->assertNotNull($routed->fresh()->ticket_id);
        $this->assertSame('high', Ticket::findOrFail($free->fresh()->ticket_id)->priority);

        // The page now lists the tickets; editing is over.
        $this->actingAs($this->cctvManager)->get($page)->assertOk()->assertSee(Ticket::first()->reference);
        $this->actingAs($this->cctvManager)->put($page.'/observations/'.$free->id, ['risk_level_slug' => 'medium'])->assertForbidden();
    }

    public function test_ticket_page_reply_buttons_for_the_responsible_department(): void
    {
        Storage::fake('public');
        $operationEmployee = Seeds::user('department_employee', ['department_id' => $this->operation->id]);
        $ticket = Ticket::create([
            'reference' => 'OPE-0001', 'title' => 'باب الطوارئ مقفول', 'branch_id' => $this->branch->id, 'department_id' => $this->operation->id,
            'created_by' => $this->recorder->id, 'status' => 'open', 'priority' => 'medium', 'source' => 'observation',
        ]);

        $this->actingAs($operationEmployee)->get('/tickets/'.$ticket->id)->assertOk()
            ->assertSee('/tickets/'.$ticket->id.'/reply')->assertSee('جاري العمل عليها')->assertSee('تم الحل');
        $this->actingAs($this->recorder)->get('/tickets/'.$ticket->id)->assertOk()->assertDontSee('/tickets/'.$ticket->id.'/reply');

        // Note required.
        $this->actingAs($operationEmployee)->from('/tickets/'.$ticket->id)
            ->post('/tickets/'.$ticket->id.'/reply', ['state' => 'working', 'note' => ''])
            ->assertRedirect('/tickets/'.$ticket->id)->assertSessionHasErrors(['note']);
        $this->assertSame('open', $ticket->fresh()->status);

        // Other department: 403.
        $this->actingAs($this->recorder)->post('/tickets/'.$ticket->id.'/reply', ['state' => 'working', 'note' => 'مش إدارتي'])->assertForbidden();

        $this->actingAs($operationEmployee)->from('/tickets/'.$ticket->id)
            ->post('/tickets/'.$ticket->id.'/reply', ['state' => 'working', 'note' => 'بدأنا الشغل'])
            ->assertRedirect('/tickets/'.$ticket->id)->assertSessionHas('status');
        $ticket->refresh();
        $this->assertSame('in_progress', $ticket->status);
        $this->assertSame($operationEmployee->id, $ticket->assigned_to);

        $this->actingAs($operationEmployee)->from('/tickets/'.$ticket->id)
            ->post('/tickets/'.$ticket->id.'/reply', ['state' => 'solved', 'note' => 'تم التصليح', 'photo' => UploadedFile::fake()->image('after.jpg')])
            ->assertRedirect('/tickets/'.$ticket->id)->assertSessionHas('status');
        $ticket->refresh();
        $this->assertSame('waiting_approval', $ticket->status);
        $evidence = TicketUpdate::where('ticket_id', $ticket->id)->where('evidence_kind', 'after')->sole();
        $this->assertStringStartsWith('evidence/tickets/'.$ticket->id.'/', $evidence->evidence_path);
        Storage::disk('public')->assertExists($evidence->evidence_path);

        // Finished: the buttons are gone.
        $this->actingAs($operationEmployee)->get('/tickets/'.$ticket->id)->assertOk()->assertDontSee('/tickets/'.$ticket->id.'/reply');
    }
}
