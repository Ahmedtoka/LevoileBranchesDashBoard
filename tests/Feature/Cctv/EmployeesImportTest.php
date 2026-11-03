<?php

namespace Tests\Feature\Cctv;

use App\Models\Employee;
use App\Services\Hr\EmployeesImporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\Seeds;
use Tests\TestCase;

/** 2026-11-03: the HR staff export, matched onto the first names the CCTV sheets carried. */
class EmployeesImportTest extends TestCase
{
    public function test_names_match_whole_words_with_spellings_folded(): void
    {
        $this->assertTrue(EmployeesImporter::startsWithName('منه الله تامر عبدالمنعم', 'منة الله'));
        $this->assertTrue(EmployeesImporter::startsWithName('عبد الرحمن سيد', 'عبدالرحمن'));
        $this->assertFalse(EmployeesImporter::startsWithName('منه الله تامر', 'منه تامر'));
        $this->assertFalse(EmployeesImporter::startsWithName('محمدين علي', 'محمد'));
    }

    public function test_the_import_links_cctv_names_merges_spellings_and_updates_by_code(): void
    {
        $zayed = Seeds::branch('Sheikh Zayed - Saraya Mall', ['active' => true]);
        $city = Seeds::branch('Nasr City - City Stars Mall', ['active' => true]);
        $habiba = Employee::create(['name' => 'حبيبه سامح', 'branch_id' => $city->id]); // moved to Zayed since
        $shahd = Employee::create(['name' => 'شهد', 'branch_id' => $city->id]);
        $shahd2 = Employee::create(['name' => 'شهد اشرف', 'branch_id' => $city->id]);

        $path = $this->file([
            ['3001', 'حبيبه سامح صلاح احمد', 'Sales', 'Zayed'],
            ['3002', 'شهد اشرف محمد طه', 'Cashier', 'City Stars'],
            ['3003', 'محمد توفيق شحاته', 'WH Picker', 'El-Obour'],
        ]);
        $this->artisan('employees:import', ['path' => $path])->assertSuccessful();

        $this->assertSame(['3001', 'حبيبه سامح صلاح احمد', $zayed->id], [$habiba->fresh()->code, $habiba->fresh()->name, $habiba->fresh()->branch_id]);
        $this->assertSame('3002', $shahd->fresh()->code);
        $this->assertNull($shahd2->fresh(), 'the other spelling is merged');
        $this->assertNull(Employee::where('code', '3003')->sole()->branch_id);
        $this->assertSame('El-Obour', Employee::where('code', '3003')->sole()->work_location);

        // Again with a change: updated by code, nothing duplicated; someone gone is made inactive.
        $plan = app(EmployeesImporter::class)->plan($this->file([['3001', 'حبيبه سامح صلاح احمد', 'Senior Sales', 'Zayed'], ['3002', 'شهد اشرف محمد طه', 'Cashier', 'City Stars']]));
        $this->assertSame([[], ['3001'], ['3003']], [$plan['new'], $plan['changed'], $plan['gone']]);
        app(EmployeesImporter::class)->apply($plan);
        $this->assertSame(3, Employee::count());
        $this->assertSame('Senior Sales', $habiba->fresh()->job_title);
        $this->assertFalse(Employee::where('code', '3003')->sole()->active);
    }

    public function test_cctv_names_that_arrive_after_the_staff_file_merge_into_the_coded_employee(): void
    {
        $city = Seeds::branch('Nasr City - City Stars Mall', ['active' => true]);
        $path = $this->file([['3002', 'شهد اشرف محمد طه', 'Cashier', 'City Stars']]);
        $this->artisan('employees:import', ['path' => $path])->assertSuccessful();
        $coded = Employee::where('code', '3002')->sole();

        // The CCTV import comes later and writes the first name only.
        $short = Employee::create(['name' => 'شهد اشرف', 'branch_id' => $city->id]);
        $this->artisan('employees:import', ['path' => $path])->assertSuccessful();

        $this->assertNull($short->fresh(), 'merged into the employee already on file');
        $this->assertSame(1, Employee::count());
        $this->assertSame('شهد اشرف محمد طه', $coded->fresh()->name);
    }

    private function file(array $rows): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray(array_merge(
            [['#', 'الكود', 'الاسم', 'كود المدير المباشر', 'المدير المباشر', 'الوظيفة', 'القسم', 'الإدارة', 'القطاع', 'مكان العمل', 'الحالة', 'رقم الهاتف']],
            array_map(fn ($r) => ['1', $r[0], $r[1], '1001', 'مدير', $r[2], 'Store Operations', 'Stores Operations', 'Retail', $r[3], 'نشط', '010'], $rows)
        ), null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'hr').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
