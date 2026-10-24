<?php

namespace Tests\Feature\Observations;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationPhoto;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Services\Observations\ObservationService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\Seeds;
use Tests\TestCase;

class ObservationSubmitTest extends TestCase
{
    private Department $cctv;

    private Department $operation;

    private Department $maintenance;

    private Branch $branch;

    private User $recorder;

    private User $branchManager;

    private User $operationManager;

    private User $maintenanceManager;

    /** @var array<string, RiskLevel> */
    private array $risks = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 10:00:00');

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation', ['is_reporting' => true]);
        $this->maintenance = Seeds::department('maintenance', ['is_reporting' => true]);

        $this->branchManager = Seeds::user('store_manager');
        $this->branch = Seeds::branch('Saraya Mall', ['manager_id' => $this->branchManager->id]);
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->operationManager = Seeds::user('department_manager', ['department_id' => $this->operation->id, 'is_department_manager' => true]);
        $this->maintenanceManager = Seeds::user('department_manager', ['department_id' => $this->maintenance->id, 'is_department_manager' => true]);

        $levels = [['critical', 24, 'critical'], ['high', 48, 'high'], ['medium', 72, 'medium'], ['low', 168, 'low'], ['none', null, null]];
        foreach ($levels as $i => [$slug, $sla, $priority]) {
            $this->risks[$slug] = RiskLevel::create([
                'slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug, 'color' => '#000000',
                'sort_order' => $i + 1, 'default_sla_hours' => $sla,
                'requires_field_verification' => in_array($slug, ['critical', 'high'], true),
                'ticket_priority' => $priority, 'active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function category(array $attrs = []): ObservationCategory
    {
        return ObservationCategory::create(array_merge([
            'department_id' => $this->cctv->id, 'slug' => 'cat-'.uniqid(), 'name_ar' => 'فئة', 'name_en' => 'Cat',
            'default_kind' => 'issue', 'active' => true,
        ], $attrs));
    }

    private function catalog(ObservationCategory $category, array $attrs = []): ObservationCatalogItem
    {
        return ObservationCatalogItem::create(array_merge([
            'category_id' => $category->id, 'code' => 'ITEM-'.uniqid(), 'label_ar' => 'بند', 'label_en' => 'Item',
            'keywords' => [], 'kind' => 'issue', 'extra_fields' => [], 'active' => true,
        ], $attrs));
    }

    private function visit(array $attrs = []): ObservationVisit
    {
        return ObservationVisit::create(array_merge([
            'type' => 'field', 'department_id' => $this->cctv->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->recorder->id, 'visit_date' => now()->toDateString(), 'started_at' => now(),
            'status' => 'draft', 'source' => 'mobile',
        ], $attrs));
    }

    private function observation(ObservationVisit $visit, array $attrs = []): Observation
    {
        return Observation::create(array_merge([
            'observation_visit_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'reporting_department_id' => $visit->department_id,
            'responsible_department_id' => $this->operation->id,
            'kind' => 'issue',
            'risk_level_id' => $this->risks['medium']->id,
            'title' => 'ملاحظة',
            'description' => 'ملاحظة',
            'event_date' => $visit->visit_date,
            'status' => 'open',
            'classification_status' => 'manual',
            'created_by' => $this->recorder->id,
        ], $attrs));
    }

    private function submit(ObservationVisit $visit, array $options = []): array
    {
        return (new ObservationService)->submitVisit($visit, $this->recorder, $options);
    }

    public function test_submit_creates_one_ticket_per_issue_with_priority_and_due(): void
    {
        $category = $this->category(['slug' => 'opening']);
        $item = $this->catalog($category, [
            'code' => 'CCTV-OPEN-LATE', 'label_ar' => 'تأخير فتح الفرع',
            'risk_level_id' => $this->risks['high']->id, 'responsible_department_id' => $this->operation->id,
            'extra_fields' => [['key' => 'minutes_late', 'type' => 'number', 'label_ar' => 'دقائق التأخير', 'label_en' => 'Minutes late']],
        ]);
        $visit = $this->visit();
        $fromCatalog = $this->observation($visit, [
            'category_id' => $category->id, 'catalog_item_id' => $item->id,
            'risk_level_id' => $this->risks['high']->id, 'classification_status' => 'catalog',
            'description' => 'الفرع فتح متأخر', 'extra_values' => ['minutes_late' => 45, 'note' => 'x'],
        ]);
        ObservationPhoto::create(['observation_id' => $fromCatalog->id, 'path' => 'obs/before.jpg', 'kind' => 'before', 'source' => 'camera', 'uploaded_by' => $this->recorder->id]);
        ObservationPhoto::create(['observation_id' => $fromCatalog->id, 'path' => 'obs/other.jpg', 'kind' => 'other', 'source' => 'camera', 'uploaded_by' => $this->recorder->id]);
        $longText = str_repeat('أ', 150);
        $free = $this->observation($visit, ['category_id' => $category->id, 'title' => '', 'description' => $longText]);

        $result = $this->submit($visit);

        $this->assertSame(2, Ticket::count());
        $this->assertCount(2, $result['created_tickets']);
        $this->assertSame('submitted', $result['visit']->status);
        $this->assertNotNull($result['visit']->submitted_at);
        $this->assertSame('submitted', $visit->fresh()->status);

        $fromCatalog->refresh();
        $ticket = Ticket::findOrFail($fromCatalog->ticket_id);
        $this->assertSame(['observation_id' => $fromCatalog->id, 'ticket_id' => $ticket->id, 'reference' => $ticket->reference], $result['created_tickets'][0]);
        $this->assertSame('open', $fromCatalog->status);
        $this->assertSame('observation', $ticket->source);
        $this->assertSame($fromCatalog->id, $ticket->observation_id);
        $this->assertSame($this->branch->id, $ticket->branch_id);
        $this->assertSame($this->operation->id, $ticket->department_id);
        $this->assertSame($this->recorder->id, $ticket->created_by);
        $this->assertSame('تأخير فتح الفرع', $ticket->title);
        $this->assertSame("الفرع فتح متأخر\n\nدقائق التأخير: 45\nnote: x", $ticket->description);
        $this->assertSame('high', $ticket->priority);
        $this->assertSame(48, (int) $ticket->sla_hours);
        $this->assertTrue($ticket->due_at->equalTo(now()->addHours(48)));
        $this->assertSame('CCTV-OPEN-LATE', $ticket->category);
        $this->assertSame(1, TicketUpdate::where('ticket_id', $ticket->id)->where('evidence_kind', 'before')->count());
        $this->assertSame('obs/before.jpg', TicketUpdate::where('ticket_id', $ticket->id)->where('evidence_kind', 'before')->value('evidence_path'));
        $this->assertSame(1, (int) $item->fresh()->usage_count);

        $freeTicket = Ticket::findOrFail($free->fresh()->ticket_id);
        $this->assertSame(mb_substr($longText, 0, 120), $freeTicket->title);
        $this->assertSame($longText, $freeTicket->description);
        $this->assertSame('medium', $freeTicket->priority);
        $this->assertSame(72, (int) $freeTicket->sla_hours);
        $this->assertTrue($freeTicket->due_at->equalTo(now()->addHours(72)));
        $this->assertSame('opening', $freeTicket->category);
    }

    public function test_submit_is_idempotent(): void
    {
        $visit = $this->visit();
        $observation = $this->observation($visit);

        $first = $this->submit($visit);
        $count = Ticket::count();
        $notifications = Notification::count();

        $second = $this->submit($visit->fresh());

        $this->assertSame(1, $count);
        $this->assertSame($count, Ticket::count());
        $this->assertSame($notifications, Notification::count());
        $this->assertSame($first['created_tickets'], $second['created_tickets']);
        $this->assertSame($observation->fresh()->ticket_id, $second['created_tickets'][0]['ticket_id']);
        $this->assertSame('submitted', $second['visit']->status);

        // A stale in-memory draft (submitted elsewhere meanwhile) is caught by the locked re-read.
        $stale = $visit->fresh();
        $stale->status = 'draft';
        $third = $this->submit($stale);
        $this->assertSame($count, Ticket::count());
        $this->assertSame($first['created_tickets'], $third['created_tickets']);
    }

    public function test_submit_rejects_issue_without_responsible_department(): void
    {
        $visit = $this->visit();
        $ok = $this->observation($visit);
        $this->observation($visit, ['responsible_department_id' => null]);
        $this->observation($visit, ['kind' => 'activity', 'status' => 'closed_no_action', 'responsible_department_id' => null]);
        $this->observation($visit, ['responsible_department_id' => null, 'branch_id' => null]);

        try {
            $this->submit($visit);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['items.1.responsible_department_id', 'items.3.responsible_department_id', 'items.3.branch_id'],
                array_keys($e->errors())
            );
        }

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Notification::count());
        $this->assertSame('draft', $visit->fresh()->status);
        $this->assertNull($ok->fresh()->ticket_id);
    }

    public function test_activity_and_positive_create_no_ticket(): void
    {
        $category = $this->category(['default_kind' => 'activity']);
        $item = $this->catalog($category, ['kind' => 'activity']);
        $visit = $this->visit();
        $activity = $this->observation($visit, ['kind' => 'activity', 'status' => 'closed_no_action', 'catalog_item_id' => $item->id, 'category_id' => $category->id]);
        $positive = $this->observation($visit, ['kind' => 'positive', 'status' => 'positive']);
        $good = $this->observation($visit, ['kind' => 'assessment', 'rating' => 'good', 'status' => 'open']);
        $average = $this->observation($visit, ['kind' => 'assessment', 'rating' => 'average', 'status' => 'open']);

        $result = $this->submit($visit);

        $this->assertSame([], $result['created_tickets']);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Notification::count());
        $this->assertSame('closed_no_action', $activity->fresh()->status);
        $this->assertSame('positive', $positive->fresh()->status);
        $this->assertSame('closed_no_action', $good->fresh()->status);
        $this->assertSame('assessment', $good->fresh()->kind);
        $this->assertSame('closed_no_action', $average->fresh()->status);
        $this->assertSame(1, (int) $item->fresh()->usage_count);
        $this->assertSame('submitted', $visit->fresh()->status);
    }

