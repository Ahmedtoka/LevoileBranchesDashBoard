<?php

namespace Tests\Feature\Observations;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Notification;
use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\Seeds;
use Tests\TestCase;

class EscalateCommandTest extends TestCase
{
    private User $areaManager;

    private User $opsManager;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 10:00:00');

        $this->areaManager = Seeds::user('area_manager');
        $this->opsManager = Seeds::user('ops_manager');
        $area = Area::create(['name' => 'Cairo', 'slug' => 'cairo', 'manager_id' => $this->areaManager->id]);
        $this->branch = Seeds::branch('Saraya Mall', ['area_id' => $area->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An observation with its ticket, due $dueHoursAgo hours ago (negative = in the future). */
    private function makeObservation(string $suffix, int $dueHoursAgo = 5, string $status = 'open', string $ticketStatus = 'open', string $kind = 'issue'): Observation
    {
        $department = Seeds::department('operation', ['is_reporting' => true]);
        $risk = RiskLevel::firstOrCreate(['slug' => 'high'], [
            'name_ar' => 'high', 'name_en' => 'high', 'color' => '#000000', 'sort_order' => 2,
            'default_sla_hours' => 48, 'requires_field_verification' => true, 'ticket_priority' => 'high', 'active' => true,
        ]);
        $visit = ObservationVisit::create([
            'type' => 'field', 'department_id' => $department->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->opsManager->id, 'visit_date' => now()->toDateString(), 'started_at' => now(),
            'status' => 'submitted', 'source' => 'mobile',
        ]);
        $observation = Observation::create([
            'observation_visit_id' => $visit->id, 'branch_id' => $this->branch->id,
            'reporting_department_id' => $department->id, 'responsible_department_id' => $department->id,
            'kind' => $kind, 'risk_level_id' => $risk->id, 'title' => 'Issue '.$suffix, 'description' => 'Issue '.$suffix,
            'event_date' => now()->toDateString(), 'status' => $status, 'classification_status' => 'manual',
            'created_by' => $this->opsManager->id,
        ]);
        $ticket = Ticket::create([
            'reference' => 'TKT-'.$suffix, 'title' => 'Issue '.$suffix, 'branch_id' => $this->branch->id,
            'department_id' => $department->id, 'created_by' => $this->opsManager->id, 'status' => $ticketStatus,
            'priority' => 'high', 'due_at' => now()->subHours($dueHoursAgo), 'source' => 'observation',
            'observation_id' => $observation->id,
        ]);
        $observation->update(['ticket_id' => $ticket->id]);

        return $observation->fresh();
    }

    private function escalations()
    {
        return Notification::where('type', 'observation_overdue');
    }

    public function test_overdue_notifies_area_and_ops_once_per_day(): void
    {
        $observation = $this->makeObservation('A1');

        $this->artisan('observations:escalate')
            ->expectsOutputToContain($observation->reference)
            ->expectsOutputToContain('Escalated 1 overdue observation(s).')
            ->assertExitCode(0);

        $this->assertSame(2, $this->escalations()->count());
        foreach ([$this->areaManager, $this->opsManager] as $user) {
            $row = $this->escalations()->where('user_id', $user->id)->sole();
            $this->assertSame($observation->ticket_id, $row->ticket_id);
            $this->assertSame('/tickets/'.$observation->ticket_id, $row->link);
            $this->assertStringContainsString($observation->reference, $row->body);
            $this->assertStringContainsString('Saraya Mall', $row->body);
        }
        $this->assertNotNull($observation->fresh()->last_overdue_notified_at);

        // Same day: nothing new.
        $this->artisan('observations:escalate')->expectsOutputToContain('Escalated 0 overdue observation(s).')->assertExitCode(0);
        $this->assertSame(2, $this->escalations()->count());

        // 25 hours later it is flagged again.
        Carbon::setTestNow(now()->addHours(25));
        $this->artisan('observations:escalate')->assertExitCode(0);
        $this->assertSame(4, $this->escalations()->count());
    }

    public function test_area_manager_who_is_also_ops_manager_is_notified_once(): void
    {
        Area::query()->update(['manager_id' => $this->opsManager->id]);
        $this->makeObservation('B1');

        $this->artisan('observations:escalate')->assertExitCode(0);

        $this->assertSame(1, $this->escalations()->where('user_id', $this->opsManager->id)->count());
        $this->assertSame(1, $this->escalations()->count());
    }

    public function test_non_overdue_and_closed_are_skipped(): void
    {
        $this->makeObservation('C1', dueHoursAgo: -10);                      // not yet due
        $this->makeObservation('C2', ticketStatus: 'closed');                // ticket closed
        $this->makeObservation('C3', ticketStatus: 'waiting_approval');      // awaiting approval
        $this->makeObservation('C4', status: 'verified_closed');             // observation closed
        $this->makeObservation('C5', status: 'waiting_verification');        // fixed, being verified
        $this->makeObservation('C6', status: 'positive', kind: 'positive');  // not an issue
        $noTicket = $this->makeObservation('C7');                            // ticket link removed
        $noTicket->update(['ticket_id' => null]);

        $this->artisan('observations:escalate')->expectsOutputToContain('Escalated 0 overdue observation(s).')->assertExitCode(0);

        $this->assertSame(0, $this->escalations()->count());
        $this->assertSame(0, Observation::whereNotNull('last_overdue_notified_at')->count());
    }

    public function test_inactive_ops_manager_is_not_notified(): void
    {
        $inactive = Seeds::user('ops_manager', ['active' => false]);
        $this->makeObservation('D1');

        $this->artisan('observations:escalate')->assertExitCode(0);

        $this->assertSame(0, $this->escalations()->where('user_id', $inactive->id)->count());
        $this->assertSame(2, $this->escalations()->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $observation = $this->makeObservation('E1');

        $this->artisan('observations:escalate', ['--dry-run' => true])
            ->expectsOutputToContain($observation->reference)
            ->expectsOutputToContain('Escalated 1 overdue observation(s).')
            ->assertExitCode(0);

        $this->assertSame(0, $this->escalations()->count());
        $this->assertNull($observation->fresh()->last_overdue_notified_at);
    }
}
