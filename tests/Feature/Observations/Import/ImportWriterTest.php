<?php

namespace Tests\Feature\Observations\Import;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationVerification;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Services\Observations\Import\ImportWriter;
use App\Services\Observations\Import\RowMapper;
use App\Services\Observations\Import\VisitGrouper;
use Database\Seeders\ObservationSetupSeeder;
use Illuminate\Support\Carbon;
use Tests\Support\Seeds;
use Tests\TestCase;

class ImportWriterTest extends TestCase
{
    private const SOURCE = 'levoile_control_center_oct2026.xlsx';

    private Branch $gnena;

    private Branch $cityStars;

    private User $importUser;

    private RowMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-24 10:00:00');

        foreach (['cctv', 'stock_control', 'finance', 'merchandise', 'hr', 'it', 'lp', 'maintenance', 'operation', 'vm', 'warehouse'] as $slug) {
            Seeds::department($slug);
        }
        $this->seed(ObservationSetupSeeder::class);
        $this->gnena = Seeds::branch('Nasr City - Gnena Mall', ['active' => true]);
        $this->cityStars = Seeds::branch('Nasr City - City Stars Mall', ['active' => true]);
        Seeds::role('super_admin');

        $this->importUser = ImportWriter::ensureImportUser();
        $this->mapper = RowMapper::fromDatabase();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function raw(array $overrides = []): array
    {
        static $row = 1;
        $row++;

        return array_merge([
            'row' => $row, 'ID' => sprintf('LV-%04d', $row), 'Week' => 'W2', 'Period Start' => '2026-10-11', 'Period End' => '2026-10-17',
            'Department' => 'CCTV', 'Branch' => 'City Stars', 'Event Date (Verified)' => '2026-10-13', 'Date Raw' => '',
            'Reported By' => 'مصطفى وليد', 'Responsible Department' => 'Operations / Loss Prevention',
            'Category' => 'مخالفات تتعلق بمنطقة الفلور', 'Finding / Activity' => 'عدم ارتداء الموظفه النيم تاج',
            'Corrective Action' => 'مراجعة المخالفة', 'Status / Rating' => 'Reported', 'Record Type' => 'Violation',
            'Source File' => 'Action Plan_ CCTV.XLSX', 'Source Row' => '5', 'Notes' => '',
        ], $overrides);
    }

    private function write(array $raws, ?array $visits = null): array
    {
        $rows = collect($raws)->map(fn ($r) => $this->mapper->map($r));
        $groups = (new VisitGrouper)->group($rows, $visits ? collect($visits)->map(fn ($v) => $this->mapper->mapVisit($v)) : null);
        $writer = app(ImportWriter::class);

        $totals = ['visits' => 0, 'observations' => 0, 'tickets' => 0, 'skipped' => 0];
        foreach ($groups as $group) {
            foreach ($writer->write($group, $this->importUser, self::SOURCE) as $k => $v) {
                $totals[$k] = ($totals[$k] ?? 0) + $v;
            }
        }

        return $totals;
    }

    public function test_import_user_is_an_inactive_super_admin(): void
    {
        $this->assertSame('import@levoile.local', $this->importUser->email);
        $this->assertFalse((bool) $this->importUser->active);
        $this->assertTrue($this->importUser->hasRole('super_admin'));
        $this->assertSame($this->importUser->id, ImportWriter::ensureImportUser()->id, 'idempotent');
    }

