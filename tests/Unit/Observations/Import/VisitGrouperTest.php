<?php

namespace Tests\Unit\Observations\Import;

use App\Services\Observations\Import\BranchMapper;
use App\Services\Observations\Import\CatalogMapper;
use App\Services\Observations\Import\DateMapper;
use App\Services\Observations\Import\DepartmentMapper;
use App\Services\Observations\Import\KindStatusMapper;
use App\Services\Observations\Import\RowMapper;
use App\Services\Observations\Import\VisitGrouper;
use Tests\TestCase;

class VisitGrouperTest extends TestCase
{
    private function rowMapper(): RowMapper
    {
        return new RowMapper(
            new BranchMapper(['Nasr City - City Stars Mall' => 7, 'Nasr City - Gnena Mall' => 6, 'Sheikh Zayed - Saraya Mall' => 20]),
            new DepartmentMapper(['cctv' => 8, 'merchandise' => 2, 'it' => 7, 'operation' => 5, 'maintenance' => 4, 'lp' => 12]),
            new CatalogMapper(collect()),
            new KindStatusMapper,
            new DateMapper,
        );
    }

    /** A Central Issues row as the reader returns it. */
    private function raw(array $overrides = []): array
    {
        return array_merge([
            'row' => 2, 'ID' => 'LV-0001', 'Week' => 'W2', 'Period Start' => '2026-10-11', 'Period End' => '2026-10-17',
            'Department' => 'CCTV', 'Branch' => 'City Stars', 'Event Date (Verified)' => '2026-10-13', 'Date Raw' => '',
            'Reported By' => 'مصطفى وليد', 'Responsible Department' => 'Operations / Loss Prevention',
            'Category' => 'مخالفات تتعلق بمنطقة الفلور', 'Finding / Activity' => 'عدم ارتداء الموظفه النيم تاج',
            'Corrective Action' => 'مراجعة المخالفة', 'Status / Rating' => 'Reported', 'Record Type' => 'Violation',
            'Source File' => 'Action Plan_ CCTV.XLSX', 'Source Row' => '5', 'Notes' => '',
        ], $overrides);
    }

    public function test_groups_by_department_branch_date(): void
    {
        $m = $this->rowMapper();
        $rows = collect([
            $m->map($this->raw(['row' => 2, 'Reported By' => 'مصطفى وليد'])),
            $m->map($this->raw(['row' => 3, 'Reported By' => 'عادل محمد, مصطفى وليد'])),
            $m->map($this->raw(['row' => 4, 'Reported By' => ''])),
            $m->map($this->raw(['row' => 5, 'Event Date (Verified)' => '2026-10-14'])),
            $m->map($this->raw(['row' => 6, 'Branch' => 'Zayed'])),
            $m->map($this->raw(['row' => 7, 'Department' => 'Merchandising', 'Branch' => 'City Stars', 'Category' => 'VM', 'Record Type' => 'Issue', 'Status / Rating' => 'Open'])),
        ]);

        $groups = (new VisitGrouper)->group($rows);

        $this->assertCount(4, $groups);
        $first = $groups->first();
        $this->assertSame('8|7|2026-10-13', $first['key']);
        $this->assertSame('field', $first['type']);
        $this->assertSame(8, $first['department_id']);
        $this->assertSame(7, $first['branch_id']);
        $this->assertSame('2026-10-13', $first['visit_date']);
        $this->assertSame([2, 3, 4], array_column($first['rows'], 'row'), 'reporter spelling never splits a visit');
        $this->assertNull($first['started_at']);

        $this->assertSame(['8|7|2026-10-14', '8|20|2026-10-13', '2|7|2026-10-13'], $groups->slice(1)->pluck('key')->values()->all());
    }

