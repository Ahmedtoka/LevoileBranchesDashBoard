<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAnswer;
use App\Models\VisitTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Realistic demo generator tied to the REAL checklists, branches and team:
 *  - Every store manager fills ONE daily checklist per day (visible history).
 *  - Failed answers route tickets to the responsible departments (multi-dept supported).
 *  - HR / people-issue fails pick a real employee of that branch.
 *  - Store managers also request maintenance every couple of days.
 *  - Each area manager visits a covered branch per day and closes it with pass/fail.
 */
class DemoDataController extends Controller
{
    private const STORE_DAYS = 10;
    private const AREA_DAYS = 10;

    private array $statusPool = [];
    private array $notes = [
        'تم الرصد أثناء الجولة، يحتاج متابعة.',
        'الملاحظة متكررة من زيارة سابقة.',
        'محتاج تدخل سريع من الإدارة المختصة.',
        'تم التنبيه على الفريق وجارٍ المتابعة.',
        'الحالة غير مطابقة للمعايير المطلوبة.',
        'يحتاج إصلاح/تجهيز قبل ذروة المبيعات.',
    ];

    public function wipe()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['ticket_updates', 'ticket_evidence', 'visit_answer_evidence', 'visit_answer_selected_employees', 'visit_answers', 'tickets', 'visits', 'notifications'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return back()->with('status', 'تم مسح كل التذاكر والزيارات والشيك ليستات.');
    }

    public function generate()
    {
        @set_time_limit(300);

        $this->buildStatusPool();

        $branches = Branch::where('active', true)->get();
        $deptList = Department::all();
        $depts = $deptList->pluck('id', 'slug');
        $maintId = $depts['maintenance'] ?? null;
        $salesDept = Department::where('slug', 'sales')->first();

        $storeTpl = VisitTemplate::with('sections.questions')->where('type', 'store_manager')->first();
        $areaTpl = VisitTemplate::with('sections.questions')->where('type', 'area_manager')->first();
        $storeQs = $this->flatten($storeTpl);
        $areaQs = $this->flatten($areaTpl);

        $storeManagers = User::whereHas('role', fn ($q) => $q->where('slug', 'store_manager'))
            ->whereNotNull('branch_id')->get();
        $areaManagers = User::whereHas('role', fn ($q) => $q->where('slug', 'area_manager'))->get();
        $maintTechs = $maintId ? User::where('department_id', $maintId)->where('is_department_manager', false)->pluck('id')->all() : [];

        // branch employees (sales staff) for people-issue picks
        $branchEmps = [];
        if ($salesDept) {
            User::where('department_id', $salesDept->id)->whereNotNull('branch_id')
                ->get(['id', 'branch_id'])->each(function ($u) use (&$branchEmps) {
                    $branchEmps[$u->branch_id][] = $u->id;
                });
        }

        $maintTitles = [
            'صيانة — لمبة لا تعمل', 'صيانة — تكييف ضعيف', 'دهان حائط يحتاج معالجة', 'باب لا يغلق جيدًا',
            'تسريب مياه', 'رف مكسور', 'مانيكان يحتاج صيانة', 'كاميرا CCTV معطلة', 'POS لا يعمل', 'يافطة تحتاج تنظيف',
        ];

        $created = 0;
        $visits = 0;

        DB::transaction(function () use (
            $storeManagers, $areaManagers, $storeTpl, $areaTpl, $storeQs, $areaQs,
            $deptList, $maintId, $maintTechs, $branchEmps, $branches, $maintTitles, &$created, &$visits
        ) {
            // 1) STORE MANAGERS — one daily checklist per day
            foreach ($storeManagers as $sm) {
                $branchId = $sm->branch_id;
                $emps = $branchEmps[$branchId] ?? [];

                for ($d = 0; $d < self::STORE_DAYS; $d++) {
                    $date = $this->dayDate($d);
                    $visit = $this->makeVisit($storeTpl->id, $branchId, $sm->id, $date);
                    $created += $this->fillVisit($visit, $storeQs, $deptList, $maintTechs, $emps, $sm->id, $branchId, $date);
                    $visits++;
                }

                // maintenance requests every 2-3 days
                for ($d = 0; $d < self::STORE_DAYS; $d += rand(2, 3)) {
                    $date = $this->dayDate($d);
                    $group = 'MR-'.str_pad((string) ((Ticket::max('id') ?? 0) + 1), 5, '0', STR_PAD_LEFT);
                    foreach (range(1, rand(1, 2)) as $_) {
                        $this->makeTicket($deptList, $maintTechs, $date, $maintId, $branchId, $sm->id, null, null, null,
                            $maintTitles[array_rand($maintTitles)], $this->notes[array_rand($this->notes)],
                            $group, ['painting', 'electrical', 'plumbing', 'carpentry', 'air-conditioning'][array_rand([0, 1, 2, 3, 4])]);
                        $created++;
                    }
                }
            }

            // 2) AREA MANAGERS — visit a covered branch per day, close with pass/fail
            foreach ($areaManagers as $am) {
                $cov = $am->branches()->pluck('branches.id')->all();
                if (empty($cov)) {
                    $cov = $branches->random(min(5, $branches->count()))->pluck('id')->all();
                }
                for ($d = 0; $d < self::AREA_DAYS; $d++) {
                    $branchId = $cov[$d % count($cov)];
                    $emps = $branchEmps[$branchId] ?? [];
                    $date = $this->dayDate($d);
                    $visit = $this->makeVisit($areaTpl->id, $branchId, $am->id, $date);
                    $created += $this->fillVisit($visit, $areaQs, $deptList, $maintTechs, $emps, $am->id, $branchId, $date);
                    $visits++;
                }
                // a couple of upcoming visits still to do
                foreach (array_slice($cov, 0, 2) as $branchId) {
                    Visit::create([
                        'visit_template_id' => $areaTpl->id, 'branch_id' => $branchId, 'user_id' => $am->id,
                        'status' => 'assigned', 'scheduled_date' => Carbon::today()->addDays(rand(0, 3))->toDateString(),
                    ]);
                }
            }
        });

        return back()->with('status', "تم توليد ديمو حقيقي — {$visits} شيك ليست و{$created} تذكرة موزّعة على الإدارات.");
    }

