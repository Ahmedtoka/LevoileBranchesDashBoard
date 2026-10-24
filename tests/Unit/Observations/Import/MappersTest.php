<?php

namespace Tests\Unit\Observations\Import;

use App\Models\Department;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Services\Observations\Import\BranchMapper;
use App\Services\Observations\Import\CatalogMapper;
use App\Services\Observations\Import\DateMapper;
use App\Services\Observations\Import\DepartmentMapper;
use App\Services\Observations\Import\KindStatusMapper;
use App\Services\Observations\Import\UnknownBranch;
use App\Services\Observations\Import\UnknownDepartment;
use App\Services\Observations\Import\WorkbookReader;
use Database\Seeders\ObservationSetupSeeder;
use Tests\Support\Seeds;
use Tests\TestCase;

class MappersTest extends TestCase
{
    private function branchMapper(): BranchMapper
    {
        return new BranchMapper([
            'Nasr City - City Stars Mall' => 7,
            '6th of October - Mall of Egypt Store' => 18,
            '6th of October - Mall of Egypt 2' => 27,
            'Nasr City - Abbas El Akkad / Ezzat Salama' => 3,
            'Nasr City - Abbas El Akkad' => 5,
            'Sheikh Zayed - Saraya Mall' => 20,
            'El Mokattam - Mokattam 2' => 14,
            'Shehab Store' => 28,
            'Gamal Abd El Nasser Store' => 29,
            'MDS Store' => 30,
        ]);
    }

    public function test_branch_aliases_from_spec(): void
    {
        $m = $this->branchMapper();

        $this->assertSame(7, $m->toBranchId('سيتي ستارز'));
        $this->assertSame(7, $m->toBranchId('City Stars'));
        $this->assertSame(27, $m->toBranchId('MOE 2'));
        $this->assertSame(18, $m->toBranchId('مول مصر'));
        $this->assertSame(3, $m->toBranchId('عباس2'));
        $this->assertSame(3, $m->toBranchId('عباس 2'));
        $this->assertSame(5, $m->toBranchId('Abbas 1'));
        $this->assertSame(20, $m->toBranchId(' zayed '));
        $this->assertSame(14, $m->toBranchId('Mokkatm 2'));
        $this->assertSame(28, $m->toBranchId('شهاب'));
        $this->assertSame(28, $m->toBranchId('Shehab'));
        $this->assertSame(29, $m->toBranchId('Gamal abd el nasser'));
        $this->assertSame(30, $m->toBranchId('mds'));
        $this->assertSame(BranchMapper::ALL, $m->toBranchId('All branches'));
    }

    public function test_unknown_branch_throws_with_raw_name(): void
    {
        $m = $this->branchMapper();

        try {
            $m->toBranchId('Nowhere Mall');
            $this->fail('expected UnknownBranch');
        } catch (UnknownBranch $e) {
            $this->assertSame('Nowhere Mall', $e->rawName);
        }

        // A known alias whose target branch does not exist in the given table is unknown too.
        try {
            $m->toBranchId('Smouha');
            $this->fail('expected UnknownBranch');
        } catch (UnknownBranch $e) {
            $this->assertSame('Smouha', $e->rawName);
        }
    }

    public function test_branch_mapper_from_database_matches_short_and_full_names(): void
    {
        $b = Seeds::branch('Alexandria - Smouha');
        $m = BranchMapper::fromDatabase(['Shehab Store' => -1]);

        $this->assertSame($b->id, $m->toBranchId('Smouha'));
        $this->assertSame(-1, $m->toBranchId('شهاب'));
    }

    public function test_department_mapper(): void
    {
        $m = new DepartmentMapper(['cctv' => 8, 'stock_control' => 3, 'maintenance' => 4, 'merchandise' => 2, 'finance' => 13, 'it' => 7, 'hr' => 10, 'lp' => 12]);

        $this->assertSame(8, $m->toDepartmentId('CCTV'));
        $this->assertSame(3, $m->toDepartmentId('Stock Control'));
        $this->assertSame(2, $m->toDepartmentId('Merchandising'));
        $this->assertSame(12, $m->toDepartmentId('Loss Prevention'));
        $this->assertSame('merchandise', $m->toSlug('Merchandising'));

        $this->expectException(UnknownDepartment::class);
        $m->toDepartmentId('Legal');
    }

