<?php

namespace App\Http\Controllers\Web\Observations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Observations\CategoryRequest;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use App\Support\ObservationScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** /admin/observation-categories: per-department categories (super_admin: all; department manager: own). */
class CategoryController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $user = $request->user();
        $locked = $this->lockedDepartmentId($user);
        if ($locked === null && ! $user->hasRole('super_admin')) {
            abort(403);
        }

        $departmentId = $locked ?? ($request->integer('department_id') ?: null);
        $departments = Department::where('is_reporting', true)->orderBy('name')->get();
        $allDepartments = Department::orderBy('name')->get(['id', 'name']);
        $riskLevels = RiskLevel::orderBy('sort_order')->get(['id', 'slug', 'name_ar', 'name_en', 'color']);

        $categories = ObservationCategory::with(['department', 'riskLevel', 'responsibleDepartment'])
            ->withCount('items')
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->orderBy('department_id')->orderBy('sort_order')->orderBy('id')
            ->get();
        $usage = Observation::whereIn('category_id', $categories->pluck('id'))
            ->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');
        $categories->each(fn ($c) => $c->observations_count = (int) ($usage[$c->id] ?? 0));

        return view('dashboard.observations.categories.index', [
            'categories' => $categories,
            'grouped' => $categories->groupBy('department_id'),
            'departments' => $departments,
            'allDepartments' => $allDepartments,
            'riskLevels' => $riskLevels,
            'departmentId' => $departmentId,
            'locked' => $locked,
        ]);
    }

    public function store(CategoryRequest $request)
    {
        $department = Department::findOrFail($request->integer('department_id'));
        $this->authorize('manageCategory', [ObservationCategory::class, $department]);
        $data = $request->validated();

        ObservationCategory::create(array_merge($this->attributes($data, $request), [
            'department_id' => $department->id,
            'slug' => CategoryRequest::slugFor($data['name_en']),
            'sort_order' => $data['sort_order'] ?? ((int) ObservationCategory::where('department_id', $department->id)->max('sort_order') + 1),
        ]));

        return back()->with('status', dt('تم حفظ الفئة.', 'Category saved.'));
    }

    public function update(CategoryRequest $request, ObservationCategory $category)
    {
        $this->authorize('manageCategory', [ObservationCategory::class, $category->department]);
        $data = $request->validated();
        // The department of an existing category never changes (its items and observations point at it).
        $attributes = $this->attributes($data, $request);
        if (array_key_exists('sort_order', $data) && $data['sort_order'] !== null) {
            $attributes['sort_order'] = (int) $data['sort_order'];
        }
        $category->update($attributes);

        return back()->with('status', dt('تم تحديث الفئة.', 'Category updated.'));
    }

    public function destroy(ObservationCategory $category)
    {
        $this->authorize('manageCategory', [ObservationCategory::class, $category->department]);

        if ($category->items()->exists() || Observation::where('category_id', $category->id)->exists()) {
            return back()->with('status', dt('لا يمكن حذف فئة عليها بنود أو ملاحظات. أوقفها بدلاً من ذلك.', 'Cannot delete a category that has items or observations. Deactivate it instead.'));
        }

        $category->delete();

        return back()->with('status', dt('تم حذف الفئة.', 'Category deleted.'));
    }

    /** ids[] in display order -> sort_order 1..n. Every listed category must be manageable by the caller. */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:observation_categories,id'],
        ]);

        $categories = ObservationCategory::with('department')->whereIn('id', $data['ids'])->get()->keyBy('id');
        foreach ($categories as $category) {
            $this->authorize('manageCategory', [ObservationCategory::class, $category->department]);
        }

        DB::transaction(function () use ($data, $categories) {
            foreach (array_values($data['ids']) as $i => $id) {
                $categories[$id]?->update(['sort_order' => $i + 1]);
            }
        });

        return back()->with('status', dt('تم حفظ الترتيب.', 'Order saved.'));
    }

    /** Department manager of a reporting department: locked to it. Super admin: null (free). Anyone else: null (then 403 by the caller). */
    private function lockedDepartmentId($user): ?int
    {
        if ($user->hasRole('super_admin')) {
            return null;
        }

        return ObservationScope::managedReportingDepartmentId($user);
    }

    private function attributes(array $data, Request $request): array
    {
        $cc = array_values(array_unique(array_map('intval', (array) ($data['default_cc_department_ids'] ?? []))));

        return [
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'default_kind' => $data['default_kind'],
            'default_risk_level_id' => $data['default_risk_level_id'] ?? null,
            'default_responsible_department_id' => $data['default_responsible_department_id'] ?? null,
            'default_cc_department_ids' => $cc === [] ? null : $cc,
            'default_sla_hours' => filled($data['default_sla_hours'] ?? null) ? (int) $data['default_sla_hours'] : null,
            // Create: an omitted checkbox means active. Update: the form always posts the checkbox state.
            'active' => $request->has('active') ? $request->boolean('active') : $request->isMethod('post'),
        ];
    }
}