    // ---------- core ----------

    /** Flatten a template into [['id'=>, 'deptIds'=>[], 'people'=>bool, 'title'=>], ...]. */
    private function flatten(?VisitTemplate $tpl): array
    {
        if (! $tpl) {
            return [];
        }
        $out = [];
        foreach ($tpl->sections as $sec) {
            foreach ($sec->questions as $q) {
                $out[] = [
                    'id' => $q->id,
                    'deptIds' => $q->departmentIds(),
                    'people' => (bool) $q->is_people_issue,
                    'title' => Str::limit($q->question_text_ar ?: $q->question_text, 120),
                ];
            }
        }

        return $out;
    }

    private function makeVisit(int $templateId, int $branchId, int $userId, Carbon $date): Visit
    {
        $visit = Visit::create([
            'visit_template_id' => $templateId, 'branch_id' => $branchId, 'user_id' => $userId,
            'status' => 'completed', 'scheduled_date' => $date->toDateString(),
            'checked_in_at' => $date, 'started_at' => $date, 'completed_at' => $date->copy()->addHour(),
        ]);
        $this->backdate('visits', $visit->id, $date);

        return $visit;
    }

    /** Answer the visit (bulk pass + individual fails), route tickets. Returns tickets created. */
    private function fillVisit(Visit $visit, array $questions, $deptList, array $maintTechs, array $emps, int $createdBy, int $branchId, Carbon $date): int
    {
        if (empty($questions)) {
            return 0;
        }
        $total = count($questions);
        $failCount = min($total, rand(1, 3));
        $failIdx = (array) array_rand($questions, $failCount);
        $failSet = array_flip($failIdx);

        // bulk-insert the passed answers
        $passRows = [];
        foreach ($questions as $i => $q) {
            if (isset($failSet[$i])) {
                continue;
            }
            $passRows[] = [
                'visit_id' => $visit->id, 'checklist_question_id' => $q['id'], 'result' => 'pass',
                'created_at' => $date, 'updated_at' => $date,
            ];
        }
        if ($passRows) {
            foreach (array_chunk($passRows, 50) as $chunk) {
                DB::table('visit_answers')->insert($chunk);
            }
        }

        $tickets = 0;
        foreach ($failIdx as $i) {
            $q = $questions[$i];
            $answer = VisitAnswer::create([
                'visit_id' => $visit->id, 'checklist_question_id' => $q['id'],
                'result' => 'fail', 'comment' => $this->notes[array_rand($this->notes)],
            ]);
            $this->backdate('visit_answers', $answer->id, $date);

            $names = [];
            if ($q['people'] && ! empty($emps)) {
                $pick = array_slice($emps, 0, 1); // one concerned employee
                $answer->selectedEmployees()->attach($pick);
                $names = User::whereIn('id', $pick)->pluck('name')->all();
            }

            $desc = $this->notes[array_rand($this->notes)];
            if ($names) {
                $desc = 'الموظفون المعنيون: '.implode('، ', $names)."\n".$desc;
            }

            $deptIds = ! empty($q['deptIds']) ? $q['deptIds'] : [null];
            foreach ($deptIds as $deptId) {
                $this->makeTicket($deptList, $maintTechs, $date, $deptId, $branchId, $createdBy, $visit->id, $answer->id, $q['id'],
                    $q['title'], $desc, null, null);
                $tickets++;
            }
        }

        $visit->update([
            'positives_count' => $total - $failCount,
            'problems_count' => $failCount,
            'tickets_count' => $tickets,
        ]);

        return $tickets;
    }

