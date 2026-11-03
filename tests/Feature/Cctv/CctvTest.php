<?php

namespace Tests\Feature\Cctv;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use App\Models\User;
use App\Services\Cctv\CctvSheetMapper;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\Seeds;
use Tests\TestCase;

/** 2026-11-03: the CCTV department's menu, the weekly-sheet import and the employee records. */
class CctvTest extends TestCase
{
    private Department $cctv;

    private Branch $zayed;

    private Branch $cityStars;

    private User $recorder;

    /** @var array<string, int> category slug => id */
    private array $cat = [];

    /** @var array<string, int> catalog code => id */
    private array $item = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
        config(['services.anthropic.key' => null, 'observations.manager_approval' => true]);

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $operation = Seeds::department('operation', ['is_reporting' => true]);
        $this->zayed = Seeds::branch('Sheikh Zayed - Saraya Mall', ['active' => true]);
        $this->cityStars = Seeds::branch('Nasr City - City Stars Mall', ['active' => true]);
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);

        foreach ([['high', 1], ['medium', 2], ['low', 3]] as [$slug, $order]) {
            RiskLevel::create(['slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug, 'color' => '#000', 'sort_order' => $order, 'default_sla_hours' => 72, 'ticket_priority' => $slug, 'active' => true]);
        }
        foreach (['branch_opening' => ['CCTV-OPEN-LATE', 'minutes_late'], 'floor' => ['CCTV-FLOOR-NO-NAMETAG', null], 'cashier' => ['CCTV-CASH-CUSTOMER-DATA', null], 'other' => [null, null]] as $slug => [$code, $minutes]) {
            $category = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug, 'default_kind' => 'issue', 'active' => true]);
            $this->cat[$slug] = $category->id;
            if ($code) {
                $this->item[$code] = ObservationCatalogItem::create([
                    'category_id' => $category->id, 'code' => $code, 'label_ar' => $code, 'label_en' => $code, 'kind' => 'issue',
                    'risk_level_id' => RiskLevel::bySlug('medium')->id, 'responsible_department_id' => $operation->id,
                    'extra_fields' => $minutes ? [['key' => $minutes, 'type' => 'number', 'label_ar' => 'دقائق']] : [],
                    'escalation_rules' => $minutes ? [['field' => $minutes, 'gte' => 30, 'risk' => 'high']] : null,
                ])->id;
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mapper_swaps_the_misread_serial_dates_and_reads_text_dates(): void
    {
        $this->assertSame('2026-10-01', CctvSheetMapper::date('46032')->toDateString()); // shown by Excel as 10 Jan 2026
        $this->assertSame('2026-10-11', CctvSheetMapper::date('46336')->toDateString());
        $this->assertSame('2026-10-18', CctvSheetMapper::date('18/10/2026')->toDateString());
        $this->assertSame('2026-10-22', CctvSheetMapper::date('22-10-2026')->toDateString());
        $this->assertNull(CctvSheetMapper::date('__'));
    }

    public function test_mapper_classifies_the_sheet_texts(): void
    {
        $this->assertSame('CCTV-OPEN-LATE', CctvSheetMapper::code('تأخير الموظفة(20 دقيقة)عن ميعاد فتح الفرع'));
        $this->assertSame('CCTV-OPEN-LOCKED', CctvSheetMapper::code('تم غلق باب الفرع بالمفتاح وعدم فتحه حتي الساعه 10 am'));
        // Mentions a mobile number but is about customer data, not phone use.
        $this->assertSame('CCTV-CASH-CUSTOMER-DATA', CctvSheetMapper::code('تكرار تسجيل نفس رقم الموبايل بعدة فواتير وعدم تسجيل بيانات العملاء علي السيستم'));
        $this->assertSame('CCTV-FLOOR-MOBILE', CctvSheetMapper::code('كثرة استخدام الموظفه للموبايل الشخصي وعدم الاهتمام بالعملاء'));
        $this->assertSame('CCTV-FLOOR-BREAK', CctvSheetMapper::code('تاخير الموظفة 22دقيقه بعد وقت البريك المسموح به'));
        $this->assertNull(CctvSheetMapper::code('نص لا يخص أي مخالفة'));

        $this->assertSame(120, CctvSheetMapper::minutes('تأخير الموظفة(ساعتين)عن ميعاد فتح الفرع'));
        $this->assertSame(60, CctvSheetMapper::minutes('تأخير الموظفة(ما يقرب من ساعة)عن ميعاد فتح الفرع'));
        $this->assertSame(32, CctvSheetMapper::minutes(' تاخير الموظفه 32دقيقه عن ميعاد فتح الفرع'));
    }

    public function test_import_skips_rows_repeated_from_an_earlier_sheet_and_folds_name_spellings(): void
    {
        $late = 'مخالفات تتعلق بالتأخير عن ميعاد فتح الفرع';
        $w1 = $this->sheet([
            ['', 'عبد الرحمن', 'Zayed', '46336', $late, 'تأخير الموظف(16 دقيقة)عن ميعاد فتح الفرع', ''],
            ['', 'شهد', 'City Stars', '46336', 'مخالفات تتعلق بمنطقة الكاشير', 'عدم تسجيل بيانات العملاء علي السيستم بشكل صحيح', 'تم الرد'],
        ]);
        $w2 = $this->sheet([
            ['', 'عبد الرحمن', 'Zayed', '46336', $late, 'تأخير الموظف(16 دقيقة)عن ميعاد فتح الفرع', ''], // repeated from w1
            ['', 'عبدالرحمن', 'Zayed', '19-10-2026', $late, 'تأخير الموظف(40 دقيقة)عن ميعاد فتح الفرع', ''],
            ['', '', 'Zayed', '19-10-2026', 'مخالفات تتعلق بمنطقة الفلور', 'عدم ارتداء الموظفه النيم تاج', ''],
        ]);

        $this->artisan('cctv:import-weeks', ['paths' => [$w1, $w2]])->assertSuccessful();

        $this->assertSame(4, Observation::where('reporting_department_id', $this->cctv->id)->count());
        $this->assertSame(2, Employee::count(), 'عبد الرحمن and عبدالرحمن are one record');
        $abdo = Employee::where('branch_id', $this->zayed->id)->sole();
        $this->assertSame(2, Observation::where('employee_id', $abdo->id)->count());
        $late40 = Observation::where('employee_id', $abdo->id)->where('event_date', '2026-10-19')->sole();
        $this->assertSame(40, $late40->extra_values['minutes_late']);
        $this->assertSame('high', $late40->riskLevel->slug, '30 minutes or more escalates, as in live entry');
        $this->assertSame('تم الرد', Observation::where('category_id', $this->cat['cashier'])->sole()->extra_values['reply']);
        $this->assertNull(Observation::where('category_id', $this->cat['floor'])->sole()->employee_id);

        // A second run writes nothing new.
        $this->artisan('cctv:import-weeks', ['paths' => [$w1, $w2]])->assertSuccessful();
        $this->assertSame(4, Observation::where('reporting_department_id', $this->cctv->id)->count());
    }

    public function test_import_aborts_on_an_unknown_branch(): void
    {
        $path = $this->sheet([['', 'منى', 'Nowhere Mall', '46336', 'مخالفات تتعلق بمنطقة الفلور', 'عدم ارتداء الموظفه النيم تاج', '']]);

        $this->artisan('cctv:import-weeks', ['paths' => [$path]])->assertFailed();
        $this->assertSame(0, Observation::count());
    }

    public function test_recording_a_violation_creates_the_employee_and_shows_in_the_summary(): void
    {
        $this->actingAs($this->recorder)->post(route('cctv.store'), [
            'branch_id' => $this->zayed->id, 'new_employee' => 'بسملة سيد', 'event_date' => '2026-11-02', 'event_time' => '10:25',
            'category_id' => $this->cat['branch_opening'], 'catalog_item_id' => $this->item['CCTV-OPEN-LATE'], 'minutes' => '25',
            'reply' => 'الطريق كان واقف', 'department_id' => Department::where('slug', 'operation')->value('id'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $o = Observation::sole();
        $employee = Employee::sole();
        $this->assertSame('بسملة سيد', $employee->name);
        $this->assertSame($this->zayed->id, $employee->branch_id);
        $this->assertSame($employee->id, $o->employee_id);
        $this->assertSame(['minutes_late' => 25, 'time' => '10:25', 'reply' => 'الطريق كان واقف'], $o->extra_values);
        $this->assertSame('submitted', $o->visit->status);

        $page = $this->actingAs($this->recorder)->get(route('cctv.summary'))->assertOk();
        $page->assertSee('Zayed')->assertSee('بسملة سيد')->assertSee('Grand Total');
        $this->assertSame(1, $page->viewData('byStore')['total']);
        $this->assertSame(1, $page->viewData('byEmployee')['branches'][0]['people'][0]['total']);
    }

    public function test_the_employee_must_belong_to_the_branch_and_other_needs_a_description(): void
    {
        $other = Employee::create(['name' => 'شهد', 'branch_id' => $this->cityStars->id]);

        $this->actingAs($this->recorder)->post(route('cctv.store'), [
            'branch_id' => $this->zayed->id, 'employee_id' => $other->id, 'event_date' => '2026-11-02', 'category_id' => $this->cat['other'],
        ])->assertSessionHasErrors(['employee_id', 'description', 'department_id']);
        $this->assertSame(0, Observation::count());
    }

    public function test_the_month_has_four_weeks_and_week_four_runs_to_the_end(): void
    {
        $oct = Carbon::create(2026, 10, 1);
        $this->assertSame(['2026-10-01', '2026-10-07'], array_map(fn ($d) => $d->toDateString(), \App\Support\CctvDashboard::weekRange($oct, 1)));
        $this->assertSame(['2026-10-22', '2026-10-31'], array_map(fn ($d) => $d->toDateString(), \App\Support\CctvDashboard::weekRange($oct, 4)));
        $this->assertSame(['2026-02-22', '2026-02-28'], array_map(fn ($d) => $d->toDateString(), \App\Support\CctvDashboard::weekRange(Carbon::create(2026, 2, 1), 4)));
        $this->assertSame(-50, \App\Support\CctvDashboard::change(2, 4));
        $this->assertNull(\App\Support\CctvDashboard::change(3, 0));
    }

    public function test_summary_filters_by_month_week_and_branch_and_compares_with_the_period_before(): void
    {
        // October: 2 in week 2 at Zayed, 1 in week 3 at City Stars; September week 4: 1 at Zayed.
        foreach ([['2026-10-09', $this->zayed], ['2026-10-10', $this->zayed], ['2026-10-16', $this->cityStars], ['2026-09-25', $this->zayed]] as [$date, $branch]) {
            $this->record($branch, $date);
        }

        $month = $this->actingAs($this->recorder)->get(route('cctv.summary', ['month' => '2026-10']))->assertOk();
        $this->assertSame(3, array_sum($month->viewData('byCategory')));
        $this->assertSame(1, array_sum($month->viewData('prevByCategory')), 'October against September');
        $this->assertSame([0, 2, 1, 0], array_column($month->viewData('weeks'), 'total'));
        $this->assertSame([1, 0, 2, 1], array_column($month->viewData('weeks'), 'prev'), 'week 1 against week 4 of September');
        $this->assertSame('Zayed', $month->viewData('topBranches')[0]['name']);

        $week2 = $this->actingAs($this->recorder)->get(route('cctv.summary', ['month' => '2026-10', 'week' => 2]))->assertOk();
        $this->assertSame(2, array_sum($week2->viewData('byCategory')));

        $branch = $this->actingAs($this->recorder)->get(route('cctv.summary', ['month' => '2026-10', 'branch_id' => $this->cityStars->id]))->assertOk();
        $this->assertSame(1, array_sum($branch->viewData('byCategory')));
        $this->assertSame([], $branch->viewData('topBranches'), 'one branch: the top violations show instead');
        $this->assertCount(1, $branch->viewData('topViolations'));

        // Default month: the latest one with data.
        $this->assertSame('2026-10', $this->actingAs($this->recorder)->get(route('cctv.summary'))->viewData('d')->month->format('Y-m'));
    }

    public function test_the_list_filters_paginates_with_bootstrap_and_counts_everything_matched(): void
    {
        $this->record($this->zayed, '2026-10-09', ['minutes' => 40, 'reply' => 'اتنبه عليها']);
        $this->record($this->zayed, '2026-10-10', ['minutes' => 12]);
        $this->record($this->cityStars, '2026-10-16');

        $all = $this->actingAs($this->recorder)->get(route('cctv.index', ['per_page' => 25]))->assertOk();
        $this->assertSame(3, $all->viewData('stats')['total']);
        $this->assertSame(1, $all->viewData('stats')['replied']);

        $late = $this->actingAs($this->recorder)->get(route('cctv.index', ['min_minutes' => 30]))->assertOk();
        $this->assertSame(1, $late->viewData('violations')->total());
        $this->assertSame(1, $this->actingAs($this->recorder)->get(route('cctv.index', ['reply' => 'no', 'branch_id' => $this->zayed->id]))->viewData('violations')->total());
        $this->assertSame(2, $this->actingAs($this->recorder)->get(route('cctv.index', ['month' => '2026-10', 'week' => 2]))->viewData('violations')->total());
        $this->assertSame(3, $this->actingAs($this->recorder)->get(route('cctv.index', ['status' => 'active']))->viewData('violations')->total());

        $paged = $this->actingAs($this->recorder)->get(route('cctv.index', ['per_page' => 25]));
        $this->assertSame(25, $paged->viewData('perPage'));
        $paged->assertDontSee('relative inline-flex', false); // not the Tailwind pager
    }

    public function test_the_entry_screen_shows_the_branches_as_boxes_with_the_day_count(): void
    {
        $this->record($this->zayed, '2026-11-02');

        $page = $this->actingAs($this->recorder)->get(route('cctv.create', ['event_date' => '2026-11-02']))->assertOk();
        $page->assertSee('class="rec-tile"', false)->assertSee('Zayed');
        $this->assertSame([$this->zayed->id => 1], $page->viewData('dayCounts'));
    }

    /** One late-opening violation through the real entry route. */
    private function record(Branch $branch, string $date, array $extra = []): void
    {
        $this->actingAs($this->recorder)->post(route('cctv.store'), array_merge([
            'branch_id' => $branch->id, 'event_date' => $date,
            'category_id' => $this->cat['branch_opening'], 'catalog_item_id' => $this->item['CCTV-OPEN-LATE'],
            'department_id' => Department::where('slug', 'operation')->value('id'),
        ], $extra))->assertSessionHasNoErrors();
    }

    public function test_a_clean_branch_is_saved_in_one_click_and_shows_green_that_day(): void
    {
        $this->actingAs($this->recorder)->post(route('cctv.clean'), ['branch_id' => $this->cityStars->id, 'event_date' => '2026-11-02'])
            ->assertRedirect(route('cctv.create', ['event_date' => '2026-11-02']))->assertSessionHasNoErrors();
        $this->record($this->zayed, '2026-11-02');
        $this->actingAs($this->recorder)->post(route('cctv.clean'), ['branch_id' => $this->zayed->id, 'event_date' => '2026-11-02']);

        $this->assertSame(1, Observation::count(), 'a clean monitoring adds no violation');
        $page = $this->actingAs($this->recorder)->get(route('cctv.create', ['event_date' => '2026-11-02']))->assertOk();
        $this->assertSame([$this->cityStars->id], $page->viewData('dayClean'), 'Zayed had a violation that day');
        $this->assertSame(1, array_sum($this->actingAs($this->recorder)->get(route('cctv.summary', ['month' => '2026-11']))->viewData('byCategory')));
    }

    public function test_a_violation_goes_to_the_department_picked_and_a_manager_s_entry_opens_the_ticket(): void
    {
        $hr = Seeds::department('hr', ['is_reporting' => true]);
        $manager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);

        $this->actingAs($manager)->post(route('cctv.store'), [
            'branch_id' => $this->zayed->id, 'event_date' => '2026-11-02', 'category_id' => $this->cat['floor'],
            'catalog_item_id' => $this->item['CCTV-FLOOR-NO-NAMETAG'], 'department_id' => $hr->id,
        ])->assertSessionHasNoErrors();

        $o = Observation::sole();
        $this->assertSame('approved', $o->visit->status);
        $this->assertSame($hr->id, $o->responsible_department_id);
        $this->assertSame($hr->id, $o->ticket->department_id);
        $this->assertSame(1, $this->actingAs($manager)->get(route('cctv.index', ['department_id' => $hr->id]))->viewData('violations')->total());
    }

    public function test_access_is_management_and_the_cctv_staff(): void
    {
        $operationStaff = Seeds::user('department_employee', ['department_id' => Department::where('slug', 'operation')->value('id')]);
        $opsManager = Seeds::user('ops_manager');

        $this->actingAs($operationStaff)->get(route('cctv.summary'))->assertForbidden();
        $this->actingAs($operationStaff)->get(route('cctv.create'))->assertForbidden();
        $this->actingAs($opsManager)->get(route('cctv.summary'))->assertOk();
        $this->actingAs($this->recorder)->get(route('cctv.create'))->assertOk();
        $this->actingAs($this->recorder)->get(route('cctv.index'))->assertOk();
        // A CCTV member works in the CCTV menu only.
        $this->actingAs($this->recorder)->get(route('dashboard'))->assertRedirect(route('cctv.summary'));
        $this->actingAs($this->recorder)->get(route('observations.index'))->assertRedirect(route('cctv.summary'));
        $this->actingAs($this->recorder)->get(route('cctv.summary'))->assertOk()
            ->assertSee(route('cctv.create'), false)->assertDontSee(route('tickets.index'), false)->assertDontSee(route('visits.index'), false);
    }

    /** A weekly sheet with the week-2 header row; returns its path. */
    private function sheet(array $rows): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray(array_merge([['الكود', 'اسم الموظف', 'الفرع', 'تاريخ المخالفة', 'المخالفة', 'المخالفات', 'ملاحظات']], $rows));
        $path = tempnam(sys_get_temp_dir(), 'cctv').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
