<?php

namespace Tests\Feature\Observations;

use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Models\Visit;
use App\Models\VisitTemplate;
use Tests\Support\Seeds;
use Tests\TestCase;

class SchemaObservationsTest extends TestCase
{
    public function test_observation_reference_increments(): void
    {
        $department = Seeds::department('cctv');
        $branch = Seeds::branch('Heliopolis');
        $user = Seeds::user('department_employee', ['department_id' => $department->id]);

        $visit = ObservationVisit::create([
            'type' => 'field',
            'department_id' => $department->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'visit_date' => now()->toDateString(),
            'source' => 'web',
        ]);

        $attrs = [
            'observation_visit_id' => $visit->id,
            'branch_id' => $branch->id,
            'reporting_department_id' => $department->id,
            'kind' => 'issue',
            'title' => 'Late opening',
            'description' => 'Branch opened late',
            'event_date' => now()->toDateString(),
            'created_by' => $user->id,
        ];

        $first = Observation::create($attrs);
        $second = Observation::create($attrs);

        $this->assertSame('OV-00001', $visit->code);
        $this->assertSame('OB-00001', $first->reference);
        $this->assertSame('OB-00002', $second->reference);
        $this->assertSame('open', $first->fresh()->status);
        $this->assertSame('manual', $first->fresh()->classification_status);
    }

    public function test_next_numbers_derive_from_highest_existing_number(): void
    {
        $department = Seeds::department('cctv');
        $branch = Seeds::branch('Heliopolis');
        $user = Seeds::user('department_employee', ['department_id' => $department->id]);

        $visitAttrs = [
            'type' => 'field',
            'department_id' => $department->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'visit_date' => now()->toDateString(),
            'source' => 'import',
        ];

        $imported = ObservationVisit::create($visitAttrs + ['code' => 'OV-00010']);
        ObservationVisit::create($visitAttrs + ['code' => 'OV-00003']);
        $this->assertSame('OV-00011', ObservationVisit::create($visitAttrs)->code);

        $attrs = [
            'observation_visit_id' => $imported->id,
            'branch_id' => $branch->id,
            'reporting_department_id' => $department->id,
            'kind' => 'issue',
            'title' => 'Imported',
            'description' => 'Imported row',
            'event_date' => now()->toDateString(),
            'created_by' => $user->id,
        ];

        Observation::create($attrs + ['reference' => 'OB-00010']);
        Observation::create($attrs + ['reference' => 'OB-00003']);
        $this->assertSame('OB-00011', Observation::create($attrs)->reference);
    }

    public function test_ticket_source_backfill(): void
    {
        $branch = Seeds::branch('Nasr City');
        $user = Seeds::user('super_admin');
        $template = VisitTemplate::create(['name' => 'Store check', 'slug' => 'store-check']);
        $visit = Visit::create(['visit_template_id' => $template->id, 'branch_id' => $branch->id, 'user_id' => $user->id]);

        $base = ['title' => 'T', 'branch_id' => $branch->id, 'status' => 'open', 'created_by' => $user->id];
        $checklist = Ticket::create($base + ['reference' => 'TK-9001', 'visit_id' => $visit->id]);
        $maintenance = Ticket::create($base + ['reference' => 'MTN-9002', 'group_code' => 'MR-0001']);
        $plain = Ticket::create($base + ['reference' => 'TK-9003']);

        $migration = require database_path('migrations/2026_10_22_000004_add_source_and_observation_to_tickets.php');

        foreach ([1, 2] as $run) {
            $migration->up();

            $this->assertSame('checklist', $checklist->fresh()->source, "run $run");
            $this->assertSame('maintenance', $maintenance->fresh()->source, "run $run");
            $this->assertNull($plain->fresh()->source, "run $run");
        }
    }
}
