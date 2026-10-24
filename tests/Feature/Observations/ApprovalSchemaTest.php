<?php

namespace Tests\Feature\Observations;

use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Seeds;
use Tests\TestCase;

/** Plan H task 1: approval columns, the `approved` status and the one-time backfill. */
class ApprovalSchemaTest extends TestCase
{
    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_10_25_000001_add_approval_to_observation_visits.php';

    public function test_schema_has_approval_columns_and_status_constant(): void
    {
        $this->assertTrue(Schema::hasColumn('observation_visits', 'approved_by'));
        $this->assertTrue(Schema::hasColumn('observation_visits', 'approved_at'));
        $this->assertSame(['draft', 'submitted', 'approved'], ObservationVisit::STATUSES);

        $visit = $this->visit(Seeds::department('cctv', ['is_reporting' => true]), Seeds::user('department_employee'));
        $approver = Seeds::user('department_manager');
        $visit->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);

        $fresh = $visit->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($approver->id, $fresh->approver->id);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->approved_at);
    }

    public function test_migration_backfills_visits_submitted_under_the_old_flow(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $operation = Seeds::department('operation');
        $recorder = Seeds::user('department_employee', ['department_id' => $cctv->id]);

        $withTicket = $this->visit($cctv, $recorder, ['status' => 'submitted', 'submitted_at' => '2026-10-20 09:00:00']);
        $o = Observation::create([
            'observation_visit_id' => $withTicket->id, 'reporting_department_id' => $cctv->id, 'responsible_department_id' => $operation->id,
            'kind' => 'issue', 'title' => 'x', 'description' => 'x', 'status' => 'open', 'classification_status' => 'manual', 'created_by' => $recorder->id,
        ]);
        $ticket = Ticket::create(['reference' => 'OPE-0001', 'title' => 'x', 'branch_id' => Seeds::branch('Saraya Mall')->id, 'department_id' => $operation->id, 'status' => 'open', 'priority' => 'medium', 'source' => 'observation', 'observation_id' => $o->id, 'created_by' => $recorder->id]);
        $o->update(['ticket_id' => $ticket->id]);

        // Activity-only visit of the old flow: finished too (no ticket was ever due).
        $noTicket = $this->visit($cctv, $recorder, ['status' => 'submitted', 'submitted_at' => '2026-10-21 09:00:00']);
        $draft = $this->visit($cctv, $recorder, ['status' => 'draft']);
        // A visit marked submitted without a timestamp is left alone.
        $noTimestamp = $this->visit($cctv, $recorder, ['status' => 'submitted', 'submitted_at' => null]);

        // Re-run the migration from scratch: drop the columns, then up().
        Schema::table('observation_visits', function ($table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at']);
        });
        $this->assertFalse(Schema::hasColumn('observation_visits', 'approved_at'));

        $migration = require self::MIGRATION;
        $migration->up();

        $this->assertTrue(Schema::hasColumn('observation_visits', 'approved_at'));
        $rows = DB::table('observation_visits')->get()->keyBy('id');
        $this->assertSame('approved', $rows[$withTicket->id]->status);
        $this->assertSame($recorder->id, (int) $rows[$withTicket->id]->approved_by);
        $this->assertSame('2026-10-20 09:00:00', $rows[$withTicket->id]->approved_at);
        $this->assertSame('approved', $rows[$noTicket->id]->status);
        $this->assertSame('draft', $rows[$draft->id]->status);
        $this->assertNull($rows[$draft->id]->approved_at);
        $this->assertSame('submitted', $rows[$noTimestamp->id]->status);

        // Running up() again is a no-op (guarded).
        $migration->up();
        $this->assertSame('submitted', DB::table('observation_visits')->where('id', $noTimestamp->id)->value('status'));
    }

    private function visit(Department $department, User $user, array $attrs = []): ObservationVisit
    {
        return ObservationVisit::create(array_merge([
            'type' => 'desk', 'department_id' => $department->id, 'user_id' => $user->id,
            'visit_date' => '2026-10-20', 'status' => 'draft', 'source' => 'web',
        ], $attrs));
    }
}
