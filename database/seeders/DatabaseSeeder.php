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
            RealTeamSeeder::class, // real store/sales team + maintenance + ops + area managers
            TemplateTypeSeeder::class,
            MaintenanceItemSeeder::class,
            TemplateSeeder::class,
            RealChecklistSeeder::class,
            TranslationSeeder::class,
            PurchasingSeeder::class, // real purchasing data (vendors, requisitions, POs)
            // Demo tickets/visits intentionally NOT seeded — clean system.
            // DemoVisitSeeder::class,
            // BranchMaintenanceSeeder::class,
        ]);
    }
}
