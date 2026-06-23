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
        ];

        foreach ($departments as $dept) {
            Department::updateOrCreate(['slug' => $dept['slug']], $dept);
        }
    }
}
