<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
use Tests\Support\Seeds;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    private Department $cctv;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->creator = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
    }

    private function visit(Branch $branch): ObservationVisit
    {
        return ObservationVisit::create([
            'type' => 'field',
            'department_id' => $this->cctv->id,
            'branch_id' => $branch->id,
            'user_id' => $this->creator->id,
            'visit_date' => now()->toDateString(),
            'status' => 'submitted',
            'source' => 'web',
        ]);
    }

    private function observation(Branch $branch, array $attrs = []): Observation
    {
        $attrs['observation_visit_id'] ??= $this->visit($branch)->id;

        return Observation::create(array_merge([
            'branch_id' => $branch->id,
            'reporting_department_id' => $this->cctv->id,
            'responsible_department_id' => $this->cctv->id,
            'kind' => 'issue',
            'title' => 'Observation',
            'description' => 'Observation description',
            'event_date' => now()->toDateString(),
            'created_by' => $this->creator->id,
            'status' => 'open',
            'classification_status' => 'catalog',
        ], $attrs));
    }

    public function test_area_manager_dashboard_scoped(): void
    {
        $am = Seeds::user('area_manager');
        $area = Area::create(['name' => 'East', 'slug' => 'east', 'manager_id' => $am->id]);
        $inside = Seeds::branch('Branch In', ['area_id' => $area->id]);
        $outside = Seeds::branch('Branch Out');
        $high = RiskLevel::create(['slug' => 'high', 'name_ar' => 'عالي', 'name_en' => 'High', 'color' => '#dc2626', 'sort_order' => 2]);

        $this->observation($inside, ['risk_level_id' => $high->id]);
        $late = $this->observation($outside, ['risk_level_id' => $high->id, 'title' => 'Outside overdue']);
        $ticket = Ticket::create([
            'reference' => 'TK-D0001', 'title' => 'Ticket', 'branch_id' => $outside->id, 'department_id' => $this->cctv->id,
            'status' => 'open', 'source' => 'observation', 'observation_id' => $late->id, 'due_at' => now()->subDay(),
        ]);
        $late->update(['ticket_id' => $ticket->id]);
        // An old (out-of-range) overdue issue inside the area: counted by "متأخر الآن", not by the range-bound tiles.
        $oldVisit = $this->visit($inside);
        $oldVisit->update(['visit_date' => now()->subDays(40)->toDateString()]);
        $old = $this->observation($inside, ['event_date' => now()->subDays(40)->toDateString(), 'title' => 'Old inside', 'observation_visit_id' => $oldVisit->id]);
        $oldTicket = Ticket::create([
            'reference' => 'TK-D0002', 'title' => 'Ticket', 'branch_id' => $inside->id, 'department_id' => $this->cctv->id,
            'status' => 'open', 'source' => 'observation', 'observation_id' => $old->id, 'due_at' => now()->subDays(39),
        ]);
        $old->update(['ticket_id' => $oldTicket->id]);

        $res = $this->actingAs($am)->get(route('observations.dashboard'))
            ->assertOk()
            ->assertViewHas('metrics', fn (array $m) => $m['visits'] === 1 && $m['branches_visited'] === 1
                && $m['open_issues'] === 1 && $m['overdue_now'] === 1)
            ->assertSee('TK-D0002')->assertSee('غير مرتبط بالفترة المختارة')
            ->assertSee('زيارات الإدارة')->assertSee('فروع تمت زيارتها')->assertSee('ملاحظات لكل زيارة')
            ->assertSee('مشاكل مفتوحة')->assertSee('نسبة الحل في المهلة')->assertSee('متأخر الآن')
            ->assertSee('الفرق حجم توثيق مش بالضرورة أداء')
            ->assertDontSee('TK-D0001')
            ->assertDontSee('Branch Out'); // neither the overdue list nor the branch dropdown leak the other area

        // tiles link back to the register with the same filters
        $html = html_entity_decode($res->getContent());
        $this->assertStringContainsString(route('observations.index', ['range' => 'week', 'kind' => 'issue']), $html);
        // the overdue tile is not period-bound, so its register link carries range=all (the register then lists every date)
        $this->assertStringContainsString('href="'.route('observations.index', ['range' => 'all', 'kind' => 'issue']).'"', $html);
        $this->actingAs($am)->get(route('observations.index', ['range' => 'all', 'kind' => 'issue']))->assertOk()->assertSee($old->reference);

        $this->actingAs(Seeds::user('super_admin'))->get(route('observations.dashboard'))
            ->assertOk()
            ->assertViewHas('metrics', fn (array $m) => $m['visits'] === 2 && $m['open_issues'] === 2 && $m['overdue_now'] === 2)
            ->assertSee('TK-D0001')->assertSee($late->reference);
    }

    public function test_department_manager_dashboard_scoped_to_their_department(): void
    {
        $vm = Seeds::department('vm', ['is_reporting' => true]);
        $manager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);
        $branch = Seeds::branch('Alpha');
        $this->observation($branch);
        $this->observation($branch, ['reporting_department_id' => $vm->id, 'responsible_department_id' => $vm->id,
            'observation_visit_id' => ObservationVisit::create([
                'type' => 'field', 'department_id' => $vm->id, 'branch_id' => $branch->id, 'user_id' => $this->creator->id,
                'visit_date' => now()->toDateString(), 'status' => 'submitted', 'source' => 'web',
            ])->id]);

        $this->actingAs($manager)->get(route('observations.dashboard'))
            ->assertOk()
            ->assertViewHas('metrics', fn (array $m) => $m['visits'] === 1 && $m['open_issues'] === 1
                && array_column($m['departments'], 'department') === [$this->cctv->name]);
    }

    public function test_filters_reach_the_metrics_and_the_tile_links(): void
    {
        $a = Seeds::branch('Alpha');
        $b = Seeds::branch('Beta');
        $this->observation($a);
        $this->observation($b);

        $res = $this->actingAs(Seeds::user('super_admin'))
            ->get(route('observations.dashboard', ['range' => 'month', 'branch_id' => $b->id]))
            ->assertOk()
            ->assertViewHas('metrics', fn (array $m) => $m['visits'] === 1 && $m['open_issues'] === 1);

        $html = html_entity_decode($res->getContent());
        $this->assertStringContainsString(route('observations.index', ['range' => 'month', 'branch_id' => $b->id, 'kind' => 'issue']), $html);
        $this->assertStringContainsString('href="'.route('observations.index', ['branch_id' => $b->id, 'range' => 'all', 'kind' => 'issue']).'"', $html);
    }

    public function test_super_admin_dashboard_renders_with_empty_db(): void
    {
        $this->actingAs(Seeds::user('super_admin'))->get(route('observations.dashboard'))
            ->assertOk()
            ->assertViewHas('metrics', fn (array $m) => $m['visits'] === 0 && $m['branches_visited'] === 0
                && $m['observations_per_visit'] == 0 && $m['open_issues'] === 0 && $m['on_time_pct'] === null
                && $m['overdue_now'] === 0 && $m['risk_by_department'] === [] && $m['repeated_branches'] === []
                && $m['week_compare']['previous']['visits'] === 0);
    }

    public function test_dashboard_denied_for_users_without_access(): void
    {
        $this->actingAs(Seeds::user('sales'))->get(route('observations.dashboard'))->assertForbidden();
    }
}
