<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        // The 15 real departments (each gets a 3-letter ticket prefix).
        $departments = [
            ['slug' => 'maintenance', 'name' => 'Maintenance', 'ticket_prefix' => 'MTN', 'color' => '#ef4444'],
            ['slug' => 'operation', 'name' => 'Operation', 'ticket_prefix' => 'OPR', 'color' => '#10b981'],
            ['slug' => 'vm', 'name' => 'VM', 'ticket_prefix' => 'VMR', 'color' => '#d946ef'],
            ['slug' => 'hr', 'name' => 'HR', 'ticket_prefix' => 'HRD', 'color' => '#14b8a6'],
            ['slug' => 'warehouse', 'name' => 'Warehouse', 'ticket_prefix' => 'WRH', 'color' => '#a16207'],
            ['slug' => 'lp', 'name' => 'Loss Prevention', 'ticket_prefix' => 'LSP', 'color' => '#dc2626'],
            ['slug' => 'finance', 'name' => 'Finance', 'ticket_prefix' => 'FIN', 'color' => '#059669'],
            ['slug' => 'cleaning', 'name' => 'Cleaning', 'ticket_prefix' => 'CLN', 'color' => '#22c55e'],
            ['slug' => 'marketing', 'name' => 'Marketing', 'ticket_prefix' => 'MKT', 'color' => '#f97316'],
            ['slug' => 'designer', 'name' => 'Designer', 'ticket_prefix' => 'DSG', 'color' => '#7c3aed'],
            ['slug' => 'merchandise', 'name' => 'Merchandise', 'ticket_prefix' => 'MRC', 'color' => '#ec4899'],
            ['slug' => 'stock_control', 'name' => 'Stock Control', 'ticket_prefix' => 'STK', 'color' => '#f59e0b'],
            ['slug' => 'purchasing', 'name' => 'Purchasing', 'ticket_prefix' => 'PUR', 'color' => '#8b5cf6'],
            ['slug' => 'it', 'name' => 'IT', 'ticket_prefix' => 'ITD', 'color' => '#0ea5e9'],
            ['slug' => 'cctv', 'name' => 'CCTV', 'ticket_prefix' => 'CCT', 'color' => '#64748b'],
        ];

        foreach ($departments as $dept) {
            Department::updateOrCreate(['slug' => $dept['slug']], $dept);
        }

        // Drop the legacy "store" department (it's a visitor role, not a ticket target).
        Department::where('slug', 'store')->delete();
    }
}