    public function test_poor_assessment_becomes_issue(): void
    {
        $category = $this->category(['default_kind' => 'assessment', 'default_risk_level_id' => $this->risks['low']->id]);
        $visit = $this->visit();
        $poor = $this->observation($visit, [
            'kind' => 'assessment', 'rating' => 'poor', 'category_id' => $category->id,
            'risk_level_id' => null, 'status' => 'open',
        ]);

        $result = $this->submit($visit);

        $poor->refresh();
        $this->assertCount(1, $result['created_tickets']);
        $this->assertSame('issue', $poor->kind);
        $this->assertSame('open', $poor->status);
        $this->assertSame($this->risks['low']->id, $poor->risk_level_id);
        $ticket = Ticket::findOrFail($poor->ticket_id);
        $this->assertSame('low', $ticket->priority);
        $this->assertSame(168, (int) $ticket->sla_hours);
    }

    public function test_repeat_count_window(): void
    {
        $area = Area::create(['name' => 'Cairo', 'name_ar' => 'القاهرة', 'slug' => 'cairo', 'manager_id' => Seeds::user('area_manager')->id, 'active' => true]);
        $this->branch->update(['area_id' => $area->id]);
        $category = $this->category();
        $repeated = $this->catalog($category, ['risk_level_id' => $this->risks['medium']->id]);
        $stale = $this->catalog($category, ['risk_level_id' => $this->risks['medium']->id]);

        $earlier = $this->visit(['status' => 'submitted']);
        foreach ([[$repeated, 10], [$repeated, 10], [$stale, 10], [$stale, 31]] as [$item, $days]) {
            $prior = $this->observation($earlier, ['catalog_item_id' => $item->id, 'category_id' => $category->id]);
            $prior->forceFill(['created_at' => now()->subDays($days)])->save();
        }

        $visit = $this->visit();
        $third = $this->observation($visit, ['catalog_item_id' => $repeated->id, 'category_id' => $category->id]);
        $notYet = $this->observation($visit, ['catalog_item_id' => $stale->id, 'category_id' => $category->id]);

        $this->submit($visit);

        $third->refresh();
        $this->assertSame(2, $third->repeat_count);
        $this->assertSame($this->risks['high']->id, $third->risk_level_id);
        $this->assertSame($this->risks['medium']->id, $third->escalated_from_risk_level_id);
        $ticket = Ticket::findOrFail($third->ticket_id);
        $this->assertSame('high', $ticket->priority);
        $this->assertSame(48, (int) $ticket->sla_hours);
        $this->assertSame(1, Notification::where('user_id', $area->manager_id)->where('type', 'observation_escalated')->where('ticket_id', $ticket->id)->count());

        $notYet->refresh();
        $this->assertSame(1, $notYet->repeat_count);
        $this->assertSame($this->risks['medium']->id, $notYet->risk_level_id);
        $this->assertNull($notYet->escalated_from_risk_level_id);
        $this->assertSame('medium', Ticket::findOrFail($notYet->ticket_id)->priority);
        $this->assertSame(1, Notification::where('type', 'observation_escalated')->count());
    }

