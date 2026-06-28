<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visit;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KPIs & deep reports.
 *
 * Access:
 *  - super_admin / ops_manager  → see everything.
 *  - department managers         → see only their department's ticket-lifecycle/SLA.
 *  - Visit-quality reports (timing, integrity, compliance) are ops-level only.
 */
class KpiController extends Controller
{
    /** On-time window for visit arrival (minutes after the scheduled time). */
    private const ONTIME_MIN = 30;

    /** "Rushing" thresholds: median gap between answers + pass rate. */
    private const RUSH_GAP_SEC = 8;
    private const RUSH_PASS_RATE = 0.90;
    private const RUSH_MIN_ANSWERS = 5;

    /** Department id to scope to (department managers), or null for full access. */
    private function deptId(Request $request): ?int
    {
        $u = $request->user();

        return ($u->is_department_manager && $u->department_id) ? (int) $u->department_id : null;
    }

    private function isOps(Request $request): bool
    {
        return $request->user()->hasRole(['super_admin', 'ops_manager']);
    }

    private function guardOps(Request $request): void
    {
        abort_unless($this->isOps($request), 403, 'هذا التقرير متاح للإدارة العليا فقط.');
    }

    // ====================================================================
    // HUB
    // ====================================================================
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        [$from, $to] = [$range->from, $range->to];
        $deptId = $this->deptId($request);
        $ops = $this->isOps($request);

        // Headline numbers (cheap aggregates).
        $closed = Ticket::query()
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->whereNotNull('closed_at')->whereBetween('closed_at', [$from, $to]);

