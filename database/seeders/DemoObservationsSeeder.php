<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Observations\Ai\ClassifierContract;
use App\Services\Observations\ObservationService;
use App\Services\TicketService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Demo data for the Observations & Risk module, built through ObservationService and TicketService so
 * every business rule applies (risk resolution, escalation, tickets, verification, reopen).
 *
 * Idempotent: it first deletes only its own previous rows (observations.import_source = 'demo' and what
 * hangs off them). Never touches setup data (users, branches, areas, departments, risk levels, catalog).
 * The service methods own their transactions, so none is opened around them here.
 */
class DemoObservationsSeeder extends Seeder
{
    public const SOURCE = 'demo';

    public const MALL_OF_ARABIA = '6th of October - Mall of Arabia';

    /** Departments the demo (and the catalog it uses) needs; created only when missing (recorders as reporting). */
    private const DEPARTMENTS = [
        'cctv' => ['name' => 'CCTV', 'ticket_prefix' => 'CCT', 'color' => '#64748b', 'is_reporting' => true],
        'stock_control' => ['name' => 'Stock Control', 'ticket_prefix' => 'STK', 'color' => '#f59e0b', 'is_reporting' => true],
        'hr' => ['name' => 'HR', 'ticket_prefix' => 'HRD', 'color' => '#14b8a6', 'is_reporting' => true],
        'merchandise' => ['name' => 'Merchandise', 'ticket_prefix' => 'MRC', 'color' => '#ec4899', 'is_reporting' => true],
        'operation' => ['name' => 'Operation', 'ticket_prefix' => 'OPR', 'color' => '#10b981'],
        'maintenance' => ['name' => 'Maintenance', 'ticket_prefix' => 'MTN', 'color' => '#ef4444'],
        'it' => ['name' => 'IT', 'ticket_prefix' => 'ITD', 'color' => '#0ea5e9'],
        'lp' => ['name' => 'Loss Prevention', 'ticket_prefix' => 'LSP', 'color' => '#dc2626'],
        'vm' => ['name' => 'VM', 'ticket_prefix' => 'VMR', 'color' => '#d946ef'],
        'warehouse' => ['name' => 'Warehouse', 'ticket_prefix' => 'WRH', 'color' => '#a16207'],
    ];

    private const CODES = [
        'CCTV-OPEN-LATE', 'CCTV-CASH-COUNT-ALONE', 'CCTV-FIT-OVER-5', 'CCTV-FLOOR-NO-NAMETAG', 'CCTV-CAMERA-DOWN',
        'STK-ORG-UNORGANIZED', 'STK-TRN-COUNT-SHEET', 'MRC-QLT-MISSING-BUTTON',
    ];

    private const USERS = [
        'admin@levoile.test', 'ops@levoile.test', 'cctv.emp@levoile.test', 'stock.emp@levoile.test',
        'operation.manager@levoile.test', 'operation.emp@levoile.test',
    ];

    /** What the last run created: observations, visits, tickets. */
    public array $summary = ['observations' => 0, 'visits' => 0, 'tickets' => 0];

    private ObservationService $service;

    private TicketService $tickets;

    /** @var array<string, User> email => user */
    private array $users = [];

    /** @var array<string, int> slug => id */
    private array $departments = [];

    /** @var array<string, int> code => id */
    private array $catalog = [];

    private int $photoSeq = 0;

