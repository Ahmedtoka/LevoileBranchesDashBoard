<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with(['role', 'department', 'branch', 'branches'])->orderBy('name')->get();
        $roles = Role::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();
        $branches = Branch::orderBy('branch_name')->get();

        return view('dashboard.users.index', compact('users', 'roles', 'departments', 'branches'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6'],
            'role_id' => ['nullable', 'exists:roles,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['exists:branches,id'],
            'is_department_manager' => ['nullable', 'boolean'],
        ]);

        $branchIds = $data['branch_ids'] ?? [];
        unset($data['branch_ids']);

        $data['password'] = Hash::make($data['password']);
        $data['is_department_manager'] = $request->boolean('is_department_manager');
        $data['active'] = true;

        $user = User::create($data);
        $user->branches()->sync($branchIds);

        return back()->with('status', 'User created.');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:6'],
            'role_id' => ['nullable', 'exists:roles,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['exists:branches,id'],
            'is_department_manager' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        $branchIds = $data['branch_ids'] ?? [];
        unset($data['branch_ids']);

        $data['is_department_manager'] = $request->boolean('is_department_manager');
        $data['active'] = $request->boolean('active');

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);
        $user->branches()->sync($branchIds);

        return back()->with('status', 'User updated.');
    }

    public function destroy(User $user)
    {
        $user->delete();

        return back()->with('status', 'User deleted.');
    }
}
