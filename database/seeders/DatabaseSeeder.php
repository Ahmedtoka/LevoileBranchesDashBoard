<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            DepartmentSeeder::class,
            BranchSeeder::class,
            UserSeeder::class,
            TemplateTypeSeeder::class,
            MaintenanceItemSeeder::class,
            TemplateSeeder::class,
            RealChecklistSeeder::class,
            DemoVisitSeeder::class,
            BranchMaintenanceSeeder::class,
        ]);
    }
}