    /**
     * The single owner of the demo's preparation: demo users, the departments and the Mall of Arabia branch
     * (created only when missing, existing rows untouched), and the module setup only when it is missing
     * (no active risk level or no catalog item), so the owner's catalog edits are never reset.
     */
    private function ensureSetup(): void
    {
        $emails = array_column(DemoUsersSeeder::users(), 'email');
        if (User::whereIn('email', $emails)->count() < count($emails)) {
            (new DemoUsersSeeder)->run();
        }

        foreach (self::DEPARTMENTS as $slug => $attrs) {
            Department::firstOrCreate(['slug' => $slug], $attrs);
        }

        if (! Branch::where('branch_name', 'like', '%Mall of Arabia')->exists()) {
            Branch::create([
                'branch_name' => self::MALL_OF_ARABIA, 'area' => '6th of October', 'city' => 'Giza',
                'active' => true, 'checkin_radius' => 150,
            ]);
        }

        if (! RiskLevel::where('active', true)->exists() || ! ObservationCatalogItem::exists()) {
            (new ObservationSetupSeeder)->run();
        }

        $missing = array_diff(self::CODES, ObservationCatalogItem::whereIn('code', self::CODES)->pluck('code')->all());
        if ($missing !== []) {
            throw new RuntimeException('Demo observations need these catalog items: '.implode(', ', $missing));
        }
    }

    /**
     * Delete the previous demo rows in FK-safe order, and their photo files. Returns the number of
     * observations removed.
     */
    public static function purge(): int
    {
        $obsIds = DB::table('observations')->where('import_source', self::SOURCE)->pluck('id')->all();
        if ($obsIds === []) {
            return 0;
        }

        $visitIds = DB::table('observations')->whereIn('id', $obsIds)->distinct()->pluck('observation_visit_id')->all();
        $ticketIds = DB::table('tickets')->whereIn('observation_id', $obsIds)->pluck('id')
            ->merge(DB::table('observations')->whereIn('id', $obsIds)->whereNotNull('ticket_id')->pluck('ticket_id'))
            ->unique()->values()->all();

        DB::transaction(function () use ($obsIds, $visitIds, $ticketIds) {
            DB::table('observations')->whereIn('id', $obsIds)->update(['ticket_id' => null]);
            DB::table('notifications')->whereIn('ticket_id', $ticketIds)->delete();
            DB::table('ticket_updates')->whereIn('ticket_id', $ticketIds)->delete();
            DB::table('tickets')->whereIn('id', $ticketIds)->delete();

            DB::table('ai_classifications')
                ->where(fn ($q) => $q->whereIn('observation_visit_id', $visitIds)->orWhereIn('observation_id', $obsIds))
                ->delete();
            DB::table('observation_verifications')
                ->where(fn ($q) => $q->whereIn('observation_id', $obsIds)->orWhereIn('observation_visit_id', $visitIds))
                ->delete();
            DB::table('observation_photos')->whereIn('observation_id', $obsIds)->delete();
            DB::table('observations')->whereIn('id', $obsIds)->delete();
            // A visit goes only when nothing else is left on it.
            DB::table('observation_visits')->whereIn('id', $visitIds)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('observations')->whereColumn('observations.observation_visit_id', 'observation_visits.id'))
                ->delete();
        });

        foreach ($visitIds as $visitId) {
            Storage::disk('public')->deleteDirectory("evidence/observations/{$visitId}");
        }

