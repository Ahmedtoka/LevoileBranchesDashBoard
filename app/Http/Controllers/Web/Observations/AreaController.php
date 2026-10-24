<?php

namespace App\Http\Controllers\Web\Observations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Observations\AreaRequest;
use App\Models\Area;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * /admin/areas: areas CRUD, branch assignment and area-manager sync (spec section 10, decision 13).
 *
 * Deactivating an area (active = false) only hides it from pickers (API meta lists active areas);
 * its branches keep their area_id and the area manager's scope is unchanged.
 */
class AreaController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $this->authorize('manageAreas', Area::class);

        $areas = Area::with('manager')
            ->withCount('branches')
            ->orderBy('sort_order')->orderBy('name')
            ->get();
        $branches = Branch::orderBy('branch_name')->get(['id', 'branch_name', 'area_id', 'active']);
        $managers = User::where('active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['area_manager', 'ops_manager', 'super_admin', 'branch_director']))
            ->orderBy('name')->get(['id', 'name']);
        $unassigned = $branches->whereNull('area_id')->count();

        return view('dashboard.observations.areas.index', compact('areas', 'branches', 'managers', 'unassigned'));
    }

    public function store(AreaRequest $request)
    {
        $this->authorize('manageAreas', Area::class);
        $data = $request->validated();

        DB::transaction(function () use ($data, $request) {
            $area = Area::create([
                'name' => $data['name'],
                'name_ar' => $data['name_ar'] ?? null,
                'slug' => $this->uniqueSlug($data['name']),
                'manager_id' => $data['manager_id'] ?? null,
                'sort_order' => $data['sort_order'] ?? (int) Area::max('sort_order') + 1,
                'active' => $request->boolean('active', true),
            ]);
            if ($request->has('branch_ids')) {
                $this->setBranches($area, (array) ($data['branch_ids'] ?? []));
            }
            $this->syncManagers([$area->manager_id]);
        });

        return back()->with('status', dt('تم حفظ المنطقة.', 'Area saved.'));
    }

    public function update(AreaRequest $request, Area $area)
    {
        $this->authorize('manageAreas', Area::class);
        $data = $request->validated();
        $previousManager = $area->manager_id;

        DB::transaction(function () use ($area, $data, $request, $previousManager) {
            $area->update([
                'name' => $data['name'],
                'name_ar' => $data['name_ar'] ?? null,
                'manager_id' => $data['manager_id'] ?? null,
                'sort_order' => $data['sort_order'] ?? $area->sort_order,
                'active' => $request->boolean('active'),
            ]);
            if ($request->has('branch_ids')) {
                $this->setBranches($area, (array) ($data['branch_ids'] ?? []));
            }
            $this->syncManagers([$previousManager, $area->manager_id]);
        });

        return back()->with('status', dt('تم تحديث المنطقة.', 'Area updated.'));
    }

    /** branch_ids[] = the full list of this area's branches; omitted ones that were here are released (area_id = null). */
    public function assignBranches(Request $request, Area $area)
    {
        $this->authorize('manageAreas', Area::class);
        $data = $request->validate([
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        DB::transaction(function () use ($area, $data) {
            $this->setBranches($area, (array) ($data['branch_ids'] ?? []));
            $this->syncManagers([$area->manager_id]);
        });

        return back()->with('status', dt('تم تحديث فروع المنطقة.', 'Area branches updated.'));
    }

    public function destroy(Area $area)
    {
        $this->authorize('manageAreas', Area::class);

        if ($area->branches()->exists()) {
            return back()->with('status', dt('لا يمكن حذف منطقة بها فروع. انقل الفروع أولاً أو أوقف المنطقة.', 'Cannot delete an area that has branches. Move the branches first or deactivate the area.'));
        }

        $manager = $area->manager_id;
        DB::transaction(function () use ($area, $manager) {
            $area->delete();
            $this->syncManagers([$manager]);
        });

        return back()->with('status', dt('تم حذف المنطقة.', 'Area deleted.'));
    }

    private function setBranches(Area $area, array $branchIds): void
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));

        // Branches that were in this area but are no longer listed go back to "no area".
        Branch::where('area_id', $area->id)->whereNotIn('id', $branchIds ?: [0])->update(['area_id' => null]);
        if ($branchIds !== []) {
            // Previous managers of areas these branches are leaving must be re-synced too.
            $leaving = Area::whereIn('id', Branch::whereIn('id', $branchIds)->whereNotNull('area_id')->where('area_id', '!=', $area->id)->select('area_id'))
                ->pluck('manager_id')->all();
            Branch::whereIn('id', $branchIds)->update(['area_id' => $area->id]);
            $this->syncManagers($leaving);
        }
    }

    /**
     * Spec decision 13: areas.manager_id is the source of truth; branch_user for each listed manager
     * becomes exactly the branches of the areas they manage (so the legacy screens keep working).
     */
    private function syncManagers(array $userIds): void
    {
        foreach (array_unique(array_filter($userIds)) as $userId) {
            $branchIds = Branch::whereIn('area_id', Area::where('manager_id', $userId)->select('id'))->pluck('id')->all();
            $current = DB::table('branch_user')->where('user_id', $userId)->pluck('branch_id')->all();

            $remove = array_diff($current, $branchIds);
            if ($remove !== []) {
                DB::table('branch_user')->where('user_id', $userId)->whereIn('branch_id', $remove)->delete();
            }
            $now = now();
            $rows = array_map(fn ($id) => ['user_id' => $userId, 'branch_id' => $id, 'created_at' => $now, 'updated_at' => $now], array_values(array_diff($branchIds, $current)));
            if ($rows !== []) {
                DB::table('branch_user')->insert($rows);
            }
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'area';
        $slug = $base;
        for ($i = 2; Area::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
