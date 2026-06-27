<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Models\VisitAnswer;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisitController extends Controller
{
    public function __construct(protected TicketService $tickets) {}

    /** GET /api/visits  — current user's visits */
    public function index(Request $request): JsonResponse
    {
        $query = Visit::with(['branch', 'template'])
            ->where('user_id', $request->user()->id)
            ->latest('scheduled_date');

        if ($status = $request->query('status')) {
            // open = assigned/checked_in/in_progress ; old = completed
            if ($status === 'open') {
                $query->whereIn('status', ['assigned', 'checked_in', 'in_progress']);
            } elseif ($status === 'old') {
                $query->whereIn('status', ['completed', 'cancelled']);
            } else {
                $query->where('status', $status);
            }
        }

        return response()->json([
            'data' => $query->get()->map(fn ($v) => $this->visitListItem($v)),
        ]);
    }

    /**
     * GET /api/visits/branch-checklists — store-manager daily checklists for the
     * branches the current user is responsible for (area manager coverage / own branch).
     */
    public function branchChecklists(Request $request): JsonResponse
    {
        $user = $request->user();

        $branchIds = $user->branches()->pluck('branches.id')->all();
        if (empty($branchIds) && $user->branch_id) {
            $branchIds = [$user->branch_id];
        }
        // ops managers / admins see all branches
        if ($user->hasRole(['ops_manager', 'super_admin', 'branch_director'])) {
            $branchIds = \App\Models\Branch::pluck('id')->all();
        }

        $status = $request->query('status', 'all'); // all | open | old
        $from = $request->query('from');
        $to = $request->query('to');
        $query = Visit::with(['branch', 'template'])
            ->whereHas('template', fn ($t) => $t->where('type', 'store_manager'))
            ->whereIn('branch_id', $branchIds)
            ->when($from, fn ($x) => $x->whereDate('scheduled_date', '>=', substr($from, 0, 10)))
            ->when($to, fn ($x) => $x->whereDate('scheduled_date', '<=', substr($to, 0, 10)))
            ->when($status === 'open', fn ($x) => $x->whereIn('status', ['assigned', 'checked_in', 'in_progress']))
            ->when($status === 'old', fn ($x) => $x->whereIn('status', ['completed', 'cancelled']))
            ->latest('scheduled_date');

        return response()->json([
            'data' => $query->get()->map(fn ($v) => $this->visitListItem($v)),
        ]);
    }

    /** GET /api/checklist/today — store manager's daily checklist (auto-create, no check-in). */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $user->branch_id;
        if (! $branchId) {
            return response()->json(['message' => 'لا يوجد فرع مرتبط بحسابك.'], 422);
        }

        $template = \App\Models\VisitTemplate::where('type', 'store_manager')->where('active', true)->orderBy('id')->first()
            ?? \App\Models\VisitTemplate::where('active', true)->orderBy('id')->first();
        if (! $template) {
            return response()->json(['message' => 'لا يوجد قالب شيك ليست مهيّأ.'], 422);
        }

        // Reuse an open visit for today, otherwise start a fresh one (store manager is on-site → skip check-in).
        $visit = Visit::where('user_id', $user->id)
            ->where('branch_id', $branchId)
            ->whereDate('scheduled_date', today())
            ->whereIn('status', ['assigned', 'checked_in', 'in_progress'])
            ->latest()->first();

        if (! $visit) {
            $visit = Visit::create([
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'visit_template_id' => $template->id,
                'scheduled_date' => today(),
                'status' => 'in_progress',
                'checked_in_at' => now(),
                'checkin_simulated' => true,
                'started_at' => now(),
            ]);
        }

        return $this->show($request, $visit);
    }

    /** GET /api/visits/{visit} — full details (also used for old read-only visits) */
    public function show(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeVisit($request, $visit);

        $visit->load([
            'branch', 'template.sections.questions.responsibleDepartment',
            'answers.evidence', 'answers.selectedEmployees', 'tickets',
        ]);

        $answersByQuestion = $visit->answers->keyBy('checklist_question_id');

        $sections = $visit->template->sections->map(function ($section) use ($answersByQuestion) {
            return [
                'id' => $section->id,
                'title' => $section->title,
                'title_ar' => $section->title_ar,
                'questions' => $section->questions->map(function ($q) use ($answersByQuestion) {
                    $a = $answersByQuestion->get($q->id);

                    return [
                        'id' => $q->id,
                        'question_text' => $q->question_text,
                        'question_text_ar' => $q->question_text_ar,
                        'type' => $q->type,
                        'input_type' => $q->input_type ?? 'boolean',
                        'options' => $q->options ?? [],
                        'options_style' => $q->options_style ?? 'buttons',
                        'pass_config' => $q->pass_config ?? [],
                        'fail_config' => $q->fail_config ?? [],
                        'max_score' => $q->max_score,
                        'responsible_department' => $q->responsibleDepartments()->pluck('name')->join(', ') ?: null,
                        'is_people_issue' => $q->is_people_issue,
                        'answer' => $a ? $this->answerPayload($a) : null,
                    ];
                })->values(),
            ];
        });

        // People-issue questions list the employees OF THIS BRANCH (the sales team
        // tied to the visited branch), so the picked employee is a real user.
        $staff = \App\Models\User::where('branch_id', $visit->branch_id)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'job_title'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'job' => $u->job_title]);

        return response()->json([
            'visit' => $this->visitListItem($visit),
            // read-only unless the viewer is the visit's own assignee (others can only view the result)
            'read_only' => $visit->isReadOnly() || $visit->user_id !== $request->user()->id,
            'general_comments' => $visit->general_comments,
            'employees' => $staff,
            'sections' => $sections,
            'tickets' => $visit->tickets->map(fn ($t) => [
                'id' => $t->id, 'reference' => $t->reference, 'title' => $t->title,
                'status' => $t->status, 'priority' => $t->priority,
            ]),
            'summary' => [
                'positives' => $visit->positives_count,
                'problems' => $visit->problems_count,
                'unanswered' => $visit->unanswered_count,
                'tickets' => $visit->tickets_count,
            ],
        ]);
    }

    /** POST /api/visits/{visit}/checkin */
    public function checkin(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeOwner($request, $visit);

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'simulated' => ['nullable', 'boolean'],
        ]);

        $branch = $visit->branch;
        $simulated = (bool) ($data['simulated'] ?? false);
        $distance = null;
        $withinRadius = true;

        if ($branch->hasCoordinates() && isset($data['latitude'], $data['longitude'])) {
            $distance = $this->haversine(
                $branch->latitude, $branch->longitude,
                $data['latitude'], $data['longitude']
            );
            $withinRadius = $distance <= $branch->checkin_radius;
        } else {
            // No branch coordinates -> allow simulated check-in for demo.
            $simulated = true;
        }

        if (! $withinRadius && ! $simulated) {
            return response()->json([
                'message' => 'You are too far from the branch to check in.',
                'distance_m' => round($distance),
                'allowed_radius_m' => $branch->checkin_radius,
            ], 422);
        }

        $visit->update([
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'checkin_latitude' => $data['latitude'] ?? null,
            'checkin_longitude' => $data['longitude'] ?? null,
            'checkin_simulated' => $simulated,
        ]);

        return response()->json([
            'message' => 'Checked in.',
            'distance_m' => $distance !== null ? round($distance) : null,
            'simulated' => $simulated,
        ]);
    }

    /** POST /api/visits/{visit}/start */
    public function start(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeOwner($request, $visit);

        if (! $visit->checked_in_at) {
            return response()->json(['message' => 'You must check in before starting the visit.'], 422);
        }

        $visit->update(['status' => 'in_progress', 'started_at' => $visit->started_at ?? now()]);

        return response()->json(['message' => 'Visit started.']);
    }

    /** POST /api/visits/{visit}/answers — submit one answer */
    public function submitAnswer(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeOwner($request, $visit);

        if ($visit->isReadOnly()) {
            return response()->json(['message' => 'This visit is read-only.'], 422);
        }

        $data = $request->validate([
            'checklist_question_id' => ['required', 'exists:checklist_questions,id'],
            'result' => ['nullable', 'in:pass,fail,na'],
            'value' => ['nullable', 'string'],          // JSON string {number, percentage}
            'comment' => ['nullable', 'string'],          // the "note" field
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'evidence' => ['nullable', 'array'],          // [{path, kind}]
            'evidence.*.path' => ['required', 'string'],
            'evidence.*.kind' => ['nullable', 'in:photo,video'],
        ]);

        $question = \App\Models\ChecklistQuestion::findOrFail($data['checklist_question_id']);
        $result = $data['result'] ?? null;

        if ($question->input_type === 'options') {
            // result holds the chosen option label; use that option's fields.
            $opt = collect($question->options ?? [])->first(fn ($o) => ($o['label'] ?? null) === $result);
            $config = $opt['fields'] ?? [];
        } else {
            $config = $result === 'fail' ? ($question->fail_config ?? []) : ($result === 'pass' ? ($question->pass_config ?? []) : []);
        }

        // Validate required outcome fields.
        $value = [];
        if (! empty($data['value'])) {
            $value = json_decode($data['value'], true) ?: [];
        }
        $evidence = $data['evidence'] ?? [];
        $hasKind = fn ($kind) => collect($evidence)->contains(fn ($e) => ($e['kind'] ?? 'photo') === $kind);

        foreach ($config as $field) {
            if (empty($field['required'])) {
                continue;
            }
            $type = $field['type'];
            $missing = match ($type) {
                'note' => empty($data['comment']),
                'number' => ! isset($value['number']) || $value['number'] === '',
                'percentage' => ! isset($value['percentage']),
                'photo' => ! $hasKind('photo'),
                'video' => ! $hasKind('video'),
                default => false,
            };
            if ($missing) {
                return response()->json(['message' => ucfirst($type)." is required when the item is {$result}."], 422);
            }
        }

        $answer = VisitAnswer::updateOrCreate(
            ['visit_id' => $visit->id, 'checklist_question_id' => $question->id],
            [
                'result' => $result,
                'value' => $data['value'] ?? null,
                'comment' => $data['comment'] ?? null,
                'score' => ($result === 'pass') ? $question->max_score : 0,
            ]
        );

        // Reset & re-attach evidence (photos + videos)
        if (array_key_exists('evidence', $data)) {
            $answer->evidence()->delete();
            foreach ($evidence as $e) {
                $answer->evidence()->create([
                    'path' => $e['path'],
                    'kind' => $e['kind'] ?? 'photo',
                    'source' => 'camera',
                ]);
            }
        }

        if (isset($data['employee_ids'])) {
            $answer->selectedEmployees()->sync($data['employee_ids']);
        }

        return response()->json(['message' => 'Answer saved.', 'answer' => $this->answerPayload($answer->fresh('evidence', 'selectedEmployees'))]);
    }

    /** POST /api/visits/{visit}/evidence — upload an image, returns stored path */
    public function uploadEvidence(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeVisit($request, $visit);

        $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/3gpp', 'max:51200'],
        ]);

        $file = $request->file('file');
        $kind = str_starts_with((string) $file->getMimeType(), 'video') ? 'video' : 'photo';
        $path = $file->store('evidence/visits/'.$visit->id, 'public');

        return response()->json(['path' => $path, 'kind' => $kind, 'url' => asset('storage/'.$path)]);
    }

    /** POST /api/visits/{visit}/submit — finalize, create tickets, close */
    public function submit(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeOwner($request, $visit);

        if ($visit->isReadOnly()) {
            return response()->json(['message' => 'This visit is already submitted.'], 422);
        }

        $data = $request->validate(['general_comments' => ['nullable', 'string']]);

        DB::transaction(function () use ($visit, $data) {
            $visit->load(['answers.question', 'answers.selectedEmployees', 'template.sections.questions']);

            $totalQuestions = $visit->template->sections->sum(fn ($s) => $s->questions->count());
            $answered = $visit->answers->whereNotNull('result')->count();
            $positives = $visit->answers->where('result', 'pass')->count();

            // Create tickets across all answered items (boolean fail OR ticket-flagged option).
            $created = 0;
            $problems = 0;
            foreach ($visit->answers->whereNotNull('result') as $answer) {
                $n = $this->tickets->createFromFailedAnswer($visit, $answer);
                $created += $n;
                if ($n > 0 || $answer->result === 'fail') {
                    $problems++;
                }
            }

            $score = (float) $visit->answers->sum('score');
            $maxScore = (float) $visit->template->sections->sum(
                fn ($s) => $s->questions->sum(fn ($q) => $q->max_score ?? 0)
            );

            $visit->update([
                'status' => 'completed',
                'completed_at' => now(),
                'positives_count' => $positives,
                'problems_count' => $problems,
                'unanswered_count' => max(0, $totalQuestions - $answered),
                'tickets_count' => $created,
                'score' => $score,
                'general_comments' => $data['general_comments'] ?? $visit->general_comments,
            ]);

            $visit->max_score = $maxScore;
        });

        return response()->json([
            'message' => 'Visit submitted. Tickets routed to responsible departments.',
            'summary' => [
                'positives' => $visit->positives_count,
                'problems' => $visit->problems_count,
                'unanswered' => $visit->unanswered_count,
                'tickets' => $visit->tickets_count,
                'score' => $visit->score,
                'max_score' => $visit->max_score ?? null,
            ],
        ]);
    }

    // ---------- helpers ----------

    protected function authorizeVisit(Request $request, Visit $visit): void
    {
        if ($visit->user_id !== $request->user()->id && ! $request->user()->hasRole(['super_admin', 'area_manager', 'branch_director', 'ops_manager'])) {
            abort(403, 'This visit is not assigned to you.');
        }
    }

    /** Only the visit's own assignee may check in / answer / submit (managers can only view). */
    protected function authorizeOwner(Request $request, Visit $visit): void
    {
        if ($visit->user_id !== $request->user()->id) {
            abort(403, 'هذه الزيارة مُسندة لشخص آخر — يمكنك عرض النتيجة فقط.');
        }
    }

    protected function visitListItem(Visit $v): array
    {
        return [
            'id' => $v->id,
            'code' => $v->code,
            'status' => $v->status,
            'scheduled_date' => optional($v->scheduled_date)->toDateString(),
            'scheduled_time' => $v->scheduled_time,
            'template' => optional($v->template)->name,
            'template_type' => optional($v->template)->type,
            'branch' => optional($v->branch)->branch_name,
            'branch_id' => $v->branch_id,
            'branch_lat' => optional($v->branch)->latitude,
            'branch_lng' => optional($v->branch)->longitude,
            'branch_radius' => optional($v->branch)->checkin_radius,
            'checked_in' => (bool) $v->checked_in_at,
            'read_only' => $v->isReadOnly(),
            'positives' => $v->positives_count,
            'problems' => $v->problems_count,
            'tickets' => $v->tickets_count,
            'completed_at' => optional($v->completed_at)->toDateTimeString(),
        ];
    }

    protected function answerPayload(VisitAnswer $a): array
    {
        return [
            'id' => $a->id,
            'result' => $a->result,
            'value' => $a->value,
            'comment' => $a->comment,
            'evidence' => $a->evidence->map(fn ($e) => ['url' => $e->url, 'kind' => $e->kind]),
            'employee_ids' => $a->selectedEmployees->pluck('id'),
        ];
    }

    protected function haversine($lat1, $lon1, $lat2, $lon2): float
    {
        $r = 6371000; // meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
