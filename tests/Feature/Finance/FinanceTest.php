<?php

namespace Tests\Feature\Finance;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\User;
use App\Services\Finance\FinanceReportMapper;
use App\Support\FinanceReport;
use Illuminate\Support\Carbon;
use Tests\Support\Seeds;
use Tests\TestCase;

/** 2026-11-03: the finance department's menu, its weekly-report import and the branch reviews. */
class FinanceTest extends TestCase
{
    private Department $finance;

    private User $recorder;

    /** @var array<string, int> */
    private array $cat = [];

    /** @var array<string, int> */
    private array $item = [];

    /** @var array<string, Branch> */
    private array $b = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
        config(['services.anthropic.key' => null, 'observations.manager_approval' => true]);

        $this->finance = Seeds::department('finance', ['is_reporting' => true]);
        $operation = Seeds::department('operation', ['is_reporting' => true]);
        Seeds::department('lp', ['is_reporting' => true]);
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->finance->id]);
        foreach (['Nasr City - City Stars Mall', 'Sheikh Zayed - Saraya Mall', 'Nasr City - Abbas El Akkad', 'MDS Store'] as $name) {
            $this->b[$name] = Seeds::branch($name, ['active' => true]);
        }
        foreach ([['high', 1], ['medium', 2], ['critical', 0]] as [$slug, $order]) {
            RiskLevel::create(['slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug, 'color' => '#000', 'sort_order' => $order, 'default_sla_hours' => 72, 'ticket_priority' => $slug === 'critical' ? 'critical' : $slug, 'active' => true]);
        }
        $items = [
            'returns' => ['FIN-RETURNS-NO-INVOICE' => false, 'FIN-RETURN-LOST' => true],
            'cash_variance' => ['FIN-CASH-SHORTAGE' => true],
            'journals' => ['FIN-JOURNALS-NEGLECTED' => false],
            'documents' => ['FIN-DOCS-MISSING' => false],
            'other' => [],
        ];
        foreach ($items as $slug => $codes) {
            $c = ObservationCategory::create(['department_id' => $this->finance->id, 'slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug, 'default_kind' => 'issue', 'active' => true]);
            $this->cat[$slug] = $c->id;
            foreach ($codes as $code => $amount) {
                $this->item[$code] = ObservationCatalogItem::create([
                    'category_id' => $c->id, 'code' => $code, 'label_ar' => $code, 'label_en' => $code, 'kind' => 'issue',
                    'risk_level_id' => RiskLevel::bySlug('high')->id, 'responsible_department_id' => $operation->id,
                    'extra_fields' => $amount ? [['key' => 'amount', 'type' => 'number', 'label_ar' => 'المبلغ']] : [],
                ])->id;
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_mapper_reads_branch_names_columns_problems_amounts_and_periods(): void
    {
        $this->assertSame('Nasr City - City Stars Mall', FinanceReportMapper::branch('سيتى ستار'));
        $this->assertSame('Nasr City - City Stars Mall', FinanceReportMapper::branch('City Stars'));
        $this->assertSame('6th of October - Mall of Egypt 2', FinanceReportMapper::branch('مول مصر'));
        $this->assertSame('6th of October - Mall of Egypt 2', FinanceReportMapper::branch('MOE 2'));
        $this->assertSame('El Rehab - The Yard Mall', FinanceReportMapper::branch('الرحاب'));
        $this->assertSame('El Rehab - The Yard Mall', FinanceReportMapper::branch('THE Yard'));
        $this->assertNull(FinanceReportMapper::branch('Nowhere'));

        $this->assertSame('documents', FinanceReportMapper::column('مستندات يوميه / ايصالات مصاريف ناقصة'));
        $this->assertSame('expenses', FinanceReportMapper::column('عدم تنفيذ التوجيهات للمصاريف'));
        $this->assertSame('journals', FinanceReportMapper::column('اهمال تقفيل اليوميات والمستندات'));
        $this->assertSame('emails', FinanceReportMapper::column('الإيميلات المرسلة / المطلوبة'));
        $this->assertNull(FinanceReportMapper::column('عدد المشاكل'));

        $this->assertSame(['FIN-RETURN-LATE', 'returns'], FinanceReportMapper::problem('عمل مرتجع بعد 14يوم بدون موافقه او ميل'));
        $this->assertSame(['FIN-RETURN-LOST', 'returns'], FinanceReportMapper::problem('مرتجع مفقود بقيمه 165'));
        $this->assertSame(['FIN-CASH-SHORTAGE', 'cash_variance'], FinanceReportMapper::problem('عجز باليومية بقيمة 480ج'));
        $this->assertSame(['FIN-ZREPORT', 'journals'], FinanceReportMapper::problem('نسيان تقفيل Zreport'));
        $this->assertSame(['FIN-CASH-PERSONAL-PAYMENT', 'expenses'], FinanceReportMapper::problem('سداد مبلغ شخصى من الموظف على مصروف للفرع'));

        $this->assertSame(480, FinanceReportMapper::amount('عجز باليومية بقيمة 480ج'));
        $this->assertSame(165, FinanceReportMapper::amount('مرتجع مفقود بقيمه 165'));
        [$from, $to] = FinanceReportMapper::period('من: 19 /.10 /.2026        إلى: 24/.10/.2026');
        $this->assertSame(['2026-10-19', '2026-10-24'], [$from->toDateString(), $to->toDateString()]);
        $this->assertSame([null, '2026-10-11'], [FinanceReportMapper::period('من: ....../....../......  إلى: 11/.10/.2026')[0], FinanceReportMapper::period('من: ....../....../......  إلى: 11/.10/.2026')[1]->toDateString()]);
    }

    public function test_the_import_writes_one_review_per_followed_branch_and_lays_the_detail_on_the_tick(): void
    {
        $path = $this->report('من: 11 /.10 /.2026 إلى: 18/.10/.2026',
            [['سيتى ستارز', ['returns', 'cash_variance']], ['زايد', ['journals']]],
            [['City Stars', 'عجز باليومية بقيمة 480ج', 'تم ارسال المشكله علي الجروب لقسم منع الخسائر'], ['mds', 'مرتجع بدون مستند وبدون ارسال ميل', 'تم ارسال المشكله لقسم منع الخسائر']]);

        $this->artisan('finance:import-weeks', ['paths' => [$path]])->assertSuccessful();

        // City Stars, Zayed, Abbas 1 (clean) are followed; MDS is not, but has a problem so it was reviewed too.
        $this->assertSame(4, ObservationVisit::where('department_id', $this->finance->id)->count());
        $shortage = Observation::where('catalog_item_id', $this->item['FIN-CASH-SHORTAGE'])->sole();
        $this->assertSame(['amount' => 480, 'action' => 'تم ارسال المشكله علي الجروب لقسم منع الخسائر', 'lp_referred' => true], $shortage->extra_values);
        $this->assertSame('عجز باليومية بقيمة 480ج', $shortage->description);
        $this->assertSame('2026-10-11', $shortage->event_date->toDateString());
        $this->assertSame(4, Observation::where('reporting_department_id', $this->finance->id)->count(), '3 ticks + MDS');
        $this->assertSame(0, ObservationVisit::where('branch_id', $this->b['Nasr City - Abbas El Akkad']->id)->withCount('observations')->sole()->observations_count);

        $c = FinanceReport::compliance(FinanceReport::reviews(User::where('email', 'import@levoile.local')->sole()->forceFill(['role_id' => \App\Models\Role::where('slug', 'super_admin')->value('id')])));
        $this->assertSame(['reviewed' => 4, 'clean' => 1, 'critical' => 0, 'follow' => 3, 'rate' => 25, 'branches' => 4], $c);

        // Again: nothing new.
        $this->artisan('finance:import-weeks', ['paths' => [$path]])->assertSuccessful();
        $this->assertSame(4, ObservationVisit::where('department_id', $this->finance->id)->count());
    }

    public function test_a_clean_review_and_a_review_with_problems(): void
    {
        $zayed = $this->b['Sheikh Zayed - Saraya Mall'];
        $city = $this->b['Nasr City - City Stars Mall'];

        $this->actingAs($this->recorder)->post(route('finance.store'), ['branch_id' => $zayed->id, 'review_date' => '2026-10-20'])
            ->assertRedirect(route('finance.create', ['month' => '2026-10', 'week' => 3]))->assertSessionHasNoErrors();
        $op = Department::where('slug', 'operation')->value('id');
        $this->actingAs($this->recorder)->post(route('finance.store'), ['branch_id' => $city->id, 'review_date' => '2026-10-20', 'problems' => [
            ['category_id' => $this->cat['returns'], 'catalog_item_id' => $this->item['FIN-RETURN-LOST'], 'amount' => '165', 'department_id' => Department::where('slug', 'lp')->value('id')],
            ['category_id' => $this->cat['other'], 'description' => 'شنطة عميل متروكة', 'department_id' => $op],
        ]])->assertSessionHasNoErrors();

        $lost = Observation::where('catalog_item_id', $this->item['FIN-RETURN-LOST'])->sole();
        $this->assertSame(['amount' => 165], $lost->extra_values, 'loss prevention is the department picked, not a tick');
        $this->assertSame(0, ObservationVisit::where('branch_id', $zayed->id)->withCount('observations')->sole()->observations_count);

        $page = $this->actingAs($this->recorder)->get(route('finance.summary', ['month' => '2026-10', 'week' => 3]))->assertOk();
        $this->assertSame(50, $page->viewData('compliance')['rate']);
        $this->assertSame(165.0, $page->viewData('money')['amount']);
        $this->assertSame(1, $page->viewData('money')['lp']);

        $create = $this->actingAs($this->recorder)->get(route('finance.create', ['month' => '2026-10', 'week' => 3]))->assertOk();
        $this->assertSame([], $create->viewData('state')[$zayed->id]['problems']);
        $this->assertCount(2, $create->viewData('state')[$city->id]['problems']);

        $list = $this->actingAs($this->recorder)->get(route('finance.index', ['lp' => 'yes']))->assertOk();
        $this->assertSame(1, $list->viewData('violations')->total());
    }

    public function test_a_problem_from_another_control_or_without_text_is_refused(): void
    {
        $city = $this->b['Nasr City - City Stars Mall'];

        $op = Department::where('slug', 'operation')->value('id');
        $this->actingAs($this->recorder)->post(route('finance.store'), ['branch_id' => $city->id, 'review_date' => '2026-10-20', 'problems' => [
            ['category_id' => $this->cat['journals'], 'catalog_item_id' => $this->item['FIN-RETURN-LOST'], 'department_id' => $op],
        ]])->assertSessionHasErrors('problems.0.catalog_item_id');
        $this->actingAs($this->recorder)->post(route('finance.store'), ['branch_id' => $city->id, 'review_date' => '2026-10-20', 'problems' => [
            ['category_id' => $this->cat['other'], 'description' => ' ', 'department_id' => $op],
        ]])->assertSessionHasErrors('problems.0.description');
        $this->actingAs($this->recorder)->post(route('finance.store'), ['branch_id' => $city->id, 'review_date' => '2026-10-20', 'problems' => [
            ['category_id' => $this->cat['journals'], 'catalog_item_id' => $this->item['FIN-JOURNALS-NEGLECTED']],
        ]])->assertSessionHasErrors('problems.0.department_id');
        $this->assertSame(0, ObservationVisit::count());
    }

    public function test_access_is_management_and_the_finance_staff(): void
    {
        $cctv = Seeds::user('department_employee', ['department_id' => Seeds::department('cctv', ['is_reporting' => true])->id]);
        // A CCTV member is sent back to the CCTV menu; anyone else outside finance is refused.
        $this->actingAs($cctv)->get(route('finance.summary'))->assertRedirect(route('cctv.summary'));
        $this->actingAs(Seeds::user('department_employee', ['department_id' => Department::where('slug', 'operation')->value('id')]))
            ->get(route('finance.create'))->assertForbidden();
        $this->actingAs(Seeds::user('ops_manager'))->get(route('finance.summary'))->assertOk();
        $this->actingAs($this->recorder)->get(route('finance.create'))->assertOk();
        $this->actingAs($this->recorder)->get(route('finance.index'))->assertOk();
        // A finance member works in the finance menu only.
        $this->actingAs($this->recorder)->get(route('dashboard'))->assertRedirect(route('finance.summary'));
        $this->actingAs($this->recorder)->get(route('tickets.index'))->assertRedirect(route('finance.summary'));
        $this->actingAs($this->recorder)->get(route('finance.summary'))->assertOk()
            ->assertSee(route('finance.create'), false)->assertDontSee(route('tickets.index'), false)->assertDontSee(route('cctv.summary'), false);
        $this->actingAs(Seeds::user('ops_manager'))->get(route('dashboard'))->assertOk();
    }

    public function test_each_problem_goes_to_the_department_picked_for_it_and_a_manager_s_review_opens_the_tickets(): void
    {
        $city = $this->b['Nasr City - City Stars Mall'];
        $lp = Department::where('slug', 'lp')->value('id');
        $op = Department::where('slug', 'operation')->value('id');
        $manager = Seeds::user('department_manager', ['department_id' => $this->finance->id, 'is_department_manager' => true]);

        $this->actingAs($manager)->post(route('finance.store'), ['branch_id' => $city->id, 'review_date' => '2026-10-20', 'problems' => [
            ['category_id' => $this->cat['cash_variance'], 'catalog_item_id' => $this->item['FIN-CASH-SHORTAGE'], 'amount' => '480', 'department_id' => $lp],
            ['category_id' => $this->cat['journals'], 'catalog_item_id' => $this->item['FIN-JOURNALS-NEGLECTED'], 'department_id' => $op],
        ]])->assertSessionHasNoErrors();

        $visit = ObservationVisit::sole();
        $this->assertSame('approved', $visit->status, 'the manager\'s own review needs no second approval');
        $shortage = Observation::where('catalog_item_id', $this->item['FIN-CASH-SHORTAGE'])->sole();
        $this->assertSame($lp, $shortage->responsible_department_id, 'the pick wins over the catalog default (operation)');
        $this->assertSame($lp, $shortage->ticket->department_id);
        $this->assertSame('open', $shortage->status);
        $this->assertSame($op, Observation::where('catalog_item_id', $this->item['FIN-JOURNALS-NEGLECTED'])->sole()->ticket->department_id);

        $this->assertSame(1, $this->actingAs($manager)->get(route('finance.index', ['department_id' => $lp]))->assertOk()->viewData('violations')->total());
    }

    public function test_the_responsible_department_answers_on_its_tasks_page_and_finance_approves_without_a_photo(): void
    {
        $op = Department::where('slug', 'operation')->first();
        $manager = Seeds::user('department_manager', ['department_id' => $this->finance->id, 'is_department_manager' => true]);
        $opStaff = Seeds::user('department_employee', ['department_id' => $op->id]);
        RiskLevel::bySlug('high')->update(['requires_field_verification' => true]);

        $this->actingAs($manager)->post(route('finance.store'), ['branch_id' => $this->b['Nasr City - City Stars Mall']->id, 'review_date' => '2026-10-20', 'problems' => [
            ['category_id' => $this->cat['journals'], 'catalog_item_id' => $this->item['FIN-JOURNALS-NEGLECTED'], 'department_id' => $op->id],
        ]])->assertSessionHasNoErrors();
        $ticket = Observation::sole()->ticket;

        // Operation sees it coming in and answers.
        $in = $this->actingAs($opStaff)->get(route('tasks.index'))->assertOk()->assertSee($ticket->reference);
        $this->assertSame(1, $in->viewData('counts')['incoming']);
        $this->actingAs($opStaff)->post(route('tickets.reply', $ticket), ['state' => 'solved', 'note' => 'اليوميات اتقفلت'])->assertSessionHasNoErrors();
        $this->assertSame('waiting_approval', $ticket->fresh()->status);

        // Finance (focused on its menu, yet the tasks page is open to it) approves and closes, no photo needed.
        $wait = $this->actingAs($manager)->get(route('tasks.index', ['tab' => 'approve']))->assertOk()->assertSee('اليوميات اتقفلت');
        $this->assertSame(1, $wait->viewData('counts')['approve']);
        $this->actingAs($manager)->post(route('tickets.transition', $ticket), ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame('verified_closed', Observation::sole()->status, 'the approval is the check for a desk review');
        $this->assertSame(1, $this->actingAs($manager)->get(route('tasks.index', ['tab' => 'closed']))->viewData('counts')['closed']);
        $this->actingAs($manager)->get(route('finance.summary'))->assertSee(route('tasks.index'), false);
    }

    /** A weekly report as the finance team writes it: period table, matrix of ticks, problem list. */
    private function report(string $period, array $matrix, array $problems): string
    {
        $cell = fn ($t) => '<w:tc><w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($t).'</w:t></w:r></w:p></w:tc>';
        $row = fn (array $cells) => '<w:tr>'.implode('', array_map($cell, $cells)).'</w:tr>';
        $table = fn (array $rows) => '<w:tbl>'.implode('', array_map($row, $rows)).'</w:tbl>';
        $columns = ['returns' => 'مرتجعات بدون فواتير وميل / اكسكلوزيف', 'float' => 'تسويه العهده', 'cash_variance' => 'عجز / زيادة',
            'freezes' => 'مشاكل التجميدات', 'journals' => 'اهمال تقفيل اليوميات والمستندات', 'expenses' => 'عدم تنفيذ التوجيهات للمصاريف',
            'emails' => 'الإيميلات المرسلة / المطلوبة', 'documents' => 'مستندات يوميه / ايصالات مصاريف ناقصة'];

        $body = $table([['رقم الأسبوع', 'الثاني', 'الفترة', $period]])
            .$table(array_merge([array_merge(['الفرع'], array_values($columns), ['عدد المشاكل'])],
                array_map(fn ($m) => array_merge([$m[0]], array_map(fn ($slug) => in_array($slug, $m[1], true) ? '✓' : '', array_keys($columns)), [(string) count($m[1])]), $matrix)))
            .$table(array_merge([['م', 'الفرع', 'المشكلة / المخالفة', 'الإجراء التصحيحي']],
                array_map(fn ($p, $i) => [(string) ($i + 1), $p[0], $p[1], $p[2]], $problems, array_keys($problems))));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>';

        $path = tempnam(sys_get_temp_dir(), 'fin').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $path;
    }
}
