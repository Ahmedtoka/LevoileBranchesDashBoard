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

    /** Generate full demo data across every role — concentrated on recent days so default (today) views are populated. */
    public function generate()
    {
        @set_time_limit(180);

        $branches = Branch::where('active', true)->get();
        $deptList = Department::all();
        $depts = $deptList->pluck('id', 'slug');

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

        $maintId = $depts['maintenance'] ?? optional($deptList->first())->id;
        $opsId = $depts['operation'] ?? null;
        $storeBranchIds = $storeManagers->pluck('branch_id')->filter()->values()->all();
        $smByBranch = $storeManagers->keyBy('branch_id');

        $titles = [
            'صيانة — لمبة لا تعمل', 'صيانة — تكييف ضعيف التبريد', 'دهان حائط يحتاج معالجة',
            'تنظيف واجهة المحل', 'مراية مكسورة', 'باب لا يغلق جيدًا', 'POS لا يعمل',
            'كاميرا CCTV معطلة', 'نواقص شنط بيع', 'يافطة تحتاج تنظيف', 'أرضية تحتاج معالجة',
            'تسريب مياه في الحمام', 'رف مكسور', 'مانيكان يحتاج صيانة', 'إضاءة خارجية لا تعمل',
        ];

        $statusDist = [
            'open' => 20, 'assigned' => 14, 'on_the_way' => 10, 'in_progress' => 14,
            'waiting_approval' => 10, 'closed' => 18, 'postponed' => 6, 'not_fixed' => 4, 'rejected' => 4,
        ];
        $statusPool = [];
        foreach ($statusDist as $st => $w) {
            $statusPool = array_merge($statusPool, array_fill(0, $w, $st));
        }

        $created = 0;

        DB::transaction(function () use (
            $branches, $deptList, $storeTpl, $areaTpl, $storeManagers, $areaManager,
            $empByDept, $titles, $statusPool, $maintId, $opsId, $storeBranchIds, $smByBranch, &$created
        ) {
            // 1) Store-manager checklist visits + their tickets (created_by = store manager)
            foreach ($storeManagers as $sm) {
                $branch = $branches->firstWhere('id', $sm->branch_id);
                if (! $branch || ! $storeTpl) {
                    continue;
                }
                for ($i = 0; $i < 16; $i++) {
                    $date = $this->dateBucket();
                    $problems = rand(0, 3);
                    $visit = $this->makeVisit($storeTpl, $branch, $sm, $date, $problems);
                    for ($p = 0; $p < $problems; $p++) {
                        $this->makeTicket($deptList, $empByDept, $titles, $statusPool, $date, $branch->id, $visit->id, true, $deptList->random()->id, $sm->id);
                        $created++;
                    }
                }
            }

            // 2) Area-manager visits + tickets (created_by = area manager) + a few upcoming visits to do
            if ($areaManager && $areaTpl) {
                foreach ($branches->shuffle()->take(10) as $branch) {
                    for ($k = 0; $k < rand(2, 3); $k++) {
                        $date = $this->dateBucket();
                        $problems = rand(0, 3);
                        $visit = $this->makeVisit($areaTpl, $branch, $areaManager, $date, $problems);
                        for ($p = 0; $p < $problems; $p++) {
                            $this->makeTicket($deptList, $empByDept, $titles, $statusPool, $date, $branch->id, $visit->id, true, $deptList->random()->id, $areaManager->id);
                            $created++;
                        }
                    }
                }
                foreach ($branches->shuffle()->take(4) as $branch) {
                    $this->makeScheduledVisit($areaTpl, $branch, $areaManager);
                }
            }

            // 3) Per-department requests (visit_id null) on the active store branches, created_by = that branch's SM
            $pickBranch = fn () => $storeBranchIds ? $storeBranchIds[array_rand($storeBranchIds)] : $branches->random()->id;

            foreach ($deptList as $d) {
                $n = $d->id === $maintId ? 45 : (($opsId && $d->id === $opsId) ? 28 : rand(8, 12));
                for ($i = 0; $i < $n; $i++) {
                    $branchId = $pickBranch();
                    $createdBy = optional($smByBranch->get($branchId))->id;
                    $this->makeTicket($deptList, $empByDept, $titles, $statusPool, $this->dateBucket(), $branchId, null, false, $d->id, $createdBy);
                    $created++;
                }
                // guarantee a few fresh (today, open) tickets so every "today" board is populated
                for ($i = 0; $i < 3; $i++) {
                    $branchId = $pickBranch();
                    $createdBy = optional($smByBranch->get($branchId))->id;
                    $this->makeTicket($deptList, $empByDept, $titles, $statusPool, $this->todayAt(), $branchId, null, false, $d->id, $createdBy, 'open');
                    $created++;
                }
            }

            // 4) FULL-CYCLE TODAY showcase — every reviewer sees the complete flow on the default (today) view.
            $cycle = ['open', 'assigned', 'on_the_way', 'in_progress', 'waiting_approval', 'closed', 'postponed', 'not_fixed', 'rejected'];

            // Each technician in the heavy depts gets one ticket of every status, today.
            foreach (array_filter([$maintId, $opsId]) as $dId) {
                $techList = $empByDept[$dId] ?? [];
                foreach (($techList ?: [null]) as $techId) {
                    foreach ($cycle as $st) {
                        $branchId = $pickBranch();
                        $createdBy = optional($smByBranch->get($branchId))->id;
                        $this->makeTicket($deptList, $empByDept, $titles, $statusPool, $this->todayAt(), $branchId, null, false, $dId, $createdBy, $st, $techId);
                        $created++;
                    }
                }
            }

            // Each store manager gets one of every status today → their "my requests" shows the full flow.
            $firstMaintTech = $empByDept[$maintId][0] ?? null;
            foreach ($storeManagers as $sm) {
                if (! $sm->branch_id) {
                    continue;
                }
                foreach ($cycle as $st) {
                    $this->makeTicket($deptList, $empByDept, $titles, $statusPool, $this->todayAt(), $sm->branch_id, null, false, $maintId, $sm->id, $st, $firstMaintTech);
                    $created++;
                }
            }
        });

        return back()->with('status', "تم توليد بيانات ديمو كاملة لكل الأدوار — {$created} تذكرة + زيارات (مركّزة على آخر أيام).");
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

    /** An upcoming (assigned) visit the user still has to perform — gives area/store managers a live to-do. */
    private function makeScheduledVisit(VisitTemplate $tpl, Branch $branch, User $user): void
    {
        $date = Carbon::today()->addDays(rand(0, 3));
        Visit::create([
            'visit_template_id' => $tpl->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => 'assigned',
            'scheduled_date' => $date->toDateString(),
        ]);
    }

    /** Weighted date: ~30% today, ~15% yesterday, ~25% this week, ~30% older (history). */
    private function dateBucket(): Carbon
    {
        $r = mt_rand(1, 100);
        if ($r <= 30) {
            return $this->todayAt();
        }
        if ($r <= 45) {
            return Carbon::yesterday()->addMinutes(mt_rand(540, 1320));
        }
        if ($r <= 70) {
            return Carbon::now()->subDays(mt_rand(2, 7))->setTime(mt_rand(9, 21), mt_rand(0, 59));
        }

        return Carbon::now()->subDays(mt_rand(8, 75))->setTime(mt_rand(9, 21), mt_rand(0, 59));
    }

    /** A random time earlier today (between midnight and now). */
    private function todayAt(): Carbon
    {
        $minutes = (int) max(1, Carbon::today()->diffInMinutes(Carbon::now()));

        return Carbon::today()->addMinutes(mt_rand(0, $minutes));
    }

    private function makeTicket($deptList, $empByDept, $titles, $statusPool, Carbon $date, int $branchId, ?int $visitId, bool $fromVisit, int $deptId, ?int $createdBy, ?string $forceStatus = null, ?int $forceTech = null): void
    {
        $dept = $deptList->firstWhere('id', $deptId);
        $prefix = $dept->ticket_prefix ?? 'GEN';

        $status = $forceStatus ?? $statusPool[array_rand($statusPool)];
        // priority biased to medium (default), occasional high/critical/low
        $priorities = ['medium', 'medium', 'medium', 'medium', 'low', 'high', 'critical'];
        $priority = $priorities[array_rand($priorities)];

        $notes = [
            'اللمبة بتقفل وتفتح لوحدها، محتاجة فحص.',
            'التكييف بيطلّع مية على الأرض ومش بيبرّد كويس.',
            'الدهان متقشّر في ركن البروفة ومحتاج معالجة.',
            'واجهة المحل عليها أتربة وبقع، محتاجة تنظيف.',
            'المراية اتكسرت في غرفة القياس.',
            'الباب الرئيسي بيعلّق ومش بيقفل بإحكام.',
            'جهاز الكاشير بيفصل كل شوية أثناء البيع.',
            'كاميرا المدخل مش شغّالة من امبارح.',
            'في نقص أكياس وشنط بيع عند الكاشير.',
            'نص لمبات اليافطة الخارجية مطفّية.',
            'بلاطة مكسورة قدام المخزن ممكن تخبط حد.',
            'تسريب مياه تحت حوض الحمام.',
            'رف العرض الجانبي مفكوك ومايل.',
            'مانيكان الفاترينة إيده مكسورة.',
            'الإضاءة الخارجية بتفصل بالليل.',
        ];

        $groupCode = $fromVisit ? null : 'MR-'.str_pad((string) ((Ticket::max('id') ?? 0) + 1), 5, '0', STR_PAD_LEFT);

        $ticket = Ticket::create([
            'reference' => Ticket::nextReference($prefix),
            'group_code' => $groupCode,
            'title' => $titles[array_rand($titles)],
            'description' => $notes[array_rand($notes)],
            'branch_id' => $branchId,
            'department_id' => $deptId,
            'visit_id' => $visitId,
            'created_by' => $createdBy,
            'status' => 'open',
            'priority' => $priority,
            'sla_hours' => 48,
            'due_at' => $date->copy()->addHours(48),
        ]);

        // timeline + advance to the chosen status
        $cursor = $date->copy();
        $this->addUpdate($ticket, 'created', null, 'open', $fromVisit ? 'طلب من الشيك ليست.' : 'تم إنشاء الطلب.', $cursor);

        $techs = $empByDept[$deptId] ?? [];
        $tech = $forceTech ?? (! empty($techs) ? $techs[array_rand($techs)] : null);

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
