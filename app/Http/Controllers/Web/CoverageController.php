<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;

class CoverageController extends Controller
{
    public function index()
    {
        $branches = Branch::where('active', true)->orderBy('city')->orderBy('branch_name')->get();

        $areaManagers = User::whereHas('role', fn ($q) => $q->where('slug', 'area_manager'))
            ->with('branches')->orderBy('name')->get();

        $storeManagers = User::whereHas('role', fn ($q) => $q->where('slug', 'store_manager'))
            ->with('branch')->orderBy('name')->get();

        $maintId = optional(Department::where('slug', 'maintenance')->first())->id;
        $technicians = User::where('department_id', $maintId)
            ->where('is_department_manager', false)
            ->with('branches')->orderBy('name')->get();

        return view('dashboard.coverage.index', compact('branches', 'areaManagers', 'storeManagers', 'technicians'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'mode' => ['required', 'in:single,multi'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['exists:branches,id'],
        ]);

        if ($data['mode'] === 'single') {
            $user->update(['branch_id' => $data['branch_id'] ?? null]);
        } else {
            $user->branches()->sync($data['branch_ids'] ?? []);
        }

        return back()->with('status', 'تم تحديث تغطية الفروع لـ '.$user->name.'.');
    }
}
