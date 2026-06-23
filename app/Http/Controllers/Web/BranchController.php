<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Ticket;
use App\Support\DateRange;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $q = $request->query('q');

        $branches = Branch::query()
            ->when($q, fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('branch_name', 'like', "%{$q}%")
                    ->orWhere('area', 'like', "%{$q}%")
                    ->orWhere('city', 'like', "%{$q}%");
            }))
            ->orderBy('city')->orderBy('branch_name')
            ->get()
            ->map(function ($b) use ($range) {
                $b->visits_in_range = $b->visits()->whereBetween('created_at', [$range->from, $range->to])->count();
                $tickets = $b->tickets()->whereBetween('created_at', [$range->from, $range->to]);
                $b->tickets_in_range = (clone $tickets)->count();
                $b->open_tickets = (clone $tickets)->whereNotIn('status', ['closed'])->count();

                return $b;
            });

        $missingCoords = $branches->filter(fn ($b) => ! $b->hasCoordinates())->count();

        $summary = [
            'branches' => $branches->count(),
            'visits' => $branches->sum('visits_in_range'),
            'tickets' => $branches->sum('tickets_in_range'),
            'open' => $branches->sum('open_tickets'),
        ];

        return view('dashboard.branches.index', compact('branches', 'missingCoords', 'range', 'summary'));
    }

    public function show(Branch $branch)
    {
        $branch->load(['visits.template', 'visits.user']);

        $tickets = Ticket::with('department')->where('branch_id', $branch->id)->latest()->get();

        $repeated = Ticket::selectRaw('category, count(*) as total')
            ->where('branch_id', $branch->id)
            ->whereNotNull('category')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        return view('dashboard.branches.show', compact('branch', 'tickets', 'repeated'));
    }
}
