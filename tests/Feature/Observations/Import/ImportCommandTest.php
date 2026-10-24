<?php

namespace Tests\Feature\Observations\Import;

use App\Console\Commands\ObservationsImport;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Observations\Import\ImportWriter;
use Database\Seeders\BranchSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\ObservationSetupSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Seeds;
use Tests\TestCase;

class ImportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Seeds::role('super_admin');
        $this->seed(DepartmentSeeder::class);
        $this->seed(BranchSeeder::class);
        $this->seed(ObservationSetupSeeder::class);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $branches = Branch::count();

        $this->artisan('observations:import', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('cctv')
            ->expectsOutputToContain('Shehab Store')
            ->assertExitCode(0);

        $this->assertSame(0, ObservationVisit::count());
        $this->assertSame(0, Observation::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame($branches, Branch::count(), 'the dry run creates no branches');
        $this->assertNull(User::where('email', ImportWriter::IMPORT_EMAIL)->first());
    }

    public function test_import_is_idempotent(): void
    {
        $this->artisan('observations:import')->assertExitCode(0);

        $observations = Observation::count();
        $visits = ObservationVisit::count();
        $tickets = Ticket::count();
        $updates = DB::table('ticket_updates')->count();

        $this->assertGreaterThanOrEqual(376, $observations);
        $this->assertSame(376, Observation::where('import_source', 'levoile_control_center_oct2026.xlsx')->count());
        $this->assertSame(376, Observation::whereNotNull('import_row')->distinct('import_row')->count('import_row'));
        $this->assertGreaterThan(0, $visits);
        $this->assertGreaterThan(0, $tickets);
        $this->assertSame(0, ObservationVisit::where('status', 'draft')->count());
        $this->assertSame($tickets, Ticket::where('source', 'observation')->whereNull('assigned_to')->count(), 'silent: nothing auto-assigned');
        $this->assertDatabaseCount('notifications', 0);

        // The four extra branches exist, without an area.
        foreach (array_keys(ObservationsImport::EXTRA_BRANCHES) as $name) {
            $this->assertNotNull(Branch::where('branch_name', $name)->first(), $name);
        }
        $this->assertNull(Branch::where('branch_name', 'Shehab Store')->value('area_id'));

        // Spot checks from the workbook.
        $first = Observation::where('import_row', '2')->sole();
        $this->assertSame('positive', $first->kind);
        $this->assertSame(Department::where('slug', 'merchandise')->value('id'), $first->reporting_department_id);
        $this->assertSame(Branch::where('branch_name', 'Nasr City - Gnena Mall')->value('id'), $first->branch_id);
        $this->assertSame('2026-10-13', $first->event_date->toDateString());
        $this->assertSame('2026-10-13 12:30:00', $first->visit->started_at->toDateTimeString(), 'Original Visits V001 start time');

        $this->assertSame(5, Observation::where('status', 'waiting_verification')->count(), 'Pending Verification rows');
        $this->assertSame(5, Ticket::where('status', 'closed')->count());
        $this->assertSame(18, Observation::whereNull('branch_id')->count(), 'All branches rows');
        $this->assertSame(0, Observation::whereNull('branch_id')->where('kind', 'issue')->count());
        $this->assertSame(0, Observation::where('kind', 'activity')->whereNotNull('ticket_id')->count());
        $this->assertSame(0, Observation::where('kind', 'positive')->whereNotNull('ticket_id')->count());

        // Second run: nothing new, everything skipped.
        $this->artisan('observations:import')
            ->expectsOutputToContain('376')
            ->assertExitCode(0);

        $this->assertSame($observations, Observation::count());
        $this->assertSame($visits, ObservationVisit::count());
        $this->assertSame($tickets, Ticket::count());
        $this->assertSame($updates, DB::table('ticket_updates')->count());
    }

    public function test_unknown_branch_aborts_before_any_write(): void
    {
        Branch::where('branch_name', 'Alexandria - Smouha')->delete();
        $branches = Branch::count();

        $this->artisan('observations:import')
            ->expectsOutputToContain('Smouha')
            ->expectsOutputToContain('aborted')
            ->assertExitCode(1);

        $this->assertSame(0, ObservationVisit::count());
        $this->assertSame(0, Observation::count());
        $this->assertSame($branches, Branch::count(), 'the extra branches are not created either');
    }

    public function test_only_filters_one_department(): void
    {
        $this->artisan('observations:import', ['--only' => 'hr'])->assertExitCode(0);

        $hr = Department::where('slug', 'hr')->value('id');
        $this->assertSame(7, Observation::count());
        $this->assertSame(7, Observation::where('reporting_department_id', $hr)->count());
        $this->assertSame(1, ObservationVisit::count());
        $this->assertSame('desk', ObservationVisit::sole()->type);
        $this->assertSame(0, Ticket::count());
    }
}
