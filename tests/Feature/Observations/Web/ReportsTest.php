<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ObservationWeeklyReport;
use Illuminate\Support\Carbon;
use Tests\Support\Seeds;
use Tests\TestCase;

/** 2026-10-28: the employee report and the weekly department report pages. */
class ReportsTest extends TestCase
{
    private Department $cctv;

    private Department $stock;

    private Department $operation;

    private Branch $zayed;

    private Branch $stars;

    private User $recorder;

    private RiskLevel $high;

    private RiskLevel $low;

    private ObservationCategory $cashier;

    private ObservationCategory $floor;

    private ObservationCatalogItem $drawer;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 28 Oct 2026: the week is Sat 24 Oct -> Fri 30 Oct; the previous one Sat 17 -> Fri 23.
        Carbon::setTestNow('2026-10-28 10:00:00');

        $this->cctv = Seeds::department('vm', ['is_reporting' => true]);
        $this->stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation', ['is_reporting' => true]);
        $this->zayed = Seeds::branch('Sheikh Zayed');
        $this->stars = Seeds::branch('City Stars');
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->high = RiskLevel::create(['slug' => 'high', 'name_ar' => 'عالي', 'name_en' => 'High', 'color' => '#dc2626', 'sort_order' => 2, 'active' => true]);
        $this->low = RiskLevel::create(['slug' => 'low', 'name_ar' => 'منخفض', 'name_en' => 'Low', 'color' => '#16a34a', 'sort_order' => 4, 'active' => true]);
        $this->cashier = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => 'cashier', 'name_ar' => 'الكاشير', 'name_en' => 'Cashier', 'default_kind' => 'issue', 'sort_order' => 1, 'active' => true]);
        $this->floor = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => 'floor', 'name_ar' => 'الفلور', 'name_en' => 'Floor', 'default_kind' => 'issue', 'sort_order' => 2, 'active' => true]);
        $this->drawer = ObservationCatalogItem::create(['category_id' => $this->cashier->id, 'code' => 'CCTV-DRAWER', 'label_ar' => 'درج مفتوح', 'label_en' => 'Open drawer', 'kind' => 'issue', 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function visit(Branch $branch, string $date, Department $department = null): ObservationVisit
    {
        return ObservationVisit::create([
            'type' => 'field', 'department_id' => ($department ?? $this->cctv)->id, 'branch_id' => $branch->id,
            'user_id' => $this->recorder->id, 'visit_date' => $date, 'status' => 'approved', 'submitted_at' => now(), 'source' => 'web',
        ]);
    }

    private function point(ObservationVisit $visit, array $attrs = []): Observation
    {
        return Observation::create(array_merge([
            'observation_visit_id' => $visit->id, 'branch_id' => $visit->branch_id, 'reporting_department_id' => $visit->department_id,
            'responsible_department_id' => $this->operation->id, 'category_id' => $this->cashier->id, 'kind' => 'issue',
            'risk_level_id' => $this->low->id, 'title' => 'نقطة', 'description' => 'نقطة', 'event_date' => $visit->visit_date,
            'status' => 'open', 'classification_status' => 'manual', 'created_by' => $this->recorder->id,
        ], $attrs));
    }

    public function test_week_of_runs_saturday_to_friday(): void
    {
        [$from, $to] = ObservationWeeklyReport::weekOf(Carbon::parse('2026-10-28'));
        $this->assertSame('2026-10-24', $from->toDateString());
        $this->assertSame('2026-10-30', $to->toDateString());

        [$from, $to] = ObservationWeeklyReport::weekOf(Carbon::parse('2026-10-24'));
        $this->assertSame('2026-10-24', $from->toDateString(), 'a Saturday starts its own week');
        [$from] = ObservationWeeklyReport::weekOf(Carbon::parse('2026-10-23'));
        $this->assertSame('2026-10-17', $from->toDateString(), 'a Friday closes the previous week');
    }

    public function test_weekly_report_matrix_compliance_recurring_and_scope(): void
    {
        // This week: Zayed has a high issue (critical), City Stars a stable visit (positive point only) => compliance 50%.
        $vZ = $this->visit($this->zayed, '2026-10-26');
        $this->point($vZ, ['risk_level_id' => $this->high->id, 'catalog_item_id' => $this->drawer->id, 'title' => 'درج مفتوح', 'employee_name' => 'أحمد', 'penalty' => 'إنذار شفهي', 'follow_up_at' => '2026-11-03']);
        $this->point($vZ, ['catalog_item_id' => $this->drawer->id, 'title' => 'درج مفتوح تاني', 'status' => 'verified_closed']);
        $this->point($vZ, ['category_id' => $this->floor->id, 'kind' => 'positive', 'status' => 'positive', 'risk_level_id' => null, 'responsible_department_id' => null, 'title' => 'الفلور نظيف']);
        $vS = $this->visit($this->stars, '2026-10-25');
        $this->point($vS, ['kind' => 'positive', 'status' => 'positive', 'risk_level_id' => null, 'responsible_department_id' => null, 'title' => 'ممتاز']);
        // Previous week: the same drawer problem on Zayed (so it is "recurring") and a stable City Stars => 50% too.
        $prev = $this->visit($this->zayed, '2026-10-19');
        $this->point($prev, ['catalog_item_id' => $this->drawer->id, 'title' => 'درج مفتوح الأسبوع اللي فات']);
        $this->visit($this->stars, '2026-10-20');
        // Another department's visit this week must not appear in the CCTV report.
        $this->point($this->visit($this->zayed, '2026-10-27', $this->stock), ['reporting_department_id' => $this->stock->id, 'category_id' => null, 'title' => 'تالف بدون باركود']);

        $admin = Seeds::user('super_admin');
        $res = $this->actingAs($admin)->get(route('observations.weekly', ['department_id' => $this->cctv->id, 'date' => '2026-10-28']))->assertOk();
        $report = $res->viewData('report');

        $this->assertSame('2026-10-24', $report['from']->toDateString());
        $this->assertSame(2, $report['kpis']['visits']);
        $this->assertSame(2, $report['kpis']['branches']);
        $this->assertSame(2, $report['kpis']['issues']);
        $this->assertSame(1, $report['kpis']['solved']);
        $this->assertSame(50.0, $report['kpis']['compliance']);
        $this->assertSame(50.0, $report['kpis']['previous_compliance']);
        $this->assertSame(1, $report['kpis']['critical']);
        $this->assertSame(1, $report['kpis']['stable']);

        $zayed = collect($report['matrix'])->firstWhere('branch', 'Sheikh Zayed');
        $this->assertSame(ObservationWeeklyReport::CRITICAL, $zayed['status']);
        $this->assertSame(2, $zayed['cells'][$this->cashier->id]['issues']);
        $this->assertSame('high', $zayed['cells'][$this->cashier->id]['worst']);
        $this->assertSame(0, $zayed['cells'][$this->floor->id]['issues']);
        $this->assertSame(1, $zayed['cells'][$this->floor->id]['points']);
        $stars = collect($report['matrix'])->firstWhere('branch', 'City Stars');
        $this->assertSame(ObservationWeeklyReport::STABLE, $stars['status']);

        $this->assertCount(1, $report['recurring']);
        $this->assertSame(2, $report['recurring'][0]['count']);
        $this->assertTrue($report['recurring'][0]['last_week']);
        $this->assertSame('درج مفتوح', $report['recurring'][0]['item']);

        $res->assertSee('نسبة الالتزام')->assertSee('50%')->assertSee('الكاشير')->assertSee('الفلور')
            ->assertSee('أحمد')->assertSee('إنذار شفهي')->assertSee('2026-11-03')->assertSee('المشاكل المتكررة')
            ->assertDontSee('تالف بدون باركود');

        // Navigation keeps the department; the previous week link lands on the week before.
        $this->assertStringContainsString(route('observations.weekly', ['department_id' => $this->cctv->id, 'date' => '2026-10-17']), html_entity_decode($res->getContent()));

        // A department manager only has their own department in the picker and cannot read another one's report.
        $manager = Seeds::user('department_manager', ['department_id' => $this->stock->id, 'is_department_manager' => true]);
        $this->actingAs($manager)->get(route('observations.weekly'))->assertOk()
            ->assertViewHas('departments', fn ($d) => $d->count() === 1 && $d->first()->id === $this->stock->id)
            ->assertSee('تالف بدون باركود');
        $this->actingAs($manager)->get(route('observations.weekly', ['department_id' => $this->cctv->id]))->assertForbidden();

        // A store manager reads the CCTV report over their own branch only.
        $store = Seeds::user('store_manager', ['branch_id' => $this->stars->id]);
        $this->actingAs($store)->get(route('observations.weekly', ['department_id' => $this->cctv->id, 'date' => '2026-10-28']))->assertOk()
            ->assertViewHas('report', fn ($r) => $r['kpis']['branches'] === 1 && $r['kpis']['compliance'] === 100.0);

        // No access for a non-reporting department employee.
        $outsider = Seeds::user('department_employee', ['department_id' => Seeds::department('cleaning')->id]);
        $this->actingAs($outsider)->get(route('observations.weekly'))->assertForbidden();
    }

    public function test_employee_report_groups_points_by_employee(): void
    {
        $v = $this->visit($this->zayed, '2026-10-26');
        $this->point($v, ['employee_name' => 'أحمد سمير', 'risk_level_id' => $this->high->id, 'penalty' => 'إنذار']);
        $this->point($v, ['employee_name' => 'أحمد سمير', 'status' => 'verified_closed']);
        $this->point($this->visit($this->stars, '2026-10-27'), ['employee_name' => 'سارة']);
        $this->point($v, ['title' => 'بدون موظف']);
        // Out of range (last month) and from a draft visit: never counted.
        $this->point($this->visit($this->zayed, '2026-09-01'), ['employee_name' => 'أحمد سمير']);
        $draft = $this->visit($this->zayed, '2026-10-26');
        $draft->update(['status' => 'draft']);
        $this->point($draft, ['employee_name' => 'مسودة']);

        $res = $this->actingAs(Seeds::user('super_admin'))->get(route('observations.employees', ['range' => 'week']))->assertOk();
        $rows = collect($res->viewData('report')['rows']);

        $this->assertSame(['أحمد سمير', 'سارة'], $rows->pluck('employee')->all());
        $ahmed = $rows->first();
        $this->assertSame(2, $ahmed['observations']);
        $this->assertSame(1, $ahmed['open_issues']);
        $this->assertSame(1, $ahmed['penalties']);
        $this->assertSame(['high' => 1, 'low' => 1], $ahmed['risk']);
        $this->assertSame(['Sheikh Zayed'], $ahmed['branch_names']);
        $this->assertSame('2026-10-26', $ahmed['last_at']);
        $this->assertSame(2, $res->viewData('report')['totals']['employees']);
        $res->assertSee('أحمد سمير')->assertSee('سارة')->assertDontSee('مسودة');
        $this->assertStringContainsString(route('observations.index', ['range' => 'week', 'employee' => 'أحمد سمير']), html_entity_decode($res->getContent()));

        // The employee filter narrows the report too; a department employee of another department sees nothing of CCTV.
        $this->actingAs(Seeds::user('super_admin'))->get(route('observations.employees', ['range' => 'week', 'employee' => 'سار']))->assertOk()
            ->assertViewHas('report', fn ($r) => count($r['rows']) === 1 && $r['rows'][0]['employee'] === 'سارة');
        $stockEmp = Seeds::user('department_employee', ['department_id' => $this->stock->id]);
        $this->actingAs($stockEmp)->get(route('observations.employees', ['range' => 'week']))->assertOk()
            ->assertViewHas('report', fn ($r) => $r['rows'] === []);
    }

    public function test_nav_shows_the_two_reports_to_managers_only(): void
    {
        $this->actingAs(Seeds::user('super_admin'))->get(route('observations.index'))->assertOk()
            ->assertSee(route('observations.weekly'), false)->assertSee(route('observations.employees'), false);
        $this->actingAs($this->recorder)->get(route('observations.index'))->assertOk()
            ->assertDontSee(route('observations.weekly'), false);
    }
}
