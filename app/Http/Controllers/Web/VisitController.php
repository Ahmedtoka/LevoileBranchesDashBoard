<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTemplate;
use App\Support\DateRange;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    /** Schedule / assign a visit. */
    public function create(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $q = $request->query('q');

        $templates = VisitTemplate::where('active', true)->orderBy('name')->get();
        $branches = Branch::where('active', true)->orderBy('branch_name')->get();
        $users = User::where('active', true)->with('role')->orderBy('name')->get();

        $base = Visit::whereBetween('created_at', [$range->from, $range->to]);
        $stats = [
            'total' => (clone $base)->count(),
            'assigned' => (clone $base)->where('status', 'assigned')->count(),
            'in_progress' => (clone $base)->whereIn('status', ['checked_in', 'in_progress'])->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
        ];
        $stats['not_started'] = $stats['assigned'];

        $visits = Visit::with(['template', 'branch', 'user'])
            ->whereBetween('created_at', [$range->from, $range->to])
            ->when($q, fn ($query) => $query->whereHas('branch', fn ($b) => $b->where('branch_name', 'like', "%{$q}%"))
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")))
            ->latest('scheduled_date')->get();

        return view('dashboard.visits.schedule', compact('templates', 'branches', 'users', 'visits', 'range', 'stats'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'visit_template_id' => ['required', 'exists:visit_templates,id'],
            'user_id' => ['required', 'exists:users,id'],
            'branch_id' => ['required', 'array', 'min:1'],
            'branch_id.*' => ['exists:branches,id'],
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => ['nullable', 'string'],
        ]);

        $count = 0;
        foreach ($data['branch_id'] as $branchId) {
            Visit::firstOrCreate([
                'visit_template_id' => $data['visit_template_id'],
                'branch_id' => $branchId,
                'user_id' => $data['user_id'],
                'status' => 'assigned',
                'scheduled_date' => $data['scheduled_date'],
            ], ['scheduled_time' => $data['scheduled_time'] ?? null]);
            $count++;
        }

        return back()->with('status', "Assigned the visit on {$count} branch(es). It will appear on the user's mobile.");
    }

    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $q = $request->query('q');
        $status = $request->query('status');

        $base = Visit::whereBetween('created_at', [$range->from, $range->to]);
        $stats = [
            'total' => (clone $base)->count(),
            'open' => (clone $base)->whereIn('status', ['assigned', 'checked_in', 'in_progress'])->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'problems' => (clone $base)->sum('problems_count'),
        ];

        $visits = Visit::with(['branch', 'template', 'user'])
            ->whereBetween('created_at', [$range->from, $range->to])
            ->when($status === 'open', fn ($query) => $query->whereIn('status', ['assigned', 'checked_in', 'in_progress']))
            ->when($status === 'completed', fn ($query) => $query->whereIn('status', ['completed', 'cancelled']))
            ->when($q, fn ($query) => $query->whereHas('branch', fn ($b) => $b->where('branch_name', 'like', "%{$q}%")))
            ->latest()->paginate(20)->withQueryString();

        $filter = $status ?? 'all';

        return view('dashboard.visits.index', compact('visits', 'filter', 'range', 'stats'));
    }

    public function show(Visit $visit)
    {
        $visit->load([
            'branch', 'template.sections.questions.responsibleDepartment',
            'user', 'answers.evidence', 'answers.selectedEmployees', 'tickets.department',
        ]);

        $answers = $visit->answers->keyBy('checklist_question_id');

        return view('dashboard.visits.show', compact('visit', 'answers'));
    }
}