    public function test_kind_status_mapping(): void
    {
        $m = new KindStatusMapper;

        $r = $m->map('Violation', 'Reported');
        $this->assertSame(['issue', 'open', null, true, false], [$r['kind'], $r['status'], $r['rating'], $r['creates_ticket'], $r['ticket_closed']]);

        $r = $m->map('Issue', 'Pending Verification');
        $this->assertSame(['issue', 'waiting_verification', true, true], [$r['kind'], $r['status'], $r['creates_ticket'], $r['ticket_closed']]);

        $r = $m->map('Maintenance Task', 'Done');
        $this->assertSame(['activity', 'closed_no_action', false], [$r['kind'], $r['status'], $r['creates_ticket']]);

        // An unfinished maintenance task is still pending work: open with a ticket (spec section 13 status rule).
        $r = $m->map('Maintenance Task', 'جاري العمل');
        $this->assertSame(['issue', 'open', true], [$r['kind'], $r['status'], $r['creates_ticket']]);
        $r = $m->map('Maintenance Task', 'لم يتم الحل');
        $this->assertSame(['issue', 'open', true], [$r['kind'], $r['status'], $r['creates_ticket']]);

        $r = $m->map('Training', 'Training Completed');
        $this->assertSame(['activity', 'closed_no_action', false], [$r['kind'], $r['status'], $r['creates_ticket']]);
        $r = $m->map('Activity / Plan', 'Reported');
        $this->assertSame(['activity', 'closed_no_action', false], [$r['kind'], $r['status'], $r['creates_ticket']]);

        $r = $m->map('Assessment', 'جيد');
        $this->assertSame(['assessment', 'closed_no_action', 'good', false], [$r['kind'], $r['status'], $r['rating'], $r['creates_ticket']]);
        $r = $m->map('Assessment', 'متوسط');
        $this->assertSame(['assessment', 'closed_no_action', 'average', false], [$r['kind'], $r['status'], $r['rating'], $r['creates_ticket']]);
        $r = $m->map('Assessment', 'ضعيف');
        $this->assertSame(['assessment', 'open', 'poor', true], [$r['kind'], $r['status'], $r['rating'], $r['creates_ticket']]);

        // A Stock Control issue graded متوسط/ضعيف is an open issue that keeps the grade.
        $r = $m->map('Issue', 'متوسط');
        $this->assertSame(['issue', 'open', 'average', true], [$r['kind'], $r['status'], $r['rating'], $r['creates_ticket']]);

        $r = $m->map('Positive', 'Positive');
        $this->assertSame(['positive', 'positive', false], [$r['kind'], $r['status'], $r['creates_ticket']]);

        $r = $m->map('Reported Finding', 'Reported', null, true);
        $this->assertSame('issue', $r['kind']);
        $r = $m->map('Reported Finding', 'Reported', null, false);
        $this->assertSame(['activity', 'closed_no_action', false], [$r['kind'], $r['status'], $r['creates_ticket']]);

        // No branch: never an issue (spec decision 11), even when the source says Issue.
        $r = $m->map('Issue', 'Open', null, false);
        $this->assertSame(['activity', 'closed_no_action', false], [$r['kind'], $r['status'], $r['creates_ticket']]);
    }

    public function test_date_mapper(): void
    {
        $m = new DateMapper;

        $this->assertSame('2026-10-13', $m->eventDate('', '46308', '2026-10-11', '2026-10-17')?->toDateString());
        $this->assertSame('2026-10-13', $m->eventDate(null, '13-10-2026', '2026-10-11', '2026-10-17')?->toDateString());
        $this->assertSame('2026-10-13', $m->eventDate(null, '13 / 10 / 2026', '2026-10-11', '2026-10-17')?->toDateString());
        $this->assertSame('2026-10-12', $m->eventDate('2026-10-12', '46336', '2026-10-11', '2026-10-17')?->toDateString(), 'verified wins');

        // Raw serial outside the row's week is dropped.
        $this->assertNull($m->eventDate('', '46336', '2026-10-11', '2026-10-17'));
        $this->assertNull($m->eventDate('', '30 / 9 / 2026', '2026-10-11', '2026-10-17'));
        $this->assertNull($m->eventDate('', '18-10-2026', '2026-10-11', '2026-10-17'));
        $this->assertNull($m->eventDate('', '', '2026-10-11', '2026-10-17'));
        $this->assertNull($m->eventDate(null, null, '2026-10-11', '2026-10-17'));

        $this->assertSame('2026-11-10', $m->parseRaw('46336')?->toDateString());
        $this->assertNull($m->parseRaw('garbage'));
    }

