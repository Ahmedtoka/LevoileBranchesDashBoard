<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoDataController extends Controller
{
    /** Wipe all tickets / visits / notifications (keeps users, branches, templates, departments). */
    public function wipe()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['ticket_updates', 'ticket_evidence', 'visit_answer_evidence', 'visit_answer_user', 'visit_answers', 'tickets', 'visits', 'notifications'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return back()->with('status', 'تم مسح كل التذاكر والزيارات.');
    }

    /** Generate ~3 months of realistic data across all roles. */
    public function generate()
    {
        @set_time_limit(120);

        $now = Carbon::now();
        $start = $now->copy()->subDays(90);

        $branches = Branch::where('active', true)->get();
        $depts = Department::pluck('id', 'slug');
        $deptList = Department::all();

        $storeTpl = VisitTemplate::where('type', 'store_manager')->first();
        $areaTpl = VisitTemplate::where('type', 'area_manager')->first();

        $storeManagers = User::whereHas('role', fn ($q) => $q->where('slug', 'store_manager'))
            ->whereNotNull('branch_id')->get();
        $areaManager = User::whereHas('role', fn ($q) => $q->where('slug', 'area_manager'))->first();

        // employees per department (for assignment)
        $empByDept = [];
        foreach ($deptList as $d) {
            $empByDept[$d->id] = User::where('department_id', $d->id)
                ->where('is_department_manager', false)->pluck('id')->all();
        }

        $ticketTitles = [
            'صيانة — لمبة لا تعمل', 'صيانة — تكييف ضعيف التبريد', 'دهان حائط يحتاج معالجة',
            'تنظيف واجهة المحل', 'مراية مكسورة', 'باب لا يغلق جيدًا', 'POS لا يعمل',
            'كاميرا CCTV معطلة', 'نواقص شنط بيع', 'يافطة تحتاج تنظيف', 'أرضية تحتاج معالجة',
            'تسريب مياه في الحمام', 'رف مكسور', 'مانيكان يحتاج صيانة', 'إضاءة خارجية لا تعمل',
        ];

        $statusDist = [
            'open' => 16, 'assigned' => 14, 'on_the_way' => 10, 'in_progress' => 14,
            'waiting_approval' => 10, 'closed' => 22, 'postponed' => 6, 'not_fixed' => 4, 'rejected' => 4,
        ];
        $statusPool = [];
        foreach ($statusDist as $st => $w) {
            $statusPool = array_merge($statusPool, array_fill(0, $w, $st));
        }

        $created = 0;

        DB::transaction(function () use (
            $branches, $depts, $deptList, $storeTpl, $areaTpl, $storeManagers, $areaManager,
            $empByDept, $ticketTitles, $statusPool, $start, $now, &$created
        ) {
            // ---- Store manager daily visits + their tickets ----
            foreach ($storeManagers as $sm) {
                $branch = $branches->firstWhere('id', $sm->branch_id);
                if (! $branch || ! $storeTpl) {
                    continue;
                }
                for ($day = 0; $day < 90; $day += rand(2, 4)) {
                    $date = $start->copy()->addDays($day)->setTime(rand(9, 20), rand(0, 59));
                    $problems = rand(0, 4);
                    $visit = $this->makeVisit($storeTpl, $branch, $sm, $date, $problems);
                    for ($i = 0; $i < $problems; $i++) {
                        $this->makeTicket($branches, $deptList, $depts, $empByDept, $ticketTitles, $statusPool, $date, $branch->id, $visit->id, true);
                        $created++;
                    }
                }
            }

            // ---- Area manager visits + tickets ----
            if ($areaManager && $areaTpl) {
                $areaBranches = $branches->shuffle()->take(10);
                foreach ($areaBranches as $branch) {
                    for ($k = 0; $k < rand(2, 4); $k++) {
                        $date = $start->copy()->addDays(rand(0, 88))->setTime(rand(9, 19), 0);
                        $problems = rand(0, 3);
                        $visit = $this->makeVisit($areaTpl, $branch, $areaManager, $date, $problems);
                        for ($i = 0; $i < $problems; $i++) {
                            $this->makeTicket($branches, $deptList, $depts, $empByDept, $ticketTitles, $statusPool, $date, $branch->id, $visit->id, true);
                            $created++;
                        }
                    }
                }
            }

            // ---- Standalone maintenance requests (no visit) ----
            $maint = $depts['maintenance'] ?? null;
            for ($i = 0; $i < 60; $i++) {
                $branch = $branches->random();
                $date = $start->copy()->addDays(rand(0, 89))->setTime(rand(9, 21), 0);
                $this->makeTicket($branches, $deptList, $depts, $empByDept, $ticketTitles, $statusPool, $date, $branch->id, null, false, $maint);
                $created++;
            }
        });

        return back()->with('status', "تم توليد بيانات ديمو لـ 3 شهور — {$created} تذكرة + زيارات.");
    }

    // ---------- helpers ----------

    private function makeVisit(VisitTemplate $tpl, Branch $branch, User $user, Carbon $date, int $problems): Visit
    {
        $visit = Visit::create([
            'visit_template_id' => $tpl->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => 'completed',
            'scheduled_date' => $date->toDateString(),
            'checked_in_at' => $date,
            'started_at' => $date,
            'completed_at' => $date->copy()->addHours(1),
            'positives_count' => rand(15, 40),
            'problems_count' => $problems,
            'tickets_count' => $problems,
        ]);
        $this->backdate('visits', $visit->id, $date);

        return $visit;
    }

    private function makeTicket($branches, $deptList, $depts, $empByDept, $titles, $statusPool, Carbon $date, int $branchId, ?int $visitId, bool $fromVisit, ?int $forceDeptId = null): void
    {
        // pick department
        if ($forceDeptId) {
            $deptId = $forceDeptId;
        } else {
            // store/area tickets mostly route to operation + a specialist
            $pick = $deptList->random();
            $deptId = $pick->id;
        }
        $dept = $deptList->firstWhere('id', $deptId);
        $prefix = $dept->ticket_prefix ?? 'GEN';

        $status = $statusPool[array_rand($statusPool)];
        $priority = ['low', 'medium', 'medium', 'high', 'critical'][array_rand([0, 1, 2, 3, 4])];

        $groupCode = $fromVisit ? null : 'MR-'.str_pad((string) ((Ticket::max('id') ?? 0) + 1), 5, '0', STR_PAD_LEFT);

        $ticket = Ticket::create([
            'reference' => Ticket::nextReference($prefix),
            'group_code' => $groupCode,
            'title' => $titles[array_rand($titles)],
            'description' => 'بيانات ديمو — وصف الطلب.',
            'branch_id' => $branchId,
            'department_id' => $deptId,
            'visit_id' => $visitId,
            'created_by' => null,
            'status' => 'open',
            'priority' => $priority,
            'sla_hours' => 48,
            'due_at' => $date->copy()->addHours(48),
        ]);

        // timeline + advance to the chosen status
        $cursor = $date->copy();
        $this->addUpdate($ticket, 'created', null, 'open', $fromVisit ? 'طلب من الشيك ليست.' : 'تم إنشاء الطلب.', $cursor);

        $techs = $empByDept[$deptId] ?? [];
        $tech = ! empty($techs) ? $techs[array_rand($techs)] : null;

        $path = $this->pathTo($status);
        $assignedTo = null;
        $assignedAt = null;
        $closedAt = null;

        foreach ($path as $step) {
            $cursor = $cursor->copy()->addHours(rand(2, 30));
            if ($step === 'assigned') {
                $assignedTo = $tech;
                $assignedAt = $cursor->copy();
                $this->addUpdate($ticket, 'assignment', 'open', 'assigned', 'تعيين للفني', $cursor, $tech);
            } elseif ($step === 'rejected') {
                $assignedTo = null;
                $this->addUpdate($ticket, 'declined', 'assigned', 'rejected', 'رفض الفني: غير متاح حاليًا.', $cursor, $tech);
            } else {
                $note = match ($step) {
                    'on_the_way' => 'تم قبول الطلب',
                    'in_progress' => 'تم بدء العمل',
                    'waiting_approval' => 'تم التصليح',
                    'postponed' => 'تم التأجيل',
                    'not_fixed' => 'لم يتم التصليح',
                    'closed' => 'تم الإغلاق',
                    default => null,
                };
                $this->addUpdate($ticket, 'status_change', null, $step, $note, $cursor, $tech);
                if ($step === 'closed') {
                    $closedAt = $cursor->copy();
                }
            }
        }

        DB::table('tickets')->where('id', $ticket->id)->update([
            'status' => $status,
            'assigned_to' => $assignedTo,
            'assigned_at' => $assignedAt,
            'closed_at' => $closedAt,
            'created_at' => $date,
            'updated_at' => $cursor,
        ]);
    }

    /** Ordered transitions to reach a target status. */
    private function pathTo(string $status): array
    {
        return match ($status) {
            'open' => [],
            'assigned' => ['assigned'],
            'rejected' => ['assigned', 'rejected'],
            'on_the_way' => ['assigned', 'on_the_way'],
            'in_progress' => ['assigned', 'on_the_way', 'in_progress'],
            'waiting_approval' => ['assigned', 'on_the_way', 'in_progress', 'waiting_approval'],
            'closed' => ['assigned', 'on_the_way', 'in_progress', 'waiting_approval', 'closed'],
            'postponed' => ['assigned', 'on_the_way', 'in_progress', 'postponed'],
            'not_fixed' => ['assigned', 'on_the_way', 'in_progress', 'not_fixed'],
            default => [],
        };
    }

    private function addUpdate(Ticket $t, string $action, ?string $from, ?string $to, ?string $note, Carbon $at, ?int $userId = null): void
    {
        $u = TicketUpdate::create([
            'ticket_id' => $t->id, 'user_id' => $userId, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'note' => $note,
        ]);
        $this->backdate('ticket_updates', $u->id, $at);
    }

    private function backdate(string $table, int $id, Carbon $at): void
    {
        DB::table($table)->where('id', $id)->update(['created_at' => $at, 'updated_at' => $at]);
    }
}
