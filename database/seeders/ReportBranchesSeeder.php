<?php

namespace Database\Seeders;

use App\Console\Commands\ObservationsImport;
use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * The branches the CCTV sheets and finance reports name that the branch table may lack (Gamal Abd El Nasser,
 * MDS, Shehab, Mall of Egypt 2). Locally observations:import created them on 2026-10-24; a database that never
 * ran it (live) stops cctv:import-weeks / finance:import-weeks on "unknown branch". Run once before those
 * imports; a branch that already exists is left as it is.
 *
 *   php artisan db:seed --class=ReportBranchesSeeder --force
 */
class ReportBranchesSeeder extends Seeder
{
    public function run(): void
    {
        $present = Branch::whereIn('branch_name', array_keys(ObservationsImport::EXTRA_BRANCHES))->pluck('branch_name')->all();
        foreach (ObservationsImport::EXTRA_BRANCHES as $name => $attributes) {
            if (! in_array($name, $present, true)) {
                Branch::create(array_merge(['branch_name' => $name, 'active' => true, 'checkin_radius' => 150, 'area_id' => null], $attributes));
                $this->command?->info("Added branch: {$name}");
            }
        }
    }
}