    public function test_raw_serial_date_outside_week_is_dropped(): void
    {
        $m = $this->rowMapper();
        $outside = $m->map($this->raw(['row' => 10, 'Event Date (Verified)' => '', 'Date Raw' => '46336']));
        $inside = $m->map($this->raw(['row' => 11, 'Event Date (Verified)' => '', 'Date Raw' => '46308']));
        $none = $m->map($this->raw(['row' => 12, 'Event Date (Verified)' => '', 'Date Raw' => '', 'Week' => 'W1', 'Period Start' => '2026-10-04', 'Period End' => '2026-10-10']));

        $this->assertNull($outside['event_date']);
        $this->assertSame('2026-11-10', $outside['raw_date_dropped']?->toDateString());
        $this->assertStringContainsString('46336', $outside['description']);
        $this->assertStringContainsString('خارج الأسبوع', $outside['description']);
        $this->assertSame('2026-10-13', $inside['event_date']?->toDateString());
        $this->assertNull($inside['raw_date_dropped']);

        $groups = (new VisitGrouper)->group(collect([$outside, $inside, $none]));

        $this->assertCount(3, $groups);
        $desk = $groups->firstWhere('key', '8|7|2026-10-11|undated');
        $this->assertNotNull($desk);
        $this->assertSame('desk', $desk['type']);
        $this->assertSame(7, $desk['branch_id']);
        $this->assertSame('2026-10-11', $desk['visit_date']);
        $this->assertStringContainsString('تاريخ غير مؤكد', $desk['general_notes']);
        $this->assertSame([10], array_column($desk['rows'], 'row'));

        $this->assertSame('field', $groups->firstWhere('key', '8|7|2026-10-13')['type']);
        $this->assertSame('2026-10-04', $groups->firstWhere('key', '8|7|2026-10-04|undated')['visit_date']);
    }

    public function test_all_branches_rows_form_a_desk_visit_per_week(): void
    {
        $m = $this->rowMapper();
        $a = $m->map($this->raw(['row' => 20, 'Department' => 'IT', 'Branch' => 'All branches', 'Event Date (Verified)' => '', 'Category' => 'Operational follow-up', 'Record Type' => 'Activity / Plan', 'Status / Rating' => 'Reported']));
        $b = $m->map($this->raw(['row' => 21, 'Department' => 'IT', 'Branch' => 'All branches', 'Event Date (Verified)' => '', 'Category' => 'Operational follow-up', 'Record Type' => 'Reported Finding', 'Status / Rating' => 'Reported']));
        $c = $m->map($this->raw(['row' => 22, 'Department' => 'IT', 'Branch' => 'All branches', 'Event Date (Verified)' => '', 'Week' => 'W1', 'Period Start' => '2026-10-04', 'Period End' => '2026-10-10', 'Category' => 'Operational follow-up', 'Record Type' => 'Issue', 'Status / Rating' => 'Open']));

        $this->assertTrue($a['all_branches']);
        $this->assertNull($a['branch_id']);
        $this->assertSame('activity', $b['kind'], 'Reported Finding without a branch is an activity');
        $this->assertSame('activity', $c['kind'], 'an Issue without a branch is forced to activity');
        $this->assertFalse($c['creates_ticket']);
        $this->assertStringContainsString('كل الفروع', $c['description']);

        $groups = (new VisitGrouper)->group(collect([$a, $b, $c]));

        $this->assertSame(['7|ALL|2026-10-11', '7|ALL|2026-10-04'], $groups->pluck('key')->all());
        $this->assertSame('desk', $groups->first()['type']);
        $this->assertNull($groups->first()['branch_id']);
        $this->assertSame([20, 21], array_column($groups->first()['rows'], 'row'));
    }

