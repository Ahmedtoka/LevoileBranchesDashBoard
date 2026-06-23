<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoVisitSeeder extends Seeder
{
    public function run(): void
    {
        // Store Manager (Nesma) daily checklist at her own branch.
        $nesma = User::where('email', 'nesma@levoile.test')->first();
        $storeTpl = VisitTemplate::where('slug', 'store-manager')->first();

        if ($nesma && $storeTpl && $nesma->branch_id) {
            Visit::updateOrCreate(
                ['visit_template_id' => $storeTpl->id, 'branch_id' => $nesma->branch_id, 'user_id' => $nesma->id, 'status' => 'assigned'],
                ['scheduled_date' => Carbon::today()]
            );
        }
    }
}