    public function test_open_violation_creates_silent_ticket_due_from_event_date(): void
    {
        $totals = $this->write([$this->raw()]);

        $this->assertSame(['visits' => 1, 'observations' => 1, 'tickets' => 1, 'skipped' => 0], array_intersect_key($totals, ['visits' => 1, 'observations' => 1, 'tickets' => 1, 'skipped' => 0]));

        $visit = ObservationVisit::sole();
        $this->assertSame('import', $visit->source);
        $this->assertSame('field', $visit->type);
        $this->assertSame('approved', $visit->status);
        $this->assertSame($this->cityStars->id, $visit->branch_id);
        $this->assertSame($this->importUser->id, $visit->user_id);
        $this->assertSame('2026-10-13', $visit->visit_date->toDateString());

        $o = Observation::sole();
        $this->assertSame('issue', $o->kind);
        $this->assertSame('open', $o->status);
        $this->assertSame('2026-10-13', $o->event_date->toDateString());
        $this->assertSame(self::SOURCE, $o->import_source);
        $this->assertSame('2', $o->import_row);
        $this->assertSame('catalog', $o->classification_status);
        $this->assertSame(ObservationCatalogItem::where('code', 'CCTV-FLOOR-NO-NAMETAG')->value('id'), $o->catalog_item_id);
        $this->assertSame('عدم ارتداء الموظفه النيم تاج', $o->title);
        $this->assertStringContainsString('المُبلِّغ: مصطفى وليد', $o->description);
        $this->assertSame(Department::where('slug', 'operation')->value('id'), $o->responsible_department_id);
        $this->assertSame([Department::where('slug', 'lp')->value('id')], $o->cc_department_ids);

        $ticket = Ticket::sole();
        $this->assertSame('observation', $ticket->source);
        $this->assertSame($o->id, $ticket->observation_id);
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->assigned_to, 'silent: no auto-assign');
        $this->assertSame('low', $ticket->priority);
        // SLA counted from the event date, not from the import time.
        $this->assertSame('2026-10-20 00:00:00', $ticket->due_at->toDateTimeString());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_pending_verification_mapping(): void
    {
        $this->write([
            $this->raw(['Department' => 'Merchandising', 'Branch' => 'جنينة', 'Responsible Department' => '', 'Category' => 'Quality',
                'Finding / Activity' => 'آثار روج على قطعة بيضاء', 'Corrective Action' => 'فحص وعزل المتأثر', 'Status / Rating' => 'Pending Verification',
                'Record Type' => 'Issue', 'Source File' => 'Existing Merchandising database', 'Source Row' => '26']),
        ]);

        $o = Observation::sole();
        $this->assertSame('issue', $o->kind);
        $this->assertSame('waiting_verification', $o->status);
        $this->assertNotNull($o->ticket_id);

        $ticket = Ticket::sole();
        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->closed_at);
        $this->assertSame($o->ticket_id, $ticket->id);

        $updates = TicketUpdate::where('ticket_id', $ticket->id)->orderBy('id')->get();
        $this->assertTrue($updates->contains(fn ($u) => $u->action === 'evidence' && $u->evidence_kind === 'after' && $u->evidence_path === ImportWriter::PLACEHOLDER_AFTER));
        $this->assertTrue($updates->contains(fn ($u) => $u->action === 'status_change' && $u->to_status === 'closed' && $u->note === 'مستورد: تم الإجراء، بانتظار التحقق'));

        // The ticket-close sync must not auto-verify the imported row.
        $this->assertSame(0, ObservationVerification::count());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_done_maintenance_creates_no_ticket(): void
    {
        $totals = $this->write([
            $this->raw(['Department' => 'Maintenance', 'Branch' => 'City Stars', 'Responsible Department' => 'Maintenance', 'Category' => 'كهرباء',
                'Finding / Activity' => 'كشاف فاصل في الاستور', 'Corrective Action' => '', 'Status / Rating' => 'Done', 'Record Type' => 'Maintenance Task',
                'Source File' => 'تقرير صيانه الفروع.xlsx']),
            $this->raw(['Department' => 'Maintenance', 'Branch' => 'City Stars', 'Responsible Department' => 'Maintenance', 'Category' => 'نجارة',
                'Finding / Activity' => 'أدرج الكاشير تحتاج إلي تعديل', 'Corrective Action' => '', 'Status / Rating' => 'جاري العمل', 'Record Type' => 'Maintenance Task',
                'Source File' => 'تقرير صيانه الفروع.xlsx']),
            $this->raw(['Category' => 'مخالفات تتعلق بمنطقة الفلور', 'Status / Rating' => 'Done', 'Record Type' => 'Issue']),
        ]);

        $this->assertSame(1, $totals['tickets']);
        $this->assertSame(3, $totals['observations']);

        $done = Observation::where('title', 'كشاف فاصل في الاستور')->sole();
        $this->assertSame('activity', $done->kind);
        $this->assertSame('closed_no_action', $done->status);
        $this->assertNull($done->ticket_id);
        $this->assertNull($done->risk_level_id);
        $this->assertSame(ObservationCatalogItem::where('code', 'MTN-ELECTRICAL')->value('id'), $done->catalog_item_id);
        $this->assertSame('catalog', $done->classification_status);

        $pending = Observation::where('title', 'أدرج الكاشير تحتاج إلي تعديل')->sole();
        $this->assertSame('issue', $pending->kind);
        $this->assertSame('open', $pending->status);
        $this->assertNotNull($pending->ticket_id);
        $this->assertSame(Department::where('slug', 'maintenance')->value('id'), Ticket::find($pending->ticket_id)->department_id);

        $doneIssue = Observation::where('title', 'عدم ارتداء الموظفه النيم تاج')->sole();
        $this->assertSame('issue', $doneIssue->kind);
        $this->assertSame('closed_no_action', $doneIssue->status);
        $this->assertNull($doneIssue->ticket_id);
        $this->assertSame(1, Ticket::count());
    }

