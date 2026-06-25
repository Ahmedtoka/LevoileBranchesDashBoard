<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['slug' => 'store', 'name' => 'Store', 'color' => '#6366f1'],
            ['slug' => 'merchandise', 'name' => 'Merchandise', 'color' => '#ec4899'],
            ['slug' => 'stock_control', 'name' => 'Stock Control', 'color' => '#f59e0b'],
            ['slug' => 'maintenance', 'name' => 'Maintenance', 'color' => '#ef4444'],
            ['slug' => 'operation', 'name' => 'Operation', 'color' => '#10b981'],
            ['slug' => 'purchasing', 'name' => 'Purchasing', 'color' => '#8b5cf6'],
            ['slug' => 'it', 'name' => 'IT', 'color' => '#0ea5e9'],
            ['slug' => 'cctv', 'name' => 'CCTV', 'color' => '#64748b'],
            // referenced by the real store/area checklists
            ['slug' => 'vm', 'name' => 'VM', 'color' => '#d946ef'],
            ['slug' => 'hr', 'name' => 'HR', 'color' => '#14b8a6'],
            ['slug' => 'warehouse', 'name' => 'Warehouse', 'color' => '#a16207'],
            ['slug' => 'lp', 'name' => 'Loss Prevention', 'color' => '#dc2626'],
            ['slug' => 'finance', 'name' => 'Finance', 'color' => '#059669'],
            ['slug' => 'cleaning', 'name' => 'Cleaning', 'color' => '#22c55e'],
            ['slug' => 'marketing', 'name' => 'Marketing', 'color' => '#f97316'],
            ['slug' => 'designer', 'name' => 'Designer', 'color' => '#7c3aed'],
        ];

        foreach ($departments as $dept) {
            Department::updateOrCreate(['slug' => $dept['slug']], $dept);
        }
    }
}