    public function test_demo_rows_do_not_count_as_repeats_for_real_observations(): void
    {
        $category = $this->category();
        $item = $this->catalog($category, ['risk_level_id' => $this->risks['medium']->id]);

        // Two demo rows of the same item at the same branch, inside the 30-day window.
        $demoVisit = $this->visit(['status' => 'submitted']);
        foreach ([3, 5] as $days) {
            $prior = $this->observation($demoVisit, ['catalog_item_id' => $item->id, 'category_id' => $category->id, 'import_source' => 'demo']);
            $prior->forceFill(['created_at' => now()->subDays($days)])->save();
        }

        // A real observation ignores them: no repeat, no escalation.
        $realVisit = $this->visit();
        $real = $this->observation($realVisit, ['catalog_item_id' => $item->id, 'category_id' => $category->id]);
        $this->submit($realVisit);
        $real->refresh();
        $this->assertSame(0, $real->repeat_count);
        $this->assertSame($this->risks['medium']->id, $real->risk_level_id);
        $this->assertNull($real->escalated_from_risk_level_id);

        // A demo observation still counts the demo rows (the real one too) and is escalated.
        $nextDemoVisit = $this->visit();
        $demo = $this->observation($nextDemoVisit, ['catalog_item_id' => $item->id, 'category_id' => $category->id, 'import_source' => 'demo']);
        $this->submit($nextDemoVisit);
        $demo->refresh();
        $this->assertSame(3, $demo->repeat_count);
        $this->assertSame($this->risks['high']->id, $demo->risk_level_id);
        $this->assertSame($this->risks['medium']->id, $demo->escalated_from_risk_level_id);
    }