    public function test_all_branches_activity_no_ticket(): void
    {
        $totals = $this->write([
            $this->raw(['Department' => 'HR', 'Branch' => 'All branches', 'Event Date (Verified)' => '', 'Responsible Department' => '',
                'Category' => 'Operational follow-up', 'Finding / Activity' => 'ضعف قيام مديري الفروع بدورهم الإداري', 'Corrective Action' => '',
                'Status / Rating' => 'Reported', 'Record Type' => 'Reported Finding', 'Source File' => 'HR.docx']),
            $this->raw(['Department' => 'HR', 'Branch' => 'All branches', 'Event Date (Verified)' => '', 'Responsible Department' => '',
                'Category' => 'Operational follow-up', 'Finding / Activity' => 'تفعيل الدور الرقابي لمدير عمليات الفروع', 'Corrective Action' => '',
                'Status / Rating' => 'Open', 'Record Type' => 'Issue', 'Source File' => 'HR.docx']),
        ]);

        $this->assertSame(['visits' => 1, 'observations' => 2, 'tickets' => 0], array_intersect_key($totals, ['visits' => 1, 'observations' => 1, 'tickets' => 1]));

        $visit = ObservationVisit::sole();
        $this->assertSame('desk', $visit->type);
        $this->assertNull($visit->branch_id);
        $this->assertSame('2026-10-11', $visit->visit_date->toDateString());
        $this->assertSame('approved', $visit->status);

        foreach (Observation::all() as $o) {
            $this->assertSame('activity', $o->kind);
            $this->assertSame('closed_no_action', $o->status);
            $this->assertNull($o->branch_id);
            $this->assertNull($o->event_date);
            $this->assertNull($o->ticket_id);
            $this->assertStringContainsString('كل الفروع', $o->description);
        }
        $this->assertSame(0, Ticket::count());
    }

    public function test_undated_rows_make_a_desk_visit_with_null_event_date(): void
    {
        $this->write([$this->raw(['Event Date (Verified)' => '', 'Date Raw' => '46336'])]);

        $visit = ObservationVisit::sole();
        $this->assertSame('desk', $visit->type);
        $this->assertSame($this->cityStars->id, $visit->branch_id);
        $this->assertSame('2026-10-11', $visit->visit_date->toDateString());
        $this->assertStringContainsString('تاريخ غير مؤكد', $visit->general_notes);

        $o = Observation::sole();
        $this->assertNull($o->event_date);
        $this->assertStringContainsString('46336', $o->description);
        // Undated: the SLA runs from the end of the week.
        $this->assertSame('2026-10-24 00:00:00', Ticket::sole()->due_at->toDateTimeString());
    }