    public function test_catalog_mapper(): void
    {
        foreach (['cctv', 'stock_control', 'finance', 'merchandise', 'hr', 'it', 'lp', 'maintenance', 'operation', 'vm', 'warehouse'] as $slug) {
            Seeds::department($slug);
        }
        $this->seed(ObservationSetupSeeder::class);
        $m = CatalogMapper::fromDatabase();
        $cctv = Department::where('slug', 'cctv')->value('id');
        $mtn = Department::where('slug', 'maintenance')->value('id');
        $mrc = Department::where('slug', 'merchandise')->value('id');
        $stk = Department::where('slug', 'stock_control')->value('id');

        $r = $m->match($cctv, 'مخالفات تتعلق بالتأخير عن ميعاد فتح الفرع', 'تأخير الموظفة(20 دقيقة)عن ميعاد فتح الفرع');
        $this->assertSame($this->categoryId($cctv, 'branch_opening'), $r['category_id']);
        $this->assertSame($this->itemId('CCTV-OPEN-LATE'), $r['catalog_item_id']);
        $this->assertTrue($r['matched']);

        $r = $m->match($cctv, 'مخالفات تتعلق بمنطقة الفلور', 'عدم ارتداء الموظفه النيم تاج');
        $this->assertSame($this->categoryId($cctv, 'floor'), $r['category_id']);
        $this->assertSame($this->itemId('CCTV-FLOOR-NO-NAMETAG'), $r['catalog_item_id']);

        $r = $m->match($cctv, 'مخالفات تتعلق بمنطقة الفلور', 'خروج الموظفة بريك بعد وقت البريك الفعلي');
        $this->assertSame($this->categoryId($cctv, 'floor'), $r['category_id']);
        $this->assertNull($r['catalog_item_id'], 'no keyword hit: category only');

        $r = $m->match($mtn, 'كهرباء', 'كشاف فاصل');
        $this->assertSame($this->itemId('MTN-ELECTRICAL'), $r['catalog_item_id']);
        $r = $m->match($mtn, 'نقاشه', 'نقاشة الحوائط');
        $this->assertSame($this->itemId('MTN-PAINTING'), $r['catalog_item_id']);
        $r = $m->match($mtn, 'سيكوريت وابواب', 'باب');
        $this->assertSame($this->itemId('MTN-DOORS'), $r['catalog_item_id']);

        $r = $m->match($mrc, 'VM', 'قطعة New Collection موجودة مع Sale');
        $this->assertSame($this->categoryId($mrc, 'vm'), $r['category_id']);
        $this->assertSame($this->itemId('MRC-VM-NEW-WITH-SALE'), $r['catalog_item_id']);
        $r = $m->match($mrc, 'VM / Pricing', 'ملاحظات تسعير');
        $this->assertSame($this->categoryId($mrc, 'vm'), $r['category_id']);
        $r = $m->match($mrc, 'Delivery / VM', 'لم يصل بعد');
        $this->assertSame($this->categoryId($mrc, 'delivery_replenishment'), $r['category_id']);

        // Unknown category falls back to the department's "other", unmatched.
        $r = $m->match($mrc, 'Capacity', 'كل الموديلات في 3 أدراج');
        $this->assertSame($this->categoryId($mrc, 'other'), $r['category_id']);
        $this->assertNull($r['catalog_item_id']);
        $this->assertFalse($r['matched']);

        // Items can be switched off (assessments keep the category only).
        $r = $m->match($stk, 'تنظيم المخزون داخل الفرع', 'المخزن غير منظم', false);
        $this->assertSame($this->categoryId($stk, 'stockroom_organization'), $r['category_id']);
        $this->assertNull($r['catalog_item_id']);
        $r = $m->match($stk, 'تنظيم المخزون داخل الفرع', 'المخزن غير منظم');
        $this->assertSame($this->itemId('STK-ORG-UNORGANIZED'), $r['catalog_item_id']);

        $r = $m->match($stk, 'Training', 'تدريب على شيت الجرد');
        $this->assertSame($this->categoryId($stk, 'training'), $r['category_id']);
        $this->assertSame($this->itemId('STK-TRN-COUNT-SHEET'), $r['catalog_item_id']);
    }

    public function test_workbook_reader_reads_the_three_sheets(): void
    {
        $data = (new WorkbookReader)->rows(database_path('data/levoile_control_center_oct2026.xlsx'));

        $this->assertCount(376, $data['issues']);
        $this->assertCount(45, $data['observations']);
        $this->assertCount(5, $data['visits']);

        $first = $data['issues']->first();
        $this->assertSame(2, $first['row']);
        $this->assertSame('LV-0001', $first['ID']);
        $this->assertSame('Merchandising', $first['Department']);
        $this->assertSame('جنينة', $first['Branch']);
        $this->assertSame('2026-10-13', $first['Event Date (Verified)']);
        $this->assertSame('2', $first['Source Row']);
        $this->assertArrayHasKey('Finding / Activity', $first);

        $this->assertSame('V001', $data['visits']->first()['Visit ID']);
        $this->assertSame('12:30', $data['visits']->first()['Start']);
        $this->assertSame('O001', $data['observations']->first()['Observation ID']);
        $this->assertSame(2, $data['observations']->first()['row']);
    }

    private function categoryId(int $departmentId, string $slug): int
    {
        return (int) ObservationCategory::where('department_id', $departmentId)->where('slug', $slug)->value('id');
    }

    private function itemId(string $code): int
    {
        return (int) ObservationCatalogItem::where('code', $code)->value('id');
    }
}