        $avgResolution = (clone $closed)->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) as h'))->value('h');
        $avgAssign = Ticket::query()
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->whereNotNull('assigned_at')->whereBetween('created_at', [$from, $to])
            ->select(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, created_at, assigned_at)) as m'))->value('m');

        $kpi = [
            'avg_resolution_h' => $avgResolution ? round($avgResolution, 1) : null,
            'avg_assign_min' => $avgAssign ? round($avgAssign) : null,
            'closed' => (clone $closed)->count(),
        ];

        $visitKpi = null;
        if ($ops) {
            $vt = $this->visitTimingRows($from, $to);
            $total = $vt->count();
            $onTime = $vt->where('on_time', true)->count();
            $flagged = $this->integrityVisits($from, $to)->where('flagged', true)->count();
            $visitKpi = [
                'visits' => $total,
                'ontime_pct' => $total ? (int) round($onTime / $total * 100) : null,
                'flagged' => $flagged,
            ];
        }

        return view('dashboard.kpis.index', compact('range', 'deptId', 'ops', 'kpi', 'visitKpi'));
    }

    // ====================================================================
    // 1) TICKET LIFECYCLE / SLA
    // ====================================================================
    public function tickets(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        [$from, $to] = [$range->from, $range->to];
        $deptId = $this->deptId($request);
        $scopeDept = $deptId ? Department::find($deptId) : null;

        $tickets = Ticket::query()
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'department_id', 'branch_id', 'status', 'created_at', 'assigned_at', 'resolved_at', 'closed_at', 'due_at', 'reference', 'title']);

        $ids = $tickets->pluck('id');

        // First time each ticket reached each status.
        $stageRows = DB::table('ticket_updates')
            ->whereIn('ticket_id', $ids)
            ->whereNotNull('to_status')
            ->select('ticket_id', 'to_status', DB::raw('MIN(created_at) as at'))
            ->groupBy('ticket_id', 'to_status')
            ->get();
        $stage = [];
        foreach ($stageRows as $r) {
            $stage[$r->ticket_id][$r->to_status] = Carbon::parse($r->at);
        }

        // Average hours for each lifecycle transition.
        $transitions = [
            'to_assign' => ['from' => 'created', 'to' => 'assigned', 'label' => 'الفتح ← التعيين'],
            'to_accept' => ['from' => 'assigned', 'to' => 'on_the_way', 'label' => 'التعيين ← القبول'],
            'to_start' => ['from' => 'on_the_way', 'to' => 'in_progress', 'label' => 'القبول ← بدء العمل'],
            'to_fix' => ['from' => 'in_progress', 'to' => 'waiting_approval', 'label' => 'بدء العمل ← الإصلاح'],
            'to_close' => ['from' => 'waiting_approval', 'to' => 'closed', 'label' => 'الإصلاح ← الإغلاق'],
        ];
        $sums = array_fill_keys(array_keys($transitions), 0.0);
        $counts = array_fill_keys(array_keys($transitions), 0);
        $totalSum = 0.0;
        $totalCount = 0;

        $tsOf = function ($tk, $sName) use ($stage) {
            if ($sName === 'created') {
                return $tk->created_at;
            }
            if ($sName === 'assigned' && $tk->assigned_at) {
                return $tk->assigned_at;
            }
            if ($sName === 'waiting_approval' && $tk->resolved_at) {
                return $tk->resolved_at;
            }
            if ($sName === 'closed' && $tk->closed_at) {
                return $tk->closed_at;
            }

            return $stage[$tk->id][$sName] ?? null;
        };

        foreach ($tickets as $tk) {
            foreach ($transitions as $key => $tr) {
                $a = $tsOf($tk, $tr['from']);
                $b = $tsOf($tk, $tr['to']);
                if ($a && $b && $b->getTimestamp() >= $a->getTimestamp()) {
                    $sums[$key] += ($b->getTimestamp() - $a->getTimestamp()) / 3600;
                    $counts[$key]++;
                }
            }
            if ($tk->closed_at) {
                $totalSum += ($tk->closed_at->getTimestamp() - $tk->created_at->getTimestamp()) / 3600;
                $totalCount++;
            }
        }

        $stageAvg = [];
        foreach ($transitions as $key => $tr) {
            $stageAvg[] = [
                'label' => $tr['label'],
                'hours' => $counts[$key] ? round($sums[$key] / $counts[$key], 1) : null,
                'n' => $counts[$key],
            ];
        }
        $avgTotal = $totalCount ? round($totalSum / $totalCount, 1) : null;

        // Per-department breakdown (admins) — avg resolution + SLA compliance.
        $byDept = collect();
        if (! $deptId) {
            $byDept = Department::query()
                ->whereHas('tickets', fn ($q) => $q->whereBetween('created_at', [$from, $to]))
                ->get()
                ->map(function ($d) use ($from, $to) {
                    $closed = Ticket::where('department_id', $d->id)->whereNotNull('closed_at')->whereBetween('closed_at', [$from, $to]);
                    $avg = (clone $closed)->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) as h'))->value('h');
                    $withDue = (clone $closed)->whereNotNull('due_at')->count();
                    $met = (clone $closed)->whereNotNull('due_at')->whereColumn('closed_at', '<=', 'due_at')->count();
                    $d->avg_h = $avg ? round($avg, 1) : null;
                    $d->closed_n = (clone $closed)->count();
                    $d->sla_pct = $withDue ? (int) round($met / $withDue * 100) : null;

                    return $d;
                })
                ->sortByDesc('closed_n')->values();
        }

        // SLA summary for the scope.
        $closedScope = Ticket::query()
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->whereNotNull('closed_at')->whereBetween('closed_at', [$from, $to]);
        $withDue = (clone $closedScope)->whereNotNull('due_at')->count();
        $met = (clone $closedScope)->whereNotNull('due_at')->whereColumn('closed_at', '<=', 'due_at')->count();
        $sla = [
            'closed' => (clone $closedScope)->count(),
            'sla_pct' => $withDue ? (int) round($met / $withDue * 100) : null,
            'breached' => $withDue - $met,
            'open' => Ticket::query()->when($deptId, fn ($q) => $q->where('department_id', $deptId))
                ->whereBetween('created_at', [$from, $to])->whereNotIn('status', ['closed'])->count(),
        ];

        // Slowest tickets currently open (age) for action.
        $slow = Ticket::query()
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->whereNotIn('status', ['closed'])
            ->whereBetween('created_at', [$from, $to])
            ->with(['branch', 'department', 'assignee'])
            ->orderBy('created_at')
            ->limit(15)->get();

        return view('dashboard.kpis.tickets', compact('range', 'scopeDept', 'stageAvg', 'avgTotal', 'byDept', 'sla', 'slow'));
    }

    // ====================================================================
    // 2) VISIT TIMING / ON-TIME
    // ====================================================================
    public function visits(Request $request)
    {
        $this->guardOps($request);
        $range = DateRange::fromRequest($request, 'month');
        $rows = $this->visitTimingRows($range->from, $range->to);

        $total = $rows->count();
        $onTime = $rows->where('on_time', true)->count();
        $summary = [
            'visits' => $total,
            'ontime' => $onTime,
            'ontime_pct' => $total ? (int) round($onTime / $total * 100) : null,
            'avg_delay' => $total ? round($rows->avg('delay_min')) : null,
            'avg_duration' => $total ? round($rows->avg('duration_min')) : null,
        ];

        // Per area-manager aggregation.
        $byManager = $rows->groupBy('user_id')->map(function ($g) {
            $n = $g->count();
            $ot = $g->where('on_time', true)->count();

            return [
                'name' => $g->first()['user_name'],
                'visits' => $n,
                'ontime' => $ot,
                'ontime_pct' => $n ? (int) round($ot / $n * 100) : 0,
                'avg_delay' => round($g->avg('delay_min')),
                'avg_duration' => round($g->avg('duration_min')),
            ];
        })->sortByDesc('ontime_pct')->values();

        // Late visits list (worst first).
        $late = $rows->where('on_time', false)->sortByDesc('delay_min')->take(20)->values();

        return view('dashboard.kpis.visits', compact('range', 'summary', 'byManager', 'late'));
    }

    /** Build per-visit timing rows for area-manager visits in [$from,$to]. */
    private function visitTimingRows(Carbon $from, Carbon $to)
    {
        $visits = Visit::query()
            ->whereHas('template', fn ($t) => $t->where('type', 'area_manager'))
            ->whereNotNull('completed_at')->whereNotNull('checked_in_at')
            ->whereBetween('checked_in_at', [$from, $to])
            ->with(['user:id,name', 'branch:id,branch_name'])
            ->get();

        return $visits->map(function ($v) {
            $checkIn = $v->checked_in_at;
            $sched = ($v->scheduled_time && $v->scheduled_date)
                ? Carbon::parse($v->scheduled_date->toDateString().' '.$v->scheduled_time)
                : null;
            $delay = $sched ? (int) round(($checkIn->getTimestamp() - $sched->getTimestamp()) / 60) : 0; // + = late
            $duration = (int) round(($v->completed_at->getTimestamp() - $checkIn->getTimestamp()) / 60);

            return [
                'visit_id' => $v->id,
                'user_id' => $v->user_id,
                'user_name' => optional($v->user)->name ?? '—',
                'branch' => optional($v->branch)->branch_name ?? '—',
                'date' => $checkIn->format('d M'),
                'scheduled' => $v->scheduled_time ? substr($v->scheduled_time, 0, 5) : '—',
                'checkin' => $checkIn->format('H:i'),
                'delay_min' => (int) $delay,
                'duration_min' => (int) $duration,
                'on_time' => $delay <= self::ONTIME_MIN,
            ];
        });
    }

    // ====================================================================
    // 3) CHECKLIST INTEGRITY ("rushing" — طخ طخ)
    // ====================================================================
    public function integrity(Request $request)
    {
        $this->guardOps($request);
        $range = DateRange::fromRequest($request, 'month');
        $rows = $this->integrityVisits($range->from, $range->to);

        $total = $rows->count();
        $flagged = $rows->where('flagged', true)->count();
        $summary = [
            'visits' => $total,
            'flagged' => $flagged,
            'flagged_pct' => $total ? (int) round($flagged / $total * 100) : null,
            'avg_sec_per_q' => $total ? round($rows->avg('sec_per_q')) : null,
        ];

        // Per-manager aggregation.
        $byManager = $rows->groupBy('user_id')->map(function ($g) {
            $n = $g->count();
            $fl = $g->where('flagged', true)->count();

            return [
                'name' => $g->first()['user_name'],
                'role' => $g->first()['role'],
                'visits' => $n,
                'flagged' => $fl,
                'flagged_pct' => $n ? (int) round($fl / $n * 100) : 0,
                'avg_sec_per_q' => round($g->avg('sec_per_q')),
                'avg_pass_rate' => round($g->avg('pass_rate') * 100),
            ];
        })->sortByDesc('flagged_pct')->values();

        // Flagged visits (most suspicious first).
        $suspicious = $rows->where('flagged', true)->sortBy('median_gap')->take(25)->values();

        return view('dashboard.kpis.integrity', compact('range', 'summary', 'byManager', 'suspicious'));
    }

    /** Per-visit integrity metrics for completed visits in [$from,$to]. */
    private function integrityVisits(Carbon $from, Carbon $to)
    {
        $visits = Visit::query()
            ->whereNotNull('completed_at')->whereNotNull('checked_in_at')
            ->whereBetween('checked_in_at', [$from, $to])
            ->with(['user:id,name', 'branch:id,branch_name', 'template:id,type'])
            ->get();

        if ($visits->isEmpty()) {
            return collect();
        }

        $answers = DB::table('visit_answers')
            ->whereIn('visit_id', $visits->pluck('id'))
            ->select('visit_id', 'result', 'created_at')
            ->orderBy('visit_id')->orderBy('created_at')
            ->get()
            ->groupBy('visit_id');

        return $visits->map(function ($v) use ($answers) {
            $rows = $answers->get($v->id, collect());
            $n = $rows->count();
            if ($n < 2) {
                return null;
            }

            // Gaps (seconds) between consecutive answers.
            $times = $rows->map(fn ($r) => Carbon::parse($r->created_at)->getTimestamp())->values();
            $gaps = [];
            for ($i = 1; $i < $times->count(); $i++) {
                $gaps[] = max(0, $times[$i] - $times[$i - 1]);
            }
            sort($gaps);
            $mid = (int) floor(count($gaps) / 2);
            $median = count($gaps) % 2 ? $gaps[$mid] : (int) round(($gaps[$mid - 1] + $gaps[$mid]) / 2);

            $passes = $rows->where('result', 'pass')->count();
            $passRate = $n ? $passes / $n : 0;
            $duration = $v->completed_at->getTimestamp() - $v->checked_in_at->getTimestamp();
            $secPerQ = $n ? (int) round($duration / $n) : 0;

            $flagged = $median < self::RUSH_GAP_SEC && $passRate >= self::RUSH_PASS_RATE && $n >= self::RUSH_MIN_ANSWERS;

            return [
                'visit_id' => $v->id,
                'user_id' => $v->user_id,
                'user_name' => optional($v->user)->name ?? '—',
                'role' => optional($v->template)->type === 'area_manager' ? 'أريا مانجر' : 'مدير فرع',
                'branch' => optional($v->branch)->branch_name ?? '—',
                'date' => $v->checked_in_at->format('d M'),
                'answers' => $n,
                'median_gap' => $median,
                'sec_per_q' => $secPerQ,
                'pass_rate' => $passRate,
                'flagged' => $flagged,
            ];
        })->filter()->values();
    }

    // ====================================================================
    // 4) STORE-MANAGER DAILY CHECKLIST COMPLIANCE
    // ====================================================================
    public function compliance(Request $request)
    {
        $this->guardOps($request);
        $range = DateRange::fromRequest($request, 'month');
        $from = $range->from->copy()->startOfDay();
        $today = Carbon::today();
        $to = $range->to->copy()->endOfDay();
        if ($to->gt($today->copy()->endOfDay())) {
            $to = $today->copy()->endOfDay();
        }

        // Calendar of expected days (cap at today).
        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days[] = $d->toDateString();
        }

        $managers = User::whereHas('role', fn ($q) => $q->where('slug', 'store_manager'))
            ->whereNotNull('branch_id')->with('branch:id,branch_name')->get();

        // All store-manager completed visits in range: user_id, date, duration.
        $visitRows = DB::table('visits')
            ->join('visit_templates', 'visits.visit_template_id', '=', 'visit_templates.id')
            ->where('visit_templates.type', 'store_manager')
            ->whereNotNull('visits.completed_at')->whereNotNull('visits.checked_in_at')
            ->whereBetween('visits.scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->select('visits.user_id', 'visits.scheduled_date',
                DB::raw('TIMESTAMPDIFF(MINUTE, visits.checked_in_at, visits.completed_at) as dur'))
            ->get();

        $byUser = [];
        foreach ($visitRows as $r) {
            $byUser[$r->user_id][$r->scheduled_date] = $r->dur;
        }

        $expected = count($days);
        $rows = $managers->map(function ($m) use ($byUser, $days, $expected) {
            $done = $byUser[$m->id] ?? [];
            $doneDays = count($done);
            $durations = array_values($done);
            $grid = [];
            foreach ($days as $day) {
                $grid[$day] = isset($done[$day]) ? (int) $done[$day] : null;
            }

            return [
                'name' => $m->name,
                'branch' => optional($m->branch)->branch_name ?? '—',
                'done' => $doneDays,
                'expected' => $expected,
                'compliance' => $expected ? (int) round($doneDays / $expected * 100) : 0,
                'avg_duration' => $durations ? (int) round(array_sum($durations) / count($durations)) : null,
                'grid' => $grid,
            ];
        })->sortBy('compliance')->values();

        $overall = [
            'managers' => $managers->count(),
            'expected' => $expected,
            'avg_compliance' => $rows->count() ? (int) round($rows->avg('compliance')) : null,
            'avg_duration' => $rows->whereNotNull('avg_duration')->count() ? (int) round($rows->whereNotNull('avg_duration')->avg('avg_duration')) : null,
        ];

        return view('dashboard.kpis.compliance', compact('range', 'rows', 'days', 'overall'));
    }
}
