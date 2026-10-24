<?php

namespace App\Http\Controllers\Web\Observations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Observations\ReviewMergeRequest;
use App\Http\Requests\Observations\ReviewPromoteRequest;
use App\Http\Requests\Observations\ReviewUpdateRequest;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use App\Services\Observations\ClassificationReviewService;
use App\Support\ObservationScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** /observations/review: the classification review queue (spec section 9.6). */
class ReviewController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private ClassificationReviewService $review) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $locked = $user->hasRole('super_admin') ? null : ObservationScope::managedReportingDepartmentId($user);
        if ($locked === null && ! $user->hasRole('super_admin')) {
            abort(403);
        }
        $departmentId = $locked ?? ($request->integer('department_id') ?: null);

        $observations = Observation::with(['branch', 'category', 'catalogItem', 'riskLevel', 'responsibleDepartment', 'reportingDepartment', 'classification', 'ticket', 'creator'])
            ->where('classification_status', 'ai_pending_review')
            ->when($departmentId, fn ($q) => $q->where('reporting_department_id', $departmentId))
            ->orderBy('reporting_department_id')->orderByDesc('id')
            ->get();

        $departmentIds = $observations->pluck('reporting_department_id')->unique()->values();
        $categories = ObservationCategory::whereIn('department_id', $departmentIds)->where('active', true)
            ->orderBy('department_id')->orderBy('sort_order')->get()->groupBy('department_id');
        $items = ObservationCatalogItem::with('category')
            ->whereIn('category_id', $categories->flatten()->pluck('id'))->where('active', true)
            ->orderBy('category_id')->orderBy('sort_order')->get();
        $itemsByDepartment = $items->groupBy(fn ($i) => $i->category->department_id);
        $itemsById = $items->keyBy('id');

        // "Similar" groups: pending free-text rows the agent mapped to the same catalog item (bulk merge).
        $similar = $observations
            ->filter(fn ($o) => ($o->classification?->suggested['catalog_item_id'] ?? null) && $itemsById->has($o->classification->suggested['catalog_item_id']))
            ->groupBy(fn ($o) => $o->classification->suggested['catalog_item_id'])
            ->filter(fn ($group) => $group->count() >= 2);

        return view('dashboard.observations.review.index', [
            'observations' => $observations,
            'departments' => Department::where('is_reporting', true)->orderBy('name')->get(),
            'allDepartments' => Department::orderBy('name')->get(['id', 'name']),
            'riskLevels' => RiskLevel::where('active', true)->orderBy('sort_order')->get(),
            'categories' => $categories,
            'itemsByDepartment' => $itemsByDepartment,
            'itemsById' => $itemsById,
            'similar' => $similar,
            'departmentId' => $departmentId,
            'locked' => $locked,
        ]);
    }

    public function approve(Request $request, Observation $observation)
    {
        $this->authorize('review', [ObservationCategory::class, $observation]);
        if ($observation->classification_status !== 'ai_pending_review') {
            return back()->with('status', dt('هذه الملاحظة تمت مراجعتها بالفعل.', 'This observation was already reviewed.'));
        }
        $this->review->approve($observation, $request->user());

        return back()->with('status', dt('تم اعتماد التصنيف.', 'Classification approved.'));
    }

    public function update(ReviewUpdateRequest $request, Observation $observation)
    {
        $this->authorize('review', [ObservationCategory::class, $observation]);
        $this->review->update($observation, $request->user(), $request->validated());

        return back()->with('status', dt('تم تعديل التصنيف واعتماده.', 'Classification corrected and approved.'));
    }

    public function merge(ReviewMergeRequest $request, Observation $observation)
    {
        $this->authorize('review', [ObservationCategory::class, $observation]);
        $item = ObservationCatalogItem::findOrFail($request->integer('catalog_item_id'));
        $this->review->merge($observation, $item, $request->user(), $request->only('risk_level_id', 'responsible_department_id', 'note'));

        return back()->with('status', dt('تم دمج الملاحظة في البند.', 'Observation merged into the catalog item.').' '.$item->code);
    }

    public function promote(ReviewPromoteRequest $request, Observation $observation)
    {
        $this->authorize('review', [ObservationCategory::class, $observation]);
        $category = ObservationCategory::with('department')->findOrFail($request->integer('category_id'));
        if ((int) $category->department_id !== (int) $observation->reporting_department_id) {
            throw ValidationException::withMessages(['category_id' => dt('الفئة من إدارة أخرى.', 'The category belongs to another department.')]);
        }
        $this->authorize('manageCategory', [ObservationCategory::class, $category->department]);

        $item = $this->review->promote($observation, $request->user(), $request->itemAttributes());

        return back()->with('status', dt('تم إنشاء البند وربط الملاحظة به.', 'Catalog item created and linked.').' '.$item->code);
    }

    public function bulkMerge(ReviewMergeRequest $request)
    {
        $item = ObservationCatalogItem::findOrFail($request->integer('catalog_item_id'));
        $observations = Observation::whereIn('id', $request->input('observation_ids', []))->get();
        foreach ($observations as $observation) {
            $this->authorize('review', [ObservationCategory::class, $observation]);
        }

        $done = 0;
        foreach ($observations as $observation) {
            if ($observation->classification_status !== 'ai_pending_review') {
                continue;
            }
            $this->review->merge($observation, $item, $request->user(), $request->only('note'));
            $done++;
        }

        return back()->with('status', dt('تم دمج', 'Merged').' '.$done.' '.dt('ملاحظة في البند', 'observation(s) into').' '.$item->code);
    }
}
