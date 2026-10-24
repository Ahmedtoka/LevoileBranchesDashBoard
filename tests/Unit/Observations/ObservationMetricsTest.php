<?php

namespace Tests\Unit\Observations;

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
use App\Support\DateRange;
use App\Support\ObservationFilters;
use App\Support\ObservationMetrics;
use Illuminate\Http\Request;
use Tests\Support\Seeds;
use Tests\TestCase;

class ObservationMetricsTest extends TestCase
{
    private Department $cctv;

    private Department $maintenance;

    private User $admin;

    private User $creator;

    private Branch $branch;

    private int $ticketNo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->maintenance = Seeds::department('maintenance');
        $this->admin = Seeds::user('super_admin');
        $this->creator = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->branch = Seeds::branch('Heliopolis');
    }

    private function range(array $query = []): DateRange
    {
        return DateRange::fromRequest(Request::create('/observations/dashboard', 'GET', $query));
    }

    private function metrics(array $filters = []): array
    {
        return ObservationMetrics::for($this->admin, $this->range(), $filters);
    }

    private function visit(array $attrs = []): ObservationVisit
    {
        return ObservationVisit::create(array_merge([
            'type' => 'field',
            'department_id' => $this->cctv->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->creator->id,
            'visit_date' => now()->toDateString(),
            'status' => 'submitted',
            'source' => 'web',
        ], $attrs));
    }

    private function observation(array $attrs = []): Observation
    {
        $attrs['observation_visit_id'] ??= $this->visit()->id;

        return Observation::create(array_merge([
            'branch_id' => $this->branch->id,
            'reporting_department_id' => $this->cctv->id,
            'responsible_department_id' => $this->maintenance->id,
            'kind' => 'issue',
            'title' => 'Observation',
            'description' => 'Observation description',
            'event_date' => now()->toDateString(),
            'created_by' => $this->creator->id,
            'status' => 'open',
            'classification_status' => 'catalog',
        ], $attrs));
    }

    private function ticket(Observation $o, array $attrs = []): Ticket
    {
        $ticket = Ticket::create(array_merge([
            'reference' => 'TK-M'.str_pad((string) ++$this->ticketNo, 4, '0', STR_PAD_LEFT),
            'title' => 'Ticket',
            'branch_id' => $o->branch_id,
            'department_id' => $this->maintenance->id,
            'status' => 'open',
            'source' => 'observation',
            'observation_id' => $o->id,
        ], $attrs));
        $o->update(['ticket_id' => $ticket->id]);

        return $ticket;
    }

    private function catalogItem(string $code, string $labelAr): ObservationCatalogItem
    {
        $category = ObservationCategory::firstOrCreate(
            ['department_id' => $this->cctv->id, 'slug' => 'cams'],
            ['name_ar' => 'كاميرات', 'name_en' => 'Cameras']
        );

        return ObservationCatalogItem::create(['category_id' => $category->id, 'code' => $code, 'label_ar' => $labelAr, 'label_en' => $code]);
    }

    public function test_visits_metric_ignores_drafts_and_desk(): void
    {
        $other = Seeds::branch('Maadi');
        $this->visit(['status' => 'draft']);
        $this->visit(['type' => 'desk', 'branch_id' => $other->id]);
        $this->visit();

        $m = $this->metrics();

        $this->assertSame(1, $m['visits']);
        $this->assertSame(1, $m['branches_visited']);
        $row = collect($m['departments'])->firstWhere('department_id', $this->cctv->id);
        $this->assertSame(1, $row['visits']);
        $this->assertSame(1, $row['branches_visited']);
    }

    public function test_observations_per_visit_open_issues_and_outside_catalog(): void
    {
        $visit = $this->visit();
        $this->visit(['type' => 'desk', 'branch_id' => null]); // a submitted desk visit is a submitted visit
        $this->visit(['status' => 'draft']);
        $item = $this->catalogItem('CAM-1', 'كاميرا عطلانة');
        $this->observation(['observation_visit_id' => $visit->id, 'catalog_item_id' => $item->id]);
        $this->observation(['observation_visit_id' => $visit->id, 'kind' => 'positive', 'status' => 'positive']);
        $this->observation(['observation_visit_id' => $visit->id, 'status' => 'verified_closed']);

        $m = $this->metrics();

        $this->assertEquals(1.5, $m['observations_per_visit']); // 3 observations / 2 submitted visits
        $this->assertSame(1, $m['open_issues']);
        $outside = collect($m['outside_catalog_pct_by_department'])->firstWhere('department', $this->cctv->name);
        $this->assertEqualsWithDelta(66.7, $outside['pct'], 0.05);
    }

    public function test_on_time_pct_uses_closed_tickets_only(): void
    {
        $due = now()->subDay();
        $onTime = $this->ticket($this->observation(), ['status' => 'closed', 'due_at' => $due, 'closed_at' => $due->copy()->subHour()]);
        $late = $this->ticket($this->observation(), ['status' => 'closed', 'due_at' => $due, 'closed_at' => $due->copy()->addHours(3)]);
        $this->ticket($this->observation(), ['status' => 'open', 'due_at' => now()->addDay()]);
        $this->ticket($this->observation(), ['status' => 'in_progress', 'due_at' => now()->subDays(2)]);
        foreach ([$onTime, $late] as $t) {
            $t->forceFill(['created_at' => $t->closed_at->copy()->subHours(10)])->save();
        }

        $m = $this->metrics();

        $this->assertEquals(50, $m['on_time_pct']);
        $perf = collect($m['responsible_performance'])->firstWhere('department', $this->maintenance->name);
        $this->assertSame(2, $perf['closed']);
        $this->assertEquals(10, $perf['avg_hours']);
        $this->assertEquals(50, $perf['on_time_pct']);
    }

    public function test_overdue_now_follows_ticket_is_overdue(): void
    {
        $past = now()->subHours(5);
        $counted = $this->ticket($this->observation(), ['status' => 'open', 'due_at' => $past]);
        $this->ticket($this->observation(), ['status' => 'waiting_approval', 'due_at' => $past]);
        $this->ticket($this->observation(), ['status' => 'closed', 'due_at' => $past, 'closed_at' => now()]);
        $this->ticket($this->observation(), ['status' => 'open', 'due_at' => now()->addHours(5)]);
        $this->ticket($this->observation(), ['status' => 'open', 'due_at' => null]);
        // "متأخر الآن" is a state now, not bounded by the period: an observation from 40 days ago still counts ...
        $old = now()->subDays(40)->toDateString();
        $oldOverdue = $this->ticket($this->observation(['event_date' => $old]), ['status' => 'in_progress', 'due_at' => now()->subDays(38)]);
        // ... unless its ticket is closed or waiting approval
        $this->ticket($this->observation(['event_date' => $old]), ['status' => 'closed', 'due_at' => now()->subDays(38), 'closed_at' => now()->subDays(37)]);
        $this->ticket($this->observation(['event_date' => $old]), ['status' => 'waiting_approval', 'due_at' => now()->subDays(38)]);

        $this->assertTrue($counted->fresh()->isOverdue());
        $m = $this->metrics();
        $this->assertSame(2, $m['overdue_now']);
        $this->assertSame(2, $m['week_compare']['current']['overdue_now']);
        $this->assertSame(2, $m['week_compare']['previous']['overdue_now']);
        $this->assertSame(5, $m['open_issues']); // open_issues stays range-bound: 5 in range, the 3 from 40 days ago are not

        $list = ObservationMetrics::overdueTickets($this->admin);
        $this->assertSame([$oldOverdue->id, $counted->id], $list->pluck('id')->all()); // oldest due first

        // the non-range filters still apply
        $other = Seeds::branch('Maadi');
        $this->assertSame(0, $this->metrics(['branch_id' => (string) $other->id])['overdue_now']);
        $this->assertSame(2, $this->metrics(['branch_id' => (string) $this->branch->id])['overdue_now']);
    }

    public function test_repeated_branches_needs_two_of_the_same_item_and_branch(): void
    {
        $repeated = $this->catalogItem('CAM-1', 'كاميرا عطلانة');
        $single = $this->catalogItem('CAM-2', 'تسجيل متوقف');
        $other = Seeds::branch('Maadi');

        $this->observation(['catalog_item_id' => $repeated->id, 'event_date' => now()->subDays(2)->toDateString()]);
        $this->observation(['catalog_item_id' => $repeated->id]);
        $this->observation(['catalog_item_id' => $single->id]);
        $this->observation(['catalog_item_id' => $repeated->id, 'branch_id' => $other->id]);
        $this->observation(['catalog_item_id' => null]);
        $this->observation(['catalog_item_id' => null]);

        $rows = $this->metrics()['repeated_branches'];

        $this->assertCount(1, $rows);
        $this->assertSame('Heliopolis', $rows[0]['branch']);
        $this->assertSame(dt('كاميرا عطلانة', 'CAM-1'), $rows[0]['item']); // label_ar in Arabic, label_en otherwise
        $this->assertSame(2, $rows[0]['count']);
        $this->assertSame(now()->toDateString(), $rows[0]['last_at']);
    }

    public function test_risk_by_department_and_filters(): void
    {
        $high = RiskLevel::create(['slug' => 'high', 'name_ar' => 'عالي', 'name_en' => 'High', 'color' => '#dc2626', 'sort_order' => 2]);
        $low = RiskLevel::create(['slug' => 'low', 'name_ar' => 'منخفض', 'name_en' => 'Low', 'color' => '#16a34a', 'sort_order' => 4]);
        $other = Seeds::branch('Maadi');
        $this->observation(['risk_level_id' => $high->id]);
        $this->observation(['risk_level_id' => $high->id]);
        $this->observation(['risk_level_id' => $low->id, 'branch_id' => $other->id, 'observation_visit_id' => $this->visit(['branch_id' => $other->id])->id]);

        $risk = collect($this->metrics()['risk_by_department'])->firstWhere('department', $this->cctv->name);
        $this->assertSame(['high' => 2, 'low' => 1], $risk['counts']);

        $filtered = $this->metrics(['branch_id' => (string) $other->id]);
        $this->assertSame(['low' => 1], $filtered['risk_by_department'][0]['counts']);
        $this->assertSame(1, $filtered['open_issues']);
        $this->assertSame(1, $filtered['visits']);
    }

    public function test_week_compare_splits_the_last_two_weeks(): void
    {
        $this->visit(['visit_date' => now()->subDays(2)->toDateString()]);
        $this->visit(['visit_date' => now()->subDays(9)->toDateString()]);
        $this->visit(['visit_date' => now()->subDays(10)->toDateString()]);

        $m = ObservationMetrics::for($this->admin, $this->range(['range' => 'month']));

        $this->assertSame(3, $m['visits']);
        $this->assertSame(1, $m['week_compare']['current']['visits']);
        $this->assertSame(2, $m['week_compare']['previous']['visits']);
        $this->assertSame(
            ['visits', 'branches_visited', 'observations_per_visit', 'open_issues', 'on_time_pct', 'overdue_now'],
            array_keys($m['week_compare']['current'])
        );
    }

    public function test_open_issues_counts_undated_issue_on_its_visit_date(): void
    {
        $this->observation(['event_date' => null]); // visit today
        $this->observation(['event_date' => null, 'observation_visit_id' => $this->visit(['visit_date' => now()->subDays(40)->toDateString()])->id]);

        $m = $this->metrics();
        $this->assertSame(1, $m['open_issues']);
        $this->assertSame(1, $m['week_compare']['current']['open_issues']);

        // range=all: no date bound on the range-bound metrics; the week comparison keeps its own windows.
        $all = ObservationMetrics::for($this->admin, ObservationFilters::rangeFromRequest(Request::create('/', 'GET', ['range' => 'all'])));
        $this->assertSame(2, $all['open_issues']);
        $this->assertSame(2, $all['visits']);
        $this->assertSame(1, $all['week_compare']['current']['open_issues']);
    }

    public function test_draft_visit_rows_are_not_counted(): void
    {
        $high = RiskLevel::create(['slug' => 'high', 'name_ar' => 'عالي', 'name_en' => 'High', 'color' => '#dc2626', 'sort_order' => 2]);
        $this->observation(['risk_level_id' => $high->id]);
        $draft = $this->visit(['status' => 'draft']);
        $this->observation(['risk_level_id' => $high->id, 'observation_visit_id' => $draft->id]);
        $this->observation(['risk_level_id' => $high->id, 'observation_visit_id' => $draft->id, 'catalog_item_id' => null]);

        $m = $this->metrics();

        $this->assertSame(1, $m['open_issues']);
        $risk = collect($m['risk_by_department'])->firstWhere('department', $this->cctv->name);
        $this->assertSame(['high' => 1], $risk['counts']);
        $row = collect($m['departments'])->firstWhere('department_id', $this->cctv->id);
        $this->assertSame(1, $row['observations']);
    }

    public function test_cancelled_ticket_is_not_resolved(): void
    {
        $due = now()->subDay();
        $onTime = $this->ticket($this->observation(), ['status' => 'closed', 'due_at' => $due, 'closed_at' => $due->copy()->subHour()]);
        $onTime->forceFill(['created_at' => $onTime->closed_at->copy()->subHours(10)])->save();
        $before = $this->metrics();

        // Closed without action: cancelled on time and fast, but it was not solved.
        $cancelled = $this->ticket($this->observation(['status' => 'closed_no_action']), ['status' => 'closed', 'due_at' => $due, 'closed_at' => $due->copy()->subDays(1)]);
        $cancelled->forceFill(['created_at' => $cancelled->closed_at->copy()->subHour()])->save();
        TicketUpdate::create(['ticket_id' => $cancelled->id, 'action' => 'cancelled', 'from_status' => 'open', 'to_status' => 'closed']);
        // ... and a late, really closed one still counts.
        $late = $this->ticket($this->observation(), ['status' => 'closed', 'due_at' => $due, 'closed_at' => $due->copy()->addHours(2)]);
        $late->forceFill(['created_at' => $late->closed_at->copy()->subHours(30)])->save();

        $this->assertEquals(100, $before['on_time_pct']);
        $m = $this->metrics();
        $this->assertEquals(50, $m['on_time_pct']);
        $perf = collect($m['responsible_performance'])->firstWhere('department', $this->maintenance->name);
        $this->assertSame(2, $perf['closed']);
        $this->assertEquals(20, $perf['avg_hours']);
        $this->assertEquals(50, $perf['on_time_pct']);
    }
}
