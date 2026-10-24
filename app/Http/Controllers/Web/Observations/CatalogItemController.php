<?php

namespace App\Http\Controllers\Web\Observations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Observations\CatalogItemRequest;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use App\Support\ObservationScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/** /admin/observation-catalog: catalog items per category (super_admin: all; department manager: own department). */
class CatalogItemController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $user = $request->user();
        $locked = $user->hasRole('super_admin') ? null : ObservationScope::managedReportingDepartmentId($user);
        if ($locked === null && ! $user->hasRole('super_admin')) {
            abort(403);
        }

        $departmentId = $locked ?? ($request->integer('department_id') ?: null);
        $categoryId = $request->integer('category_id') ?: null;
        $q = trim((string) $request->query('q'));

        $departments = Department::where('is_reporting', true)->orderBy('name')->get();
        $allDepartments = Department::orderBy('name')->get(['id', 'name']);
        $riskLevels = RiskLevel::orderBy('sort_order')->get();
        $categories = ObservationCategory::with('department')
            ->when($departmentId, fn ($qq) => $qq->where('department_id', $departmentId))
            ->orderBy('department_id')->orderBy('sort_order')->get();

        $items = ObservationCatalogItem::with(['category.department', 'riskLevel', 'responsibleDepartment'])
            ->whereIn('category_id', $categories->pluck('id'))
            ->when($categoryId, fn ($qq) => $qq->where('category_id', $categoryId))
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('code', 'like', "%{$q}%")->orWhere('label_ar', 'like', "%{$q}%")->orWhere('label_en', 'like', "%{$q}%");
            }))
            ->orderBy('category_id')->orderBy('sort_order')->orderBy('id')
            ->get();
        $referenced = Observation::whereIn('catalog_item_id', $items->pluck('id'))
            ->selectRaw('catalog_item_id, count(*) as n')->groupBy('catalog_item_id')->pluck('n', 'catalog_item_id');
        $items->each(fn ($i) => $i->observations_count = (int) ($referenced[$i->id] ?? 0));

        return view('dashboard.observations.catalog.index', [
            'items' => $items,
            'grouped' => $items->groupBy('category_id'),
            'categories' => $categories,
            'departments' => $departments,
            'allDepartments' => $allDepartments,
            'riskLevels' => $riskLevels,
            'departmentId' => $departmentId,
            'categoryId' => $categoryId,
            'q' => $q,
            'locked' => $locked,
        ]);
    }

    public function store(CatalogItemRequest $request)
    {
        $category = ObservationCategory::with('department')->findOrFail($request->integer('category_id'));
        $this->authorize('manageCategory', [ObservationCategory::class, $category->department]);

        $attributes = $request->itemAttributes();
        $attributes['usage_count'] = 0;
        $attributes['sort_order'] = $request->filled('sort_order')
            ? (int) $request->input('sort_order')
            : (int) ObservationCatalogItem::where('category_id', $category->id)->max('sort_order') + 1;
        ObservationCatalogItem::create($attributes);

        return back()->with('status', dt('تم حفظ البند.', 'Catalog item saved.'));
    }

    public function update(CatalogItemRequest $request, ObservationCatalogItem $item)
    {
        $this->authorize('manageCategory', [ObservationCategory::class, $item->category->department]);
        $target = ObservationCategory::with('department')->findOrFail($request->integer('category_id'));
        if ($target->id !== $item->category_id) {
            $this->authorize('manageCategory', [ObservationCategory::class, $target->department]);
        }

        $attributes = $request->itemAttributes();
        if ($request->filled('sort_order')) {
            $attributes['sort_order'] = (int) $request->input('sort_order');
        }
        $item->update($attributes);

        return back()->with('status', dt('تم تحديث البند.', 'Catalog item updated.'));
    }

    public function toggle(ObservationCatalogItem $item)
    {
        $this->authorize('manageCategory', [ObservationCategory::class, $item->category->department]);
        $item->update(['active' => ! $item->active]);

        return back()->with('status', $item->active ? dt('تم تفعيل البند.', 'Item activated.') : dt('تم تعطيل البند.', 'Item deactivated.'));
    }

    public function destroy(ObservationCatalogItem $item)
    {
        $this->authorize('manageCategory', [ObservationCategory::class, $item->category->department]);

        if ((int) $item->usage_count > 0 || Observation::where('catalog_item_id', $item->id)->exists()) {
            return back()->with('status', dt('لا يمكن حذف بند مستخدم في ملاحظات. عطّله بدلاً من ذلك.', 'Cannot delete an item that observations use. Deactivate it instead.'));
        }

        $item->delete();

        return back()->with('status', dt('تم حذف البند.', 'Catalog item deleted.'));
    }
}
