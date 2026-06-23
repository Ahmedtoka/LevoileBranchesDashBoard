<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['slug' => 'super_admin', 'name' => 'Super Admin'],
            ['slug' => 'branch_director', 'name' => 'Branch Director'],
            ['slug' => 'area_manager', 'name' => 'Area Manager'],
            ['slug' => 'store_manager', 'name' => 'Store Manager'],
            ['slug' => 'vm_manager', 'name' => 'VM Manager'],
            ['slug' => 'ops_manager', 'name' => 'OPS Manager'],
            ['slug' => 'department_manager', 'name' => 'Department Manager'],
            ['slug' => 'department_employee', 'name' => 'Department Employee'],
            ['slug' => 'branch_manager', 'name' => 'Branch / Store Responsible'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