    public function test_merch_visits_get_times(): void
    {
        $m = $this->rowMapper();
        $rows = collect([
            $m->map($this->raw(['row' => 2, 'Department' => 'Merchandising', 'Branch' => 'جنينة', 'Category' => 'VM', 'Record Type' => 'Positive', 'Status / Rating' => 'Positive', 'Source File' => 'Existing Merchandising database', 'Source Row' => '2'])),
            $m->map($this->raw(['row' => 3, 'Department' => 'Merchandising', 'Branch' => 'جنينة', 'Category' => 'Quality', 'Record Type' => 'Issue', 'Status / Rating' => 'Open', 'Source File' => 'Existing Merchandising database', 'Source Row' => '4'])),
            $m->map($this->raw(['row' => 4, 'Department' => 'Merchandising', 'Branch' => 'سيتي ستارز', 'Category' => 'VM', 'Record Type' => 'Issue', 'Status / Rating' => 'Open'])),
        ]);
        $visits = collect([
            $m->mapVisit(['row' => 2, 'Visit ID' => 'V001', 'Branch' => 'جنينة', 'Visit Date' => '2026-10-13', 'Start' => '12:30', 'End' => '15:00', 'Merchandiser' => 'مايكل', 'Branch Responsible' => 'صباح - يوسف', 'Overall / Notes' => 'الحالة العامة ممتازة', 'Department' => 'Merchandising']),
        ]);

        $groups = (new VisitGrouper)->group($rows, $visits);

        $gnena = $groups->firstWhere('key', '2|6|2026-10-13');
        $this->assertSame('2026-10-13 12:30:00', $gnena['started_at']?->toDateTimeString());
        $this->assertSame('2026-10-13 15:00:00', $gnena['submitted_at']?->toDateTimeString());
        $this->assertStringContainsString('الحالة العامة ممتازة', $gnena['general_notes']);
        $this->assertStringContainsString('مايكل', $gnena['general_notes']);
        $this->assertCount(2, $gnena['rows']);

        $city = $groups->firstWhere('key', '2|7|2026-10-13');
        $this->assertNull($city['started_at']);
        $this->assertNull($city['general_notes']);
    }

    public function test_row_mapper_enriches_merch_rows_and_parses_minutes(): void
    {
        $m = $this->rowMapper();

        $late = $m->map($this->raw(['Category' => 'مخالفات تتعلق بالتأخير عن ميعاد فتح الفرع', 'Finding / Activity' => 'تاخير الموظفه 32دقيقه عن ميعاد فتح الفرع']));
        $this->assertSame(['minutes_late' => 32], $late['extra_values']);
        $hours = $m->map($this->raw(['Category' => 'مخالفات تتعلق بالتأخير عن ميعاد فتح الفرع', 'Finding / Activity' => 'تأخير الموظفة(ساعتين)عن ميعاد فتح الفرع']));
        $this->assertSame(['minutes_late' => 120], $hours['extra_values']);
        $this->assertNull($m->map($this->raw())['extra_values']);

        $original = ['row' => 4, 'Observation ID' => 'O003', 'Visit ID' => 'V001', 'Category' => 'Quality', 'Product / Area' => 'البديهات البيضاء', 'Finding' => 'آثار ميك أب', 'Action / Follow-up' => 'فحص وعزل المتأثر', 'Before' => 'ألوان متأثرة', 'Target / After' => 'بدون آثار', 'Action Status' => 'Open'];
        $merch = $m->map($this->raw(['Department' => 'Merchandising', 'Branch' => 'جنينة', 'Category' => 'Quality', 'Record Type' => 'Issue', 'Status / Rating' => 'Open', 'Finding / Activity' => 'آثار ميك أب', 'Corrective Action' => 'فحص وعزل المتأثر', 'Source File' => 'Existing Merchandising database', 'Source Row' => '4']), $original);

        $this->assertSame('البديهات البيضاء', $merch['product_or_area']);
        $this->assertStringContainsString('قبل: ألوان متأثرة', $merch['description']);
        $this->assertStringContainsString('الهدف: بدون آثار', $merch['description']);
        $this->assertSame('آثار ميك أب', $merch['title']);
        $this->assertSame(5, $merch['responsible_department_id'], 'no source responsible: operation');

        $maintenance = $m->map($this->raw(['Department' => 'Maintenance', 'Branch' => 'City Stars', 'Responsible Department' => 'Maintenance', 'Category' => 'كهرباء', 'Record Type' => 'Maintenance Task', 'Status / Rating' => 'جاري العمل']));
        $this->assertSame(4, $maintenance['responsible_department_id']);
        $cctv = $m->map($this->raw());
        $this->assertSame(5, $cctv['responsible_department_id']);
        $this->assertSame([12], $cctv['cc_department_ids']);
        $this->assertStringContainsString('المُبلِّغ: مصطفى وليد', $cctv['description']);
        $this->assertStringContainsString('الإجراء: مراجعة المخالفة', $cctv['description']);
    }
}