    public function test_merch_visit_takes_original_times_and_assessments_keep_rating(): void
    {
        $this->write([
            $this->raw(['Department' => 'Merchandising', 'Branch' => 'جنينة', 'Responsible Department' => '', 'Category' => 'VM',
                'Finding / Activity' => 'العرض جميل', 'Corrective Action' => '', 'Status / Rating' => 'Positive', 'Record Type' => 'Positive',
                'Source File' => 'Existing Merchandising database', 'Source Row' => '3']),
            $this->raw(['Department' => 'Stock Control', 'Branch' => 'جنينة', 'Responsible Department' => 'Store Operations', 'Category' => 'جاهزية الفرع للجرد',
                'Finding / Activity' => 'جاهزية الفرع للجرد', 'Corrective Action' => '', 'Status / Rating' => 'جيد', 'Record Type' => 'Assessment']),
            $this->raw(['Department' => 'Stock Control', 'Branch' => 'جنينة', 'Responsible Department' => 'Store Operations', 'Category' => 'تنظيم المخزون داخل الفرع',
                'Finding / Activity' => 'المخزن غير منظم', 'Corrective Action' => 'تنظيم المخزن', 'Status / Rating' => 'ضعيف', 'Record Type' => 'Assessment']),
        ], [
            ['row' => 2, 'Visit ID' => 'V001', 'Branch' => 'جنينة', 'Visit Date' => '2026-10-13', 'Start' => '12:30', 'End' => '15:00', 'Merchandiser' => 'مايكل', 'Branch Responsible' => 'صباح', 'Overall / Notes' => 'الحالة العامة ممتازة', 'Department' => 'Merchandising'],
        ]);

        $merch = ObservationVisit::where('department_id', Department::where('slug', 'merchandise')->value('id'))->sole();
        $this->assertSame('2026-10-13 12:30:00', $merch->started_at->toDateTimeString());
        $this->assertSame('2026-10-13 15:00:00', $merch->submitted_at->toDateTimeString());
        $this->assertStringContainsString('مايكل', $merch->general_notes);

        $positive = Observation::where('title', 'العرض جميل')->sole();
        $this->assertSame('positive', $positive->kind);
        $this->assertSame('positive', $positive->status);
        $this->assertNull($positive->risk_level_id);
        $this->assertNull($positive->ticket_id);

        $good = Observation::where('title', 'جاهزية الفرع للجرد')->sole();
        $this->assertSame('assessment', $good->kind);
        $this->assertSame('good', $good->rating);
        $this->assertSame('closed_no_action', $good->status);
        $this->assertNull($good->catalog_item_id, 'assessments link the category only');
        $this->assertNotNull($good->category_id);
        $this->assertNull($good->ticket_id);

        // A poor assessment becomes an open issue with a ticket (service rule).
        $poor = Observation::where('title', 'المخزن غير منظم')->sole();
        $this->assertSame('issue', $poor->kind);
        $this->assertSame('poor', $poor->rating);
        $this->assertSame('open', $poor->status);
        $this->assertNotNull($poor->ticket_id);
        $this->assertSame(1, Ticket::count());
    }

    public function test_skips_existing_rows(): void
    {
        $raws = [$this->raw(), $this->raw(['Finding / Activity' => 'عدم ارتداء الموظفه اسكارف اليونيفورم'])];

        $first = $this->write($raws);
        $this->assertSame([1, 2, 2, 0], [$first['visits'], $first['observations'], $first['tickets'], $first['skipped']]);

        $second = $this->write($raws);
        $this->assertSame([0, 0, 0, 2], [$second['visits'], $second['observations'], $second['tickets'], $second['skipped']]);

        $this->assertSame(1, ObservationVisit::count());
        $this->assertSame(2, Observation::count());
        $this->assertSame(2, Ticket::count());

        // A partially imported group only adds the missing rows.
        $third = $this->write([...$raws, $this->raw(['Finding / Activity' => 'استخدام الموبايل'])]);
        $this->assertSame([1, 1, 1, 2], [$third['visits'], $third['observations'], $third['tickets'], $third['skipped']]);
        $this->assertSame(3, Observation::count());
    }

    public function test_failed_group_leaves_nothing_behind(): void
    {
        $rows = collect([$this->raw()])->map(fn ($r) => $this->mapper->map($r));
        $group = (new VisitGrouper)->group($rows)->first();
        // A free row without a description makes the upsert fail after the visit was created.
        $group['rows'][0]['catalog_item_id'] = null;
        $group['rows'][0]['description'] = '';

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            app(ImportWriter::class)->write($group, $this->importUser, self::SOURCE);
        } finally {
            $this->assertSame(0, ObservationVisit::count());
            $this->assertSame(0, Observation::count());
        }
    }
}
