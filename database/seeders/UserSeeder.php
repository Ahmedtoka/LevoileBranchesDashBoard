<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::pluck('id', 'slug');
        $departments = Department::all();

        // Store Manager tied to a specific branch (the branch's responsible user).
        $branch = Branch::where('branch_name', 'Maadi - Maadi 1')->first()
            ?? Branch::first();
        if ($branch) {
            User::updateOrCreate(
                ['email' => 'nesma@levoile.test'],
                [
                    'name' => 'Nesma (Store Manager)',
                    'password' => Hash::make('password'),
                    'role_id' => $roles['store_manager'] ?? null,
                    'branch_id' => $branch->id,
                    'active' => true,
                ]
            );
            $branch->update(['manager_id' => User::where('email', 'nesma@levoile.test')->value('id')]);
        }

        // Core role users (all share password: "password")
        $core = [
            ['name' => 'Super Admin', 'email' => 'admin@levoile.test', 'role' => 'super_admin'],
            ['name' => 'Branch Director', 'email' => 'director@levoile.test', 'role' => 'branch_director'],
            ['name' => 'Area Manager', 'email' => 'area@levoile.test', 'role' => 'area_manager'],
            ['name' => 'Store Manager', 'email' => 'store@levoile.test', 'role' => 'store_manager'],
            ['name' => 'VM Manager', 'email' => 'vm@levoile.test', 'role' => 'vm_manager'],
            ['name' => 'OPS Manager', 'email' => 'ops@levoile.test', 'role' => 'ops_manager'],
        ];

        foreach ($core as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => Hash::make('password'),
                    'role_id' => $roles[$u['role']] ?? null,
                    'active' => true,
                ]
            );
        }

        // One manager + 2 employees per department
        foreach ($departments as $dept) {
            User::updateOrCreate(
                ['email' => $dept->slug.'.manager@levoile.test'],
                [
                    'name' => $dept->name.' Manager',
                    'password' => Hash::make('password'),
                    'role_id' => $roles['department_manager'] ?? null,
                    'department_id' => $dept->id,
                    'is_department_manager' => true,
                    'active' => true,
                ]
            );

            for ($i = 1; $i <= 2; $i++) {
                User::updateOrCreate(
                    ['email' => $dept->slug.'.emp'.$i.'@levoile.test'],
                    [
                        'name' => $dept->name.' Employee '.$i,
                        'password' => Hash::make('password'),
                        'role_id' => $roles['department_employee'] ?? null,
                        'department_id' => $dept->id,
                        'is_department_manager' => false,
                        'active' => true,
                    ]
                );
            }
        }

        // Demo coverage: technicians responsible for branches (auto-assign), area manager region.
        $byName = fn ($names) => Branch::whereIn('branch_name', $names)->pluck('id')->all();

        $emp1 = User::where('email', 'maintenance.emp1@levoile.test')->first();
        $emp2 = User::where('email', 'maintenance.emp2@levoile.test')->first();
        $area = User::where('email', 'area@levoile.test')->first();

        $emp1?->branches()->sync($byName([
            'Maadi - Maadi 1', 'Maadi - Maadi 2', 'Alexandria - San Stefano Mall', 'Alexandria - Smouha',
        ]));
        $emp2?->branches()->sync($byName([
            '6th of October - Mall of Egypt Store', '6th of October - Mall of Arabia',
            'Nasr City - Abbas El Akkad', 'Mansoura - Mansoura Store',
        ]));
        $area?->branches()->sync($byName([
            'Maadi - Maadi 1', 'Maadi - Maadi 2', 'Nasr City - Abbas El Akkad', 'Nasr City - Gnena Mall',
        ]));
    }
}