        return count($obsIds);
    }

    public function run(): void
    {
        $this->ensureSetup();

        self::purge();

        $this->service = app(ObservationService::class);
        $this->tickets = app(TicketService::class);
        $this->users = User::whereIn('email', self::USERS)->get()->keyBy('email')->all();
        $this->departments = Department::pluck('id', 'slug')->all();
        $this->catalog = ObservationCatalogItem::whereIn('code', self::CODES)->pluck('id', 'code')->all();

        $zayed = Branch::where('branch_name', 'like', '%Saraya Mall')->firstOrFail();
        $stars = Branch::where('branch_name', 'like', '%City Stars Mall')->firstOrFail();
        $arabia = Branch::where('branch_name', 'like', '%Mall of Arabia')->firstOrFail();

        $cctv = $this->users['cctv.emp@levoile.test'];
        $admin = $this->users['admin@levoile.test'];
        $ops = $this->users['ops@levoile.test'];

        // ---- Visit A: CCTV, Saraya, 9 days ago ----
        $atA = $this->day(9, 10, 30);
        $visitA = $this->visit($cctv, 'cctv', $zayed, 'field', $atA);
        $aiText = 'الكاشير ساب درج النقدية مفتوح وهو بعيد عن الكاونتر';
        $batch = app(ClassifierContract::class)->classify($this->departments['cctv'], $zayed->id, [[0, $aiText]]);
        $aiRows = $this->service->recordClassifications($visitA, [[0, $aiText]], $batch);
        DB::table('ai_classifications')->whereIn('id', array_map(fn ($r) => $r->id, $aiRows))->update(['created_at' => $atA, 'updated_at' => $atA]);

        [$late, $cash, $fitA] = $this->record($visitA, $cctv, $atA, [
            ['catalog_item_id' => $this->catalog['CCTV-OPEN-LATE'], 'description' => 'الفرع فتح متأخر 35 دقيقة عن الميعاد',
                'extra_values' => ['minutes_late' => 35, 'employee' => 'أحمد سمير'], 'photos' => [$this->photo($visitA, 'before')]],
            ['catalog_item_id' => $this->catalog['CCTV-CASH-COUNT-ALONE'], 'description' => 'الكاشير بيعد النقدية لوحده آخر اليوم',
                'photos' => [$this->photo($visitA, 'before')]],
            ['catalog_item_id' => $this->catalog['CCTV-FIT-OVER-5'], 'description' => 'عميلة دخلت الفيتنج بـ 8 قطع',
                'photos' => [$this->photo($visitA, 'before')]],
            ['kind' => 'issue', 'description' => $aiText, 'risk_level_slug' => 'medium',
                'responsible_department_id' => $this->departments['operation'], 'ai_classification_id' => $aiRows[0]->id],
        ]);
        $this->submit($visitA, $cctv, $atA);

        // FIT-OVER-5 (medium): fixed and approved -> verified_closed (automatic verification).
        $this->work($fitA, $atA->copy()->addHours(2), 'closed', $visitA);
        // OPEN-LATE (high after the 35-minute rule): fixed and approved -> waiting_verification.
        $this->work($late, $atA->copy()->addHours(3), 'closed', $visitA);
        // CASH-COUNT-ALONE (high): fixed and approved, then the field check says not resolved -> reopened.
        $this->work($cash, $atA->copy()->addHours(4), 'closed', $visitA);
        $this->verifyNotResolved($cash->fresh(), $ops, $this->day(7, 12, 0));

        // ---- Visit B: CCTV, City Stars, 6 days ago ----
        $atB = $this->day(6, 11, 0);
        $visitB = $this->visit($cctv, 'cctv', $stars, 'field', $atB);
        [$nametag] = $this->record($visitB, $cctv, $atB, [
            ['catalog_item_id' => $this->catalog['CCTV-FLOOR-NO-NAMETAG'], 'description' => 'اتنين من فريق البيع من غير نيم تاج',
                'photos' => [$this->photo($visitB, 'before')]],
            ['catalog_item_id' => $this->catalog['CCTV-CAMERA-DOWN'], 'description' => 'كاميرا المخزن مش شغالة من امبارح',
                'photos' => [$this->photo($visitB, 'before')]],
        ]);
        $this->submit($visitB, $cctv, $atB);
        // NAME TAG: the operation employee is working on it -> in_progress. CAMERA-DOWN stays open (and overdue).
        $this->work($nametag, $atB->copy()->addHours(2), 'in_progress', $visitB);

        // ---- HR desk visit, all branches, 5 days ago: activities only ----
        $atH = $this->day(5, 13, 0);
        $visitH = $this->visit($admin, 'hr', null, 'desk', $atH);
        $this->record($visitH, $admin, $atH, [
            ['kind' => 'activity', 'description' => 'مراجعة كشوف الحضور والانصراف لكل الفروع'],
            ['kind' => 'activity', 'description' => 'تحديث ملفات الموظفين الجدد على السيستم'],
        ]);
        $this->submit($visitH, $admin, $atH);

        // ---- Stock control, Mall of Arabia, 4 days ago: a poor assessment becomes an issue ----
        $atS = $this->day(4, 10, 0);
        $visitS = $this->visit($this->users['stock.emp@levoile.test'], 'stock_control', $arabia, 'field', $atS);
        $this->record($visitS, $this->users['stock.emp@levoile.test'], $atS, [
            ['catalog_item_id' => $this->catalog['STK-ORG-UNORGANIZED'], 'description' => 'المخزن غير منظم والكراتين على الأرض',
                'photos' => [$this->photo($visitS, 'before')]],
            ['kind' => 'assessment', 'rating' => 'poor', 'description' => 'جاهزية الجرد ضعيفة', 'risk_level_slug' => 'medium',
                'responsible_department_id' => $this->departments['operation']],
            ['kind' => 'assessment', 'rating' => 'average', 'description' => 'نظافة المخزن متوسطة'],
            ['catalog_item_id' => $this->catalog['STK-TRN-COUNT-SHEET'], 'description' => 'تدريب الفريق على شيت الجرد'],
        ]);
        $this->submit($visitS, $this->users['stock.emp@levoile.test'], $atS);

        // ---- Merchandising, City Stars, 3 days ago: an issue and a positive ----
        $atM = $this->day(3, 12, 0);
        $visitM = $this->visit($admin, 'merchandise', $stars, 'field', $atM);
        $this->record($visitM, $admin, $atM, [
            ['catalog_item_id' => $this->catalog['MRC-QLT-MISSING-BUTTON'], 'description' => 'زرار واقع في قميص معروض على الستاند',
                'photos' => [$this->photo($visitM, 'before')]],
            ['kind' => 'positive', 'description' => 'عرض الواجهة ممتاز ومطابق لدليل الموسم'],
        ]);
        $this->submit($visitM, $admin, $atM);

        // ---- Visit C: CCTV, Saraya, 2 days ago: a repeat and a manual (category only) point ----
        $atC = $this->day(2, 11, 30);
        $visitC = $this->visit($cctv, 'cctv', $zayed, 'field', $atC);
        $floor = ObservationCategory::where('department_id', $this->departments['cctv'])->where('slug', 'floor')->value('id');
        $this->record($visitC, $cctv, $atC, [
            ['catalog_item_id' => $this->catalog['CCTV-FIT-OVER-5'], 'description' => 'تكرار: دخول 7 قطع الفيتنج روم',
                'photos' => [$this->photo($visitC, 'before')]],
            ['category_id' => $floor, 'kind' => 'issue', 'description' => 'الفلور مزدحم بستاندات قدام الفيتنج والحركة صعبة'],
        ]);
        $this->submit($visitC, $cctv, $atC);

        $this->cleanNotifications();

        $obsIds = DB::table('observations')->where('import_source', self::SOURCE)->pluck('id');
        $this->summary = [
            'observations' => $obsIds->count(),
            'visits' => DB::table('observations')->whereIn('id', $obsIds)->distinct()->count('observation_visit_id'),
            'tickets' => DB::table('tickets')->whereIn('observation_id', $obsIds)->count(),
        ];
    }

    // ---------- helpers ----------

    /** $d days ago at H:i (never in the future). */
    private function day(int $d, int $hour, int $minute): Carbon
    {
        return Carbon::now()->subDays($d)->setTime($hour, $minute);
    }

    private function visit(User $u, string $department, ?Branch $branch, string $type, Carbon $at): ObservationVisit
    {
        $visit = $this->service->createVisit($u, [
            'type' => $type, 'source' => 'web', 'department_id' => $this->departments[$department],
            'branch_id' => $branch?->id, 'visit_date' => $at->toDateString(), 'simulated' => true,
            'checkin_latitude' => $branch?->latitude, 'checkin_longitude' => $branch?->longitude,
        ]);
        DB::table('observation_visits')->where('id', $visit->id)->update(['started_at' => $at, 'created_at' => $at, 'updated_at' => $at]);

        return $visit->refresh();
    }

    /** Copy the bundled placeholder image into the visit's evidence folder; returns the public-disk path. */
    private function photo(ObservationVisit $v, string $kind): string
    {
        $path = "evidence/observations/{$v->id}/demo-{$kind}-".(++$this->photoSeq).'.jpg';
        Storage::disk('public')->put($path, file_get_contents(database_path('data/demo-evidence.jpg')));

        return $path;
    }

    /** Upsert the items, mark them demo and backdate them. Returns the observations in input order. */
    private function record(ObservationVisit $v, User $u, Carbon $at, array $items): array
    {
        $saved = $this->service->upsertObservations($v, $items, $u);
        $ids = $saved->pluck('id')->all();

        DB::table('observations')->whereIn('id', $ids)->update(['import_source' => self::SOURCE, 'created_at' => $at, 'updated_at' => $at]);
        DB::table('observation_photos')->whereIn('observation_id', $ids)->update(['created_at' => $at, 'updated_at' => $at]);

        return $saved->all();
    }

    private function submit(ObservationVisit $v, User $u, Carbon $at): void
    {
        $submittedAt = $at->copy()->addMinutes(45);
        // Silent: no notifications or auto-assignment to real staff; the demo assigns explicitly below.
        $result = $this->service->submitVisit($v->refresh(), $u, ['silent' => true, 'due_from' => $submittedAt]);

        DB::table('observation_visits')->where('id', $v->id)->update(['submitted_at' => $submittedAt, 'updated_at' => $submittedAt]);
        DB::table('observations')->where('observation_visit_id', $v->id)->update(['updated_at' => $submittedAt]);

        $ticketIds = array_column($result['created_tickets'], 'ticket_id');
        DB::table('tickets')->whereIn('id', $ticketIds)->update(['created_at' => $submittedAt, 'updated_at' => $submittedAt]);
        DB::table('ticket_updates')->whereIn('ticket_id', $ticketIds)->update(['created_at' => $submittedAt, 'updated_at' => $submittedAt]);
    }

    /**
     * Move the observation's ticket through the real cycle: assigned -> on_the_way -> in_progress, and for
     * `closed` also an after photo -> waiting_approval -> closed (approved by the ops manager). Each step
     * is backdated three hours after the previous one, starting at $from.
     */
    private function work(Observation $o, Carbon $from, string $until, ObservationVisit $v): void
    {
        $ticket = Ticket::findOrFail($o->fresh()->ticket_id);
        $employee = $this->users['operation.emp@levoile.test'];
        $manager = $this->users['operation.manager@levoile.test'];
        $lastUpdate = (int) DB::table('ticket_updates')->where('ticket_id', $ticket->id)->max('id');
        $lastNotification = (int) DB::table('notifications')->max('id');

        $this->tickets->assign($ticket, $employee, $manager);
        $this->tickets->changeStatus($ticket, 'on_the_way', $employee, 'تم قبول الطلب');
        $this->tickets->changeStatus($ticket, 'in_progress', $employee, 'تم بدء العمل');
        if ($until === 'closed') {
            $this->tickets->addEvidence($ticket, $this->photo($v, 'after'), 'after', $employee);
            $this->tickets->changeStatus($ticket, 'waiting_approval', $employee, 'تم الإصلاح');
            $this->tickets->changeStatus($ticket, 'closed', $this->users['ops@levoile.test'], 'تم الإغلاق بعد مراجعة صورة بعد الإصلاح');
        }

        // Backdate the log rows written above, then the ticket's own milestones from them.
        $at = $from->copy();
        $times = [];
        foreach (DB::table('ticket_updates')->where('ticket_id', $ticket->id)->where('id', '>', $lastUpdate)->orderBy('id')->get(['id', 'action', 'to_status']) as $row) {
            if ($row->action !== 'evidence') {
                $at = $at->copy()->addHours(3);
            }
            DB::table('ticket_updates')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
            if ($row->to_status) {
                $times[$row->to_status] = $at->copy();
            }
        }

        DB::table('tickets')->where('id', $ticket->id)->update(array_filter([
            'assigned_at' => $times['assigned'] ?? null,
            'resolved_at' => $times['waiting_approval'] ?? null,
            'closed_at' => $times['closed'] ?? null,
            'updated_at' => $at,
        ]));
        DB::table('notifications')->where('ticket_id', $ticket->id)->where('id', '>', $lastNotification)
            ->update(['created_at' => $times['assigned'] ?? $at, 'updated_at' => $times['assigned'] ?? $at]);

        if (isset($times['closed'])) {
            // Written by SyncObservationFromTicket at the close: the copied after photo and, for a risk that
            // needs no field check, the automatic verification.
            DB::table('observation_photos')->where('observation_id', $o->id)->where('kind', 'after')
                ->update(['created_at' => $times['closed'], 'updated_at' => $times['closed']]);
            DB::table('observation_verifications')->where('observation_id', $o->id)
                ->update(['verified_at' => $times['closed'], 'created_at' => $times['closed'], 'updated_at' => $times['closed']]);
            DB::table('observations')->where('id', $o->id)->update(['updated_at' => $times['closed']]);
        }
    }

    /** Field check: not resolved -> the observation is reopened with a new ticket (backdated to $at). */
    private function verifyNotResolved(Observation $o, User $verifier, Carbon $at): void
    {
        $previousTicketId = $o->ticket_id;
        $this->service->verify($o, $verifier, 'not_resolved', 'لم يتم الحل');
        $o->refresh();

        DB::table('observation_verifications')->where('observation_id', $o->id)->where('result', 'not_resolved')
            ->update(['verified_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
        DB::table('observations')->where('id', $o->id)->update(['updated_at' => $at]);

        $new = Ticket::find($o->ticket_id);
        if ($new === null || (int) $new->id === (int) $previousTicketId) {
            return;
        }

        // The reopen auto-assignment may have picked a real employee: hand the ticket to the demo employee
        // through TicketService so the log, the event and the notification all name the right person.
        $employee = $this->users['operation.emp@levoile.test'];
        if ($new->assigned_to !== null && (int) $new->assigned_to !== (int) $employee->id) {
            $this->tickets->assign($new, $employee, $this->users['operation.manager@levoile.test']);
        }

        // Backdate the new ticket's log one minute apart (in write order), then its milestones from it.
        $assignedAt = null;
        $step = $at->copy();
        foreach (DB::table('ticket_updates')->where('ticket_id', $new->id)->orderBy('id')->get(['id', 'action']) as $row) {
            DB::table('ticket_updates')->where('id', $row->id)->update(['created_at' => $step, 'updated_at' => $step]);
            if ($row->action === 'assignment') {
                $assignedAt = $step->copy();
            }
            $step = $step->copy()->addMinute();
        }

        $new->refresh();
        DB::table('tickets')->where('id', $new->id)->update([
            'created_at' => $at, 'updated_at' => $step,
            'due_at' => $new->sla_hours ? $at->copy()->addHours((int) $new->sla_hours) : null,
            'assigned_at' => $new->assigned_to ? ($assignedAt ?? $at) : null,
        ]);
        DB::table('notifications')->where('ticket_id', $new->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    /** Notifications on demo tickets go to demo users only (a reopen notifies the department manager). */
    private function cleanNotifications(): void
    {
        $demoUserIds = User::whereIn('email', array_column(DemoUsersSeeder::users(), 'email'))->pluck('id');
        $ticketIds = DB::table('tickets')
            ->whereIn('observation_id', DB::table('observations')->where('import_source', self::SOURCE)->select('id'))
            ->pluck('id');

        DB::table('notifications')->whereIn('ticket_id', $ticketIds)->whereNotIn('user_id', $demoUserIds)->delete();
    }
}
