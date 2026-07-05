<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseKpiController extends Controller
{
    private function guard(Request $request): void
    {
        $u = $request->user();
        $slug = $u->department_id ? optional(Department::find($u->department_id))->slug : null;
        abort_unless($u->hasRole(['super_admin', 'ops_manager']) || in_array($slug, ['purchasing', 'finance'], true), 403);
    }

    public function index(Request $request)
    {
        $this->guard($request);
        $range = DateRange::fromRequest($request, 'month');
        [$from, $to] = [$range->from, $range->to];

        $scope = fn () => PurchaseRequest::query()->whereBetween('requested_at', [$from, $to]);

        // Duration averages (days).
        $avgPricing = (clone $scope())->whereNotNull('pricing_started_at')->whereNotNull('pricing_ended_at')
            ->select(DB::raw('AVG(DATEDIFF(pricing_ended_at, pricing_started_at)) as d'))->value('d');
        $avgExecution = (clone $scope())->whereNotNull('execution_started_at')->whereNotNull('execution_ended_at')
            ->select(DB::raw('AVG(DATEDIFF(execution_ended_at, execution_started_at)) as d'))->value('d');
        $avgTotal = (clone $scope())->where('status', 'purchased')
            ->whereNotNull('execution_ended_at')
            ->select(DB::raw('AVG(DATEDIFF(execution_ended_at, requested_at)) as d'))->value('d');

        $openStatuses = PurchaseRequest::OPEN_STATUSES;
        $openCount = (clone $scope())->whereIn('status', $openStatuses)->count();
        $delayed = (clone $scope())->whereIn('status', $openStatuses)
            ->whereRaw('DATEDIFF(NOW(), requested_at) > (COALESCE(sla_pricing_days,3) + COALESCE(sla_execution_days,7))')
            ->count();

        $kpi = [
            'avg_pricing' => $avgPricing !== null ? round($avgPricing, 1) : null,
            'avg_execution' => $avgExecution !== null ? round($avgExecution, 1) : null,
            'avg_total' => $avgTotal !== null ? round($avgTotal, 1) : null,
            'open' => $openCount,
            'delayed' => $delayed,
            'delayed_pct' => $openCount ? (int) round($delayed / $openCount * 100) : null,
        ];

        // Status distribution.
        $byStatus = (clone $scope())->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->toArray();

        // By buyer.
        $byBuyer = (clone $scope())->whereNotNull('buyer_id')
            ->select('buyer_id',
                DB::raw('count(*) as n'),
                DB::raw('AVG(DATEDIFF(pricing_ended_at, pricing_started_at)) as avg_pricing'),
                DB::raw('SUM(status="purchased") as done'))
            ->groupBy('buyer_id')->orderByDesc('n')->get()
            ->map(function ($r) {
                $r->buyer_name = optional(User::find($r->buyer_id))->name ?? '—';
                $r->avg_pricing = $r->avg_pricing !== null ? round($r->avg_pricing, 1) : null;

                return $r;
            });

        // By category (count + spend).
        $byCategory = (clone $scope())->whereNotNull('category')
            ->select('category', DB::raw('count(*) as n'), DB::raw('SUM(execution_price) as spend'))
            ->groupBy('category')->orderByDesc('n')->limit(15)->get();

        // Slowest open requests.
        $slow = (clone $scope())->whereIn('status', $openStatuses)
            ->with(['branch', 'requestingDepartment', 'buyer'])
            ->orderBy('requested_at')->limit(15)->get();

        return view('dashboard.purchasing.kpis', compact('range', 'kpi', 'byStatus', 'byBuyer', 'byCategory', 'slow'));
    }
}