    private function makeTicket($deptList, array $maintTechs, Carbon $date, ?int $deptId, int $branchId, int $createdBy, ?int $visitId, ?int $answerId, ?int $questionId, string $title, string $desc, ?string $groupCode, ?string $category): void
    {
        $dept = $deptId ? $deptList->firstWhere('id', $deptId) : null;
        $prefix = $dept->ticket_prefix ?? 'GEN';

        $status = $this->statusPool[array_rand($this->statusPool)];
        $priorities = ['medium', 'medium', 'medium', 'medium', 'low', 'high', 'critical'];

        $ticket = Ticket::create([
            'reference' => Ticket::nextReference($prefix),
            'group_code' => $groupCode,
            'title' => $title,
            'description' => $desc,
            'branch_id' => $branchId,
            'department_id' => $deptId,
            'visit_id' => $visitId,
            'visit_answer_id' => $answerId,
            'checklist_question_id' => $questionId,
            'created_by' => $createdBy,
            'status' => 'open',
            'priority' => $priorities[array_rand($priorities)],
            'sla_hours' => 48,
            'due_at' => $date->copy()->addHours(48),
            'category' => $category,
        ]);

        $cursor = $date->copy();
        $this->addUpdate($ticket, 'created', null, 'open', $visitId ? 'طلب من الشيك ليست.' : 'تم إنشاء الطلب.', $cursor);

        // maintenance tickets get assigned to a technician
        $tech = ($deptId && in_array($deptId, $deptList->pluck('id')->all()) && ! empty($maintTechs) && $dept && $dept->slug === 'maintenance')
            ? $maintTechs[array_rand($maintTechs)] : null;

        $assignedTo = null;
        $assignedAt = null;
        $closedAt = null;
        foreach ($this->pathTo($status) as $step) {
            $cursor = $cursor->copy()->addHours(rand(2, 26));
            if ($step === 'assigned') {
                $assignedTo = $tech;
                $assignedAt = $cursor->copy();
                $this->addUpdate($ticket, 'assignment', 'open', 'assigned', 'تعيين للفني', $cursor, $tech);
            } elseif ($step === 'rejected') {
                $assignedTo = null;
                $this->addUpdate($ticket, 'declined', 'assigned', 'rejected', 'رفض الفني.', $cursor, $tech);
            } else {
                $this->addUpdate($ticket, 'status_change', null, $step, $this->stepNote($step), $cursor, $tech);
                if ($step === 'closed') {
                    $closedAt = $cursor->copy();
                }
            }
        }

        DB::table('tickets')->where('id', $ticket->id)->update([
            'status' => $status, 'assigned_to' => $assignedTo, 'assigned_at' => $assignedAt,
            'closed_at' => $closedAt, 'created_at' => $date, 'updated_at' => $cursor,
        ]);
    }

    /** A datetime for $d days ago; for today it is always BEFORE now (so "today" filters include it). */
    private function dayDate(int $d): Carbon
    {
        if ($d <= 0) {
            $minutes = (int) max(1, Carbon::today()->diffInMinutes(Carbon::now()));

            return Carbon::today()->addMinutes(rand(0, $minutes));
        }

        return Carbon::now()->subDays($d)->setTime(rand(9, 21), rand(0, 59));
    }

    private function stepNote(string $step): ?string
    {
        return [
            'on_the_way' => 'تم قبول الطلب', 'in_progress' => 'تم بدء العمل', 'waiting_approval' => 'تم التصليح',
            'postponed' => 'تم التأجيل', 'not_fixed' => 'لم يتم التصليح', 'closed' => 'تم الإغلاق',
        ][$step] ?? null;
    }

    private function buildStatusPool(): void
    {
        $dist = ['open' => 22, 'assigned' => 14, 'on_the_way' => 10, 'in_progress' => 14,
            'waiting_approval' => 10, 'closed' => 16, 'postponed' => 6, 'not_fixed' => 4, 'rejected' => 4];
        foreach ($dist as $st => $w) {
            $this->statusPool = array_merge($this->statusPool, array_fill(0, $w, $st));
        }
    }

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
