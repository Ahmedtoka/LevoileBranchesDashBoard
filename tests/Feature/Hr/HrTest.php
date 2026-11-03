<?php

namespace Tests\Feature\Hr;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\Seeds;
use Tests\TestCase;

/** 2026-11-03: the HR menu: staff directory, employee page, uploading the HR export with a preview. */
class HrTest extends TestCase
{
    private Department $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = Seeds::department('hr', ['is_reporting' => true]);
        $zayed = Seeds::branch('Sheikh Zayed - Saraya Mall', ['active' => true]);
        Employee::create(['code' => '1001', 'name' => 'مصطفي احمد', 'job_title' => 'Area Manager', 'sector' => 'Retail', 'department' => 'Stores Operations', 'work_location' => 'Head Office']);
        Employee::create(['code' => '3001', 'name' => 'حبيبه سامح', 'job_title' => 'Sales', 'manager_code' => '1001', 'manager_name' => 'مصطفي احمد', 'branch_id' => $zayed->id, 'work_location' => 'Zayed', 'sector' => 'Retail', 'department' => 'Stores Operations', 'phone' => '0100']);
        Employee::create(['code' => '4001', 'name' => 'محمد توفيق', 'job_title' => 'WH Picker', 'work_location' => 'El-Obour', 'sector' => 'Supply Chain', 'department' => 'Warehouse']);
    }

    public function test_the_directory_filters_and_searches_by_name_and_code(): void
    {
        $staff = Seeds::user('department_employee', ['department_id' => $this->hr->id]);

        $page = $this->actingAs($staff)->get(route('hr.summary'))->assertOk();
        $this->assertSame(3, $page->viewData('stats')['active']);
        $this->assertSame(1, $page->viewData('stats')['branches']);
        $this->assertSame(1, $page->viewData('stats')['managers']);

        $this->assertSame(['3001'], $this->actingAs($staff)->get(route('hr.summary', ['q' => '300']))->viewData('employees')->pluck('code')->all());
        $this->assertSame(['3001'], $this->actingAs($staff)->get(route('hr.summary', ['q' => 'حبيبه']))->viewData('employees')->pluck('code')->all());
        $this->assertSame(['4001'], $this->actingAs($staff)->get(route('hr.summary', ['sector' => 'Supply Chain']))->viewData('employees')->pluck('code')->all());
        $this->assertSame(['3001'], $this->actingAs($staff)->get(route('hr.summary', ['manager_code' => '1001']))->viewData('employees')->pluck('code')->all());
        $this->assertSame(['3001'], $this->actingAs($staff)->get(route('hr.summary', ['place' => 'branches']))->viewData('employees')->pluck('code')->all());

        $manager = Employee::where('code', '1001')->sole();
        $profile = $this->actingAs($staff)->get(route('hr.show', $manager))->assertOk()->assertSee('حبيبه سامح');
        $this->assertCount(1, $profile->viewData('team'));
        $this->actingAs($staff)->get(route('hr.export'))->assertOk();
    }

    public function test_an_upload_shows_the_changes_first_and_saves_on_confirm(): void
    {
        Storage::fake('local');
        $staff = Seeds::user('department_employee', ['department_id' => $this->hr->id]);
        $file = $this->file([['1001', 'مصطفي احمد', 'Area Manager', 'Head Office'], ['3001', 'حبيبه سامح', 'Senior Sales', 'Zayed'], ['5001', 'سارة علي', 'Cashier', 'Zayed']]);

        $preview = $this->actingAs($staff)->post(route('hr.preview'), ['file' => $file])->assertOk()->assertSee('سارة علي');
        $plan = $preview->viewData('plan');
        $this->assertSame([['5001'], ['3001'], ['4001']], [$plan['new'], $plan['changed'], $plan['gone']]);
        $this->assertSame(3, Employee::count(), 'nothing written by the preview');

        $this->actingAs($staff)->post(route('hr.apply'), ['token' => $preview->viewData('token')])->assertRedirect(route('hr.summary'));
        $this->assertSame('Senior Sales', Employee::where('code', '3001')->sole()->job_title);
        $this->assertFalse(Employee::where('code', '4001')->sole()->active);
        $this->assertSame(4, Employee::count());
    }

    public function test_access_is_management_and_hr_and_hr_works_in_its_menu_only(): void
    {
        $other = Seeds::user('department_employee', ['department_id' => Seeds::department('it', ['is_reporting' => true])->id]);
        $this->actingAs($other)->get(route('hr.summary'))->assertForbidden();
        $this->actingAs(Seeds::user('area_manager'))->get(route('hr.import'))->assertForbidden();
        $this->actingAs(Seeds::user('ops_manager'))->get(route('hr.import'))->assertOk();

        $staff = Seeds::user('department_employee', ['department_id' => $this->hr->id]);
        $this->actingAs($staff)->get(route('dashboard'))->assertRedirect(route('hr.summary'));
        $this->actingAs($staff)->get(route('hr.summary'))->assertSee(route('hr.import'), false)->assertDontSee(route('tickets.index'), false);
    }

    private function file(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray(array_merge(
            [['#', 'الكود', 'الاسم', 'الوظيفة', 'مكان العمل', 'الحالة']],
            array_map(fn ($r) => ['1', $r[0], $r[1], $r[2], $r[3], 'نشط'], $rows)
        ), null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'hr').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'users.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
