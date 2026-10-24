<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Observations\AiCheckRequest;
use App\Http\Requests\Observations\StoreObservationVisitRequest;
use App\Http\Requests\Observations\SubmitObservationVisitRequest;
use App\Http\Requests\Observations\UploadObservationPhotoRequest;
use App\Http\Requests\Observations\UpsertObservationsRequest;
use App\Http\Resources\Observations\ObservationItem;
use App\Http\Resources\Observations\ObservationVisitItem;
use App\Models\AiClassification;
use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Services\Observations\Ai\ClassifierContract;
use App\Services\Observations\ObservationService;
use App\Support\ObservationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Observation visits (spec section 11). Thin: validate (FormRequest) -> authorize (policy) -> service -> serialize.
 * Called by the Flutter app (bearer token) and the web page /observations/new (stateful Sanctum session).
 */
class ObservationVisitController extends Controller
{
    /** Spec section 8: shown when the classifier is unavailable (failed / skipped). */
    private const AI_UNAVAILABLE = 'الفحص غير متاح، اختر الفئة يدوياً';

    public function __construct(private ObservationService $service) {}

    /** GET /api/observation-visits?status=&mine=1&branch_id=&department_id=&scope=to_approve */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('create', ObservationVisit::class) || ObservationScope::approvableDepartmentIds($u) !== []);

        if ($request->query('scope') === 'to_approve') {
            // Plan H: submitted visits of the reporting department(s) the user approves for (null = all).
            $departmentIds = ObservationScope::approvableDepartmentIds($u);
            $query = ObservationVisit::query()->where('status', 'submitted')
                ->when($departmentIds !== null, fn ($q) => $q->whereIn('department_id', $departmentIds));
        } else {
            // Own visits OR the scope. A subquery, because for global roles the scope adds no condition and an
            // empty nested orWhere would be dropped, leaving only "own visits".
            $visible = ObservationScope::apply(ObservationVisit::query()->select('observation_visits.id'), $u, 'branch_id', 'department_id');
            $query = ObservationVisit::query()->where(fn ($q) => $q->where('user_id', $u->id)->orWhereIn('id', $visible));
        }

        $query
            ->when(in_array($request->query('status'), ObservationVisit::STATUSES, true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('user_id', $u->id))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', (int) $request->query('branch_id')))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', (int) $request->query('department_id')))
            ->with(ObservationVisitItem::WITH)
            ->withCount(ObservationVisitItem::counts())
            ->orderByDesc('id');

        $page = $query->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (ObservationVisit $v) => ObservationVisitItem::make($v))->all(),
            'meta' => self::pageMeta($page),
        ]);
    }

    /** POST /api/observation-visits */
    public function store(StoreObservationVisitRequest $request): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('create', ObservationVisit::class));

        $visit = $this->service->createVisit($u, [
            'department_id' => $request->input('department_id'),
            'branch_id' => $request->input('branch_id'),
            'type' => $request->input('type'),
            'visit_date' => $request->input('visit_date'),
            'checkin_latitude' => $request->input('latitude'),
            'checkin_longitude' => $request->input('longitude'),
            'simulated' => $request->boolean('simulated'),
            'general_notes' => $request->input('general_notes'),
            'source' => $request->input('source') ?? ($request->bearerToken() ? 'mobile' : 'web'),
        ]);

        return response()->json([
            'message' => 'تم بدء الزيارة.',
            'visit' => $this->visitItem($visit),
        ], 201);
    }

    /** GET /api/observation-visits/{visit} */
    public function show(Request $request, ObservationVisit $visit): JsonResponse
    {
        $this->allow($request->user()->can('view', $visit));

        $observations = $visit->observations()->with(ObservationItem::WITH)->orderBy('id')->get();

        return response()->json([
            'visit' => $this->visitItem($visit),
            'observations' => ObservationItem::many($observations),
        ]);
    }

    /** POST /api/observation-visits/{visit}/photos (multipart: photo, kind?) */
    public function uploadPhoto(UploadObservationPhotoRequest $request, ObservationVisit $visit): JsonResponse
    {
        $this->allow($request->user()->can('update', $visit));

        $path = $request->file('photo')->store('evidence/observations/'.$visit->id, 'public');

        return response()->json([
            'path' => $path,
            'url' => asset('storage/'.$path),
            'kind' => $request->input('kind') ?: 'before',
        ]);
    }

    /**
     * POST /api/observation-visits/{visit}/ai-check. One ai_classifications row per point; a classifier
     * failure never fails the request (HTTP 200 with status failed / skipped).
     */
    public function aiCheck(AiCheckRequest $request, ObservationVisit $visit): JsonResponse
    {
        $this->allow($request->user()->can('update', $visit));

        $points = array_map(fn (array $p) => [(int) $p['index'], (string) $p['text']], array_values($request->input('points')));
        $batch = app(ClassifierContract::class)->classify((int) $visit->department_id, $visit->branch_id, $points);

        $created = $this->service->recordClassifications($visit, $points, $batch);

        // Row id per point index, to tag each result with its ai_classification_id.
        $rowIdOf = [];
        foreach ($created as $n => $row) {
            $rowIdOf[$points[$n][0]] = $row->id;
        }
        $results = [];
        foreach ($batch->results as $result) {
            if (isset($rowIdOf[$result->index])) {
                $results[] = $result->toArray() + ['ai_classification_id' => $rowIdOf[$result->index]];
            }
        }

        return response()->json([
            'status' => $batch->status,
            'message' => $batch->status === 'ok' ? null : self::AI_UNAVAILABLE,
            'results' => $results,
            'classification_ids' => array_map(fn (AiClassification $c) => $c->id, $created),
        ]);
    }

    /** POST /api/observation-visits/{visit}/observations: upsert the draft's observations (all or nothing). */
    public function upsert(UpsertObservationsRequest $request, ObservationVisit $visit): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('update', $visit));

        // validated() builds the array rule by rule, so an item may come out of order: restore the input order
        // (the response and the service's `items.{i}` error keys follow it).
        $items = $request->validated('items');
        ksort($items);
        $saved = $this->service->upsertObservations($visit, array_values($items), $u);

        $rows = Observation::with(ObservationItem::WITH)->whereIn('id', $saved->pluck('id'))->get()->keyBy('id');

        return response()->json([
            'data' => ObservationItem::many($saved->map(fn (Observation $o) => $rows[$o->id])),
        ]);
    }

    /**
     * POST /api/observation-visits/{visit}/submit. With observations.manager_approval (default) the visit
     * only becomes `submitted` (no tickets; `created_tickets` is []): a manager approves it later.
     */
    public function submit(SubmitObservationVisitRequest $request, ObservationVisit $visit): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('submit', $visit));

        $result = $this->service->submitVisit($visit, $u);

        return response()->json([
            'message' => config('observations.manager_approval', true) ? 'تم إرسال الزيارة وهي بانتظار اعتماد المدير.' : 'تم إرسال الزيارة.',
            'visit' => $this->visitItem($result['visit']),
            'created_tickets' => $this->createdTickets($result['created_tickets']),
        ]);
    }

    /** POST /api/observation-visits/{visit}/approve (Plan H): the manager creates the tickets. */
    public function approve(Request $request, ObservationVisit $visit): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('approve', $visit) || ($visit->status === 'approved' && $u->can('supervise', $visit)));

        $result = $this->service->approveVisit($visit, $u);

        return response()->json([
            'message' => 'تم اعتماد الزيارة.',
            'visit' => $this->visitItem($result['visit']),
            'created_tickets' => $this->createdTickets($result['created_tickets']),
        ]);
    }

    /** @param  list<array{observation_id:int, ticket_id:int, reference:string}>  $rows */
    private function createdTickets(array $rows): array
    {
        $tickets = Ticket::with('department:id,name')
            ->whereIn('id', array_column($rows, 'ticket_id'))
            ->get()->keyBy('id');

        return array_map(function (array $row) use ($tickets) {
            $ticket = $tickets[$row['ticket_id']] ?? null;

            return [
                'id' => $row['ticket_id'],
                'observation_id' => $row['observation_id'],
                'ticket_id' => $row['ticket_id'],
                'reference' => $row['reference'],
                'title' => $ticket?->title,
                'priority' => $ticket?->priority,
                'department' => $ticket?->department ? ['id' => $ticket->department->id, 'name' => $ticket->department->name] : null,
            ];
        }, $rows);
    }

    private function visitItem(ObservationVisit $visit): array
    {
        return ObservationVisitItem::make($visit->load(ObservationVisitItem::WITH)->loadCount(ObservationVisitItem::counts()));
    }

    private function allow(bool $allowed): void
    {
        abort_unless($allowed, 403, 'غير مصرح.');
    }

    private static function pageMeta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }
}
