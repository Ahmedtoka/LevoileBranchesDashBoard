<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Observations\AiCheckRequest;
use App\Http\Requests\Observations\StoreObservationVisitRequest;
use App\Http\Requests\Observations\UploadObservationPhotoRequest;
use App\Http\Requests\Observations\UpsertObservationsRequest;
use App\Http\Resources\Observations\ObservationItem;
use App\Http\Resources\Observations\ObservationVisitItem;
use App\Models\AiClassification;
use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Services\Observations\Ai\ClassificationResult;
use App\Services\Observations\Ai\ClassifierContract;
use App\Services\Observations\ObservationService;
use App\Support\ObservationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Observation visits (spec section 11). Thin: validate (FormRequest) -> authorize (policy) -> service -> serialize.
 * Called by the Flutter app (bearer token) and the web page /observations/new (stateful Sanctum session).
 */
class ObservationVisitController extends Controller
{
    /** Spec section 8: shown when the classifier is unavailable (failed / skipped). */
    private const AI_UNAVAILABLE = 'الفحص غير متاح، اختر الفئة يدوياً';

    public function __construct(private ObservationService $service) {}

    /** GET /api/observation-visits?status=&mine=1&branch_id=&department_id= */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('create', ObservationVisit::class));

        // Own visits OR the scope. A subquery, because for global roles the scope adds no condition and an
        // empty nested orWhere would be dropped, leaving only "own visits".
        $visible = ObservationScope::apply(ObservationVisit::query()->select('observation_visits.id'), $u, 'branch_id', 'department_id');
        $query = ObservationVisit::query()
            ->where(fn ($q) => $q->where('user_id', $u->id)->orWhereIn('id', $visible))
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

        $byIndex = [];
        foreach ($batch->results as $result) {
            /** @var ClassificationResult $result */
            $byIndex[$result->index] = $result;
        }

        $rows = DB::transaction(function () use ($points, $byIndex, $batch, $visit) {
            $rows = [];
            foreach ($points as $n => [$index, $text]) {
                $rows[$index] = AiClassification::create([
                    'observation_visit_id' => $visit->id,
                    'request_hash' => sha1($visit->department_id.'|'.$text),
                    'input_text' => $text,
                    'suggested' => isset($byIndex[$index]) ? $byIndex[$index]->toArray() : null,
                    'model' => $batch->model,
                    'status' => $batch->status,
                    'error' => $batch->error,
                    'latency_ms' => $batch->latencyMs,
                    // Batch totals on the first row only, so sums over the table stay correct.
                    'input_tokens' => $n === 0 ? $batch->inputTokens : null,
                    'output_tokens' => $n === 0 ? $batch->outputTokens : null,
                ]);
            }

            return $rows;
        });

        $results = [];
        foreach ($batch->results as $result) {
            if (isset($rows[$result->index])) {
                $results[] = $result->toArray() + ['ai_classification_id' => $rows[$result->index]->id];
            }
        }

        return response()->json([
            'status' => $batch->status,
            'message' => $batch->status === 'ok' ? null : self::AI_UNAVAILABLE,
            'results' => $results,
            'classification_ids' => array_values(array_map(fn (AiClassification $c) => $c->id, $rows)),
        ]);
    }

    /** POST /api/observation-visits/{visit}/observations: upsert the draft's observations (all or nothing). */
    public function upsert(UpsertObservationsRequest $request, ObservationVisit $visit): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('update', $visit));

        $items = array_values($request->validated('items'));
        $saved = $this->service->upsertObservations($visit, $items, $u);

        // Record what the employee kept of each AI suggestion, read from the saved row.
        foreach ($items as $i => $item) {
            if (empty($item['ai_classification_id']) || ! isset($saved[$i])) {
                continue;
            }
            $o = $saved[$i];
            AiClassification::whereKey($item['ai_classification_id'])->update(['employee_final' => json_encode([
                'catalog_item_id' => $o->catalog_item_id,
                'category_id' => $o->category_id,
                'kind' => $o->kind,
                'risk_level' => $o->riskLevel?->slug,
                'responsible_department_id' => $o->responsible_department_id,
            ], JSON_UNESCAPED_UNICODE)]);
        }

        $rows = Observation::with(ObservationItem::WITH)->whereIn('id', $saved->pluck('id'))->get()->keyBy('id');

        return response()->json([
            'data' => ObservationItem::many($saved->map(fn (Observation $o) => $rows[$o->id])),
        ]);
    }

    /** POST /api/observation-visits/{visit}/submit */
    public function submit(Request $request, ObservationVisit $visit): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('submit', $visit));

        $result = $this->service->submitVisit($visit, $u);

        $tickets = Ticket::with('department:id,name')
            ->whereIn('id', array_column($result['created_tickets'], 'ticket_id'))
            ->get()->keyBy('id');

        return response()->json([
            'message' => 'تم إرسال الزيارة.',
            'visit' => $this->visitItem($result['visit']),
            'created_tickets' => array_map(function (array $row) use ($tickets) {
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
            }, $result['created_tickets']),
        ]);
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