    public function test_high_risk_notifies_area_manager(): void
    {
        $areaManager = Seeds::user('area_manager');
        $area = Area::create(['name' => 'Cairo', 'name_ar' => 'القاهرة', 'slug' => 'cairo', 'manager_id' => $areaManager->id, 'active' => true]);
        $this->branch->update(['area_id' => $area->id]);
        $visit = $this->visit();
        $high = $this->observation($visit, ['risk_level_id' => $this->risks['high']->id, 'cc_department_ids' => [$this->maintenance->id]]);
        $medium = $this->observation($visit);

        $this->submit($visit);

        $highTicket = $high->fresh()->ticket_id;
        $mediumTicket = $medium->fresh()->ticket_id;
        $this->assertSame(1, Notification::where('user_id', $areaManager->id)->where('type', 'observation_new')->where('ticket_id', $highTicket)->count());
        $this->assertSame(0, Notification::where('user_id', $areaManager->id)->where('ticket_id', $mediumTicket)->count());
        foreach ([$highTicket, $mediumTicket] as $ticketId) {
            $this->assertSame(1, Notification::where('user_id', $this->branchManager->id)->where('type', 'observation_new')->where('ticket_id', $ticketId)->count());
            $this->assertSame(1, Notification::where('user_id', $this->operationManager->id)->where('type', 'ticket_new')->where('ticket_id', $ticketId)->count());
        }
        $this->assertSame(1, Notification::where('user_id', $this->maintenanceManager->id)->where('type', 'observation_cc')->where('ticket_id', $highTicket)->count());
        $this->assertSame(0, Notification::where('user_id', $this->operationManager->id)->whereIn('type', ['observation_new', 'observation_cc'])->count());
    }

    public function test_silent_option_creates_no_notifications(): void
    {
        $area = Area::create(['name' => 'Cairo', 'name_ar' => 'القاهرة', 'slug' => 'cairo', 'manager_id' => Seeds::user('area_manager')->id, 'active' => true]);
        $this->branch->update(['area_id' => $area->id]);
        $technician = Seeds::user('department_employee', ['department_id' => $this->operation->id]);
        $technician->branches()->attach($this->branch->id);
        $visit = $this->visit();
        $observation = $this->observation($visit, ['risk_level_id' => $this->risks['critical']->id, 'cc_department_ids' => [$this->maintenance->id]]);

        $result = $this->submit($visit, ['silent' => true, 'due_from' => now()->subDay()]);

        $this->assertCount(1, $result['created_tickets']);
        $this->assertSame(0, Notification::count());
        $ticket = Ticket::findOrFail($observation->fresh()->ticket_id);
        $this->assertNull($ticket->assigned_to);
        $this->assertSame('open', $ticket->status);
        $this->assertTrue($ticket->due_at->equalTo(now()->subDay()->addHours(24)));
    }
}
