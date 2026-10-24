<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Observations\UpdateObservationRequest;
use App\Http\Requests\Observations\VerifyObservationRequest;
use App\Http\Resources\Observations\ObservationItem;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationVerification;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Services\Observations\ObservationService;
use App\Support\ObservationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Observations (spec section 11). Thin: validate (FormRequest) -> authorize (policy) -> service -> serialize.
 * Called by the Flutter app (bearer token) and the web pages (stateful Sanctum session).
 */
class ObservationController extends Controller
{
    public function __construct(private ObservationService $service) {}

    /** GET /api/observations/meta?department_id= : everything the recording screens need. */
    public function meta(Request $request): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('create', ObservationVisit::class));

        $departments = $this->service->reportingDepartmentsFor($u);
        $defaultId = $u->defaultReportingDepartmentId();
        $asked = (int) $request->query('department_id');
        $selectedId = $departments->contains('id', $asked) ? $asked : $defaultId;

        $categories = ObservationCategory::where('department_id', $selectedId ?? 0)
            ->where('active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $items = ObservationCatalogItem::query()
            ->join('observation_categories', 'observation_categories.id', '=', 'observation_catalog_items.category_id')
            ->where('observation_categories.department_id', $selectedId ?? 0)
            ->where('observation_categories.active', true)
            ->where('observation_catalog_items.active', true)
            ->orderBy('observation_categories.sort_order')
            ->orderBy('observation_categories.id')
            ->orderBy('observation_catalog_items.sort_order')
            ->orderBy('observation_catalog_items.id')
            ->select('observation_catalog_items.*')
            ->get();

        return response()->json([
            'reporting_departments' => $departments->map(fn (Department $d) => ['id' => $d->id, 'slug' => $d->slug, 'name' => $d->name])->values(),
            'default_department_id' => $defaultId,
            'department_id' => $selectedId,
            'risk_levels' => RiskLevel::where('active', true)->orderBy('sort_order')->get()->map(fn (RiskLevel $r) => [
                'id' => $r->id, 'slug' => $r->slug, 'name_ar' => $r->name_ar, 'name_en' => $r->name_en,
                'color' => $r->color, 'requires_field_verification' => (bool) $r->requires_field_verification,
            ])->values(),
            'categories' => $categories->map(fn (ObservationCategory $c) => [
                'id' => $c->id, 'slug' => $c->slug, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en,
                'default_kind' => $c->default_kind,
                'risk_level_id' => $c->default_risk_level_id,
                'responsible_department_id' => $c->default_responsible_department_id,
                'default_risk_level_id' => $c->default_risk_level_id,
                'default_responsible_department_id' => $c->default_responsible_department_id,
            ])->values(),
            'catalog_items' => $items->map(fn (ObservationCatalogItem $i) => [
                'id' => $i->id, 'category_id' => $i->category_id, 'code' => $i->code,
                'label_ar' => $i->label_ar, 'label_en' => $i->label_en, 'keywords' => $i->keywords ?? [],
                'kind' => $i->kind, 'risk_level_id' => $i->risk_level_id,
                'responsible_department_id' => $i->responsible_department_id, 'extra_fields' => $i->extra_fields ?? [],
            ])->values(),
            'responsible_departments' => Department::orderBy('name')->get(['id', 'slug', 'name'])
                ->map(fn (Department $d) => ['id' => $d->id, 'slug' => $d->slug, 'name' => $d->name])->values(),
            'areas' => Area::where('active', true)->with('branches:id,area_id')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Area $a) => ['id' => $a->id, 'name' => $a->name, 'branch_ids' => $a->branches->pluck('id')->values()])->values(),
            'branches' => Branch::where('active', true)->orderBy('branch_name')->get(['id', 'branch_name', 'area_id', 'latitude', 'longitude'])
                ->map(fn (Branch $b) => [
                    'id' => $b->id, 'name' => $b->branch_name, 'area_id' => $b->area_id,
                    'latitude' => $b->latitude, 'longitude' => $b->longitude,
                ])->values(),
        ]);
    }

    /** GET /api/observations?branch_id=&status=&risk=&department_id=&responsible_department_id=&from=&to=&kind=&classification_status=&catalog_item_id=&q=&page= */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('viewAny', Observation::class));

        $query = ObservationScope::apply(Observation::query(), $u)
            ->when($request->filled('branch_id'), fn ($q) => $q->where('observations.branch_id', (int) $request->query('branch_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('observations.status', (string) $request->query('status')))
            ->when($request->filled('risk'), fn ($q) => $q->whereHas('riskLevel', fn ($r) => $r->where('slug', (string) $request->query('risk'))))
            ->when($request->filled('department_id'), fn ($q) => $q->where('observations.reporting_department_id', (int) $request->query('department_id')))
            ->when($request->filled('responsible_department_id'), fn ($q) => $q->where('observations.responsible_department_id', (int) $request->query('responsible_department_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('observations.event_date', '>=', (string) $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('observations.event_date', '<=', (string) $request->query('to')))
            ->when($request->filled('kind'), fn ($q) => $q->where('observations.kind', (string) $request->query('kind')))
            ->when($request->filled('classification_status'), fn ($q) => $q->where('observations.classification_status', (string) $request->query('classification_status')))
            ->when($request->filled('catalog_item_id'), fn ($q) => $q->where('observations.catalog_item_id', (int) $request->query('catalog_item_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.addcslashes((string) $request->query('q'), '%_\\').'%';
                $q->where(fn ($w) => $w->where('observations.reference', 'like', $term)
                    ->orWhere('observations.title', 'like', $term)
                    ->orWhere('observations.description', 'like', $term));
            })
            ->with(ObservationItem::WITH)
            ->orderByDesc('observations.id');

        $page = $query->paginate(30);

        return response()->json([
            'data' => ObservationItem::many($page->items()),
            'meta' => self::pageMeta($page),
        ]);
    }

    /** GET /api/observations/{observation} */
    public function show(Request $request, Observation $observation): JsonResponse
    {
        $this->allow($request->user()->can('view', $observation));

        $observation->load(array_merge(ObservationItem::WITH, ['verifications.verifier:id,name', 'classification']));
        $item = ObservationItem::make($observation);
        $classification = $observation->classification;

        return response()->json([
            'observation' => $item,
            'photos' => $item['photos'],
            'ticket' => $item['ticket'],
            'verifications' => $observation->verifications->sortBy('id')->values()
                ->map(fn (ObservationVerification $v) => self::verification($v))->all(),
            'classification' => $classification ? [
                'id' => $classification->id,
                'status' => $classification->status,
                'suggested' => $classification->suggested,
                'employee_final' => $classification->employee_final,
                'admin_final' => $classification->admin_final,
            ] : null,
        ]);
    }

    /** PUT /api/observations/{observation} (Plan H): the reporting department's manager corrects a point before approval. */
    public function update(UpdateObservationRequest $request, Observation $observation): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('updateClassification', $observation));

        $updated = $this->service->updateClassification($observation, $request->validated(), $u);

        return response()->json([
            'message' => 'تم تحديث الملاحظة.',
            'observation' => ObservationItem::make($updated->load(ObservationItem::WITH)),
        ]);
    }

    /** DELETE /api/observations/{observation}: only an observation of the caller's own draft visit. */
    public function destroyDraft(Request $request, Observation $observation): JsonResponse
    {
        $visit = $observation->visit;
        $this->allow($visit !== null && $request->user()->can('update', $visit));

        $this->service->deleteDraftObservation($observation);

        return response()->json(['message' => 'تم حذف الملاحظة.']);
    }

    /** POST /api/observations/{observation}/verify */
    public function verify(VerifyObservationRequest $request, Observation $observation): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('verify', $observation));

        $verification = $this->service->verify(
            $observation,
            $u,
            (string) $request->input('result'),
            $request->input('notes'),
            $request->input('photo_path'),
            $request->filled('observation_visit_id') ? (int) $request->input('observation_visit_id') : null,
        );

        return response()->json([
            'message' => 'تم تسجيل التحقق.',
            'verification' => self::verification($verification->load('verifier:id,name')),
            'observation' => ObservationItem::make($observation->fresh(ObservationItem::WITH)),
        ]);
    }

    /** GET /api/branches/{branch}/observations/pending-verification */
    public function pendingVerification(Request $request, Branch $branch): JsonResponse
    {
        $u = $request->user();
        $this->allow($u->can('viewAny', Observation::class));

        $rows = $this->service->pendingVerification($branch, $u)->load(ObservationItem::WITH);

        return response()->json(['data' => ObservationItem::many($rows)]);
    }

    private static function verification(ObservationVerification $v): array
    {
        return [
            'id' => $v->id,
            'result' => $v->result,
            'notes' => $v->notes,
            'verified_by' => $v->verifier ? ['id' => $v->verifier->id, 'name' => $v->verifier->name] : null,
            'verified_at' => $v->verified_at?->toIso8601String(),
        ];
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
