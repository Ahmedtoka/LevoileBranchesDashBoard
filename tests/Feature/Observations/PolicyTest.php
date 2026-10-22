<?php

namespace Tests\Feature\Observations;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ObservationScope;
use Tests\Support\Seeds;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    private function observation(Branch $branch, Department $department, User $creator, array $attrs = []): Observation
    {
        $visit = ObservationVisit::create([
            'type' => 'field',
            'department_id' => $department->id,
            'branch_id' => $branch->id,
            'user_id' => $creator->id,
            'visit_date' => now()->toDateString(),
            'source' => 'web',
        ]);

        return Observation::create(array_merge([
            'observation_visit_id' => $visit->id,
            'branch_id' => $branch->id,
            'reporting_department_id' => $department->id,
            'kind' => 'issue',
            'title' => 'Observation',
            'description' => 'Observation description',
            'event_date' => now()->toDateString(),
            'created_by' => $creator->id,
            'status' => 'open',
        ], $attrs));
    }

    private function scopedIds(User $user): array
    {
        return ObservationScope::apply(Observation::query(), $user)->orderBy('id')->pluck('id')->all();
    }

    public function test_non_reporting_employee_denied(): void
    {
        $cleaning = Seeds::department('cleaning', ['is_reporting' => false]);
        $employee = Seeds::user('department_employee', ['department_id' => $cleaning->id]);

        $this->assertFalse($employee->can('create', ObservationVisit::class));
        $this->assertFalse($employee->can('viewAny', Observation::class));
        $this->assertSame([], ObservationScope::apply(Observation::query(), $employee)->pluck('id')->all());

        // Contrast: an employee of a reporting department is allowed, so the denial above is the policy, not a missing Gate.
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $reporter = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $this->assertTrue($reporter->can('create', ObservationVisit::class));
        $this->assertTrue($reporter->can('viewAny', Observation::class));
    }

    public function test_area_manager_scope_uses_area_table(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $am = Seeds::user('area_manager');
        $area = Area::create(['name' => 'Heliopolis', 'slug' => 'heliopolis', 'manager_id' => $am->id]);
        $first = Seeds::branch('Branch A', ['area_id' => $area->id]);
        $second = Seeds::branch('Branch B', ['area_id' => $area->id]);
        $outside = Seeds::branch('Branch C');
        $creator = Seeds::user('department_employee', ['department_id' => $cctv->id]);

        $inA = $this->observation($first, $cctv, $creator);
        $inB = $this->observation($second, $cctv, $creator);
        $this->observation($outside, $cctv, $creator);

        $ids = $am->scopedBranchIds();
        sort($ids);
        $this->assertSame([$first->id, $second->id], $ids);
        $this->assertSame([$inA->id, $inB->id], $this->scopedIds($am));
    }

    public function test_store_manager_sees_only_own_branch(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $own = Seeds::branch('Own Branch');
        $other = Seeds::branch('Other Branch');
        $manager = Seeds::user('store_manager', ['branch_id' => $own->id]);
        $creator = Seeds::user('department_employee', ['department_id' => $cctv->id]);

        $mine = $this->observation($own, $cctv, $creator);
        $this->observation($other, $cctv, $creator);

        $this->assertSame([$mine->id], $this->scopedIds($manager));
        $this->assertTrue($manager->can('viewAny', Observation::class));
        $this->assertFalse($manager->isReportingUser());
    }

    public function test_department_manager_can_manage_only_own_categories(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $manager = Seeds::user('department_manager', ['department_id' => $cctv->id, 'is_department_manager' => true]);

        $this->assertTrue($manager->can('manageCategory', [ObservationCategory::class, $cctv]));
        $this->assertFalse($manager->can('manageCategory', [ObservationCategory::class, $stock]));
        $this->assertFalse($manager->can('manageCategory', [ObservationCategory::class, null]));
    }

    public function test_default_reporting_department(): void
    {
        $operation = Seeds::department('operation', ['is_reporting' => true]);
        $vm = Seeds::department('vm', ['is_reporting' => true]);
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);

        $am = Seeds::user('area_manager');
        $employee = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $vmManager = Seeds::user('vm_manager');

        $this->assertSame($operation->id, $am->defaultReportingDepartmentId());
        $this->assertSame($cctv->id, $employee->defaultReportingDepartmentId());
        $this->assertSame($vm->id, $vmManager->defaultReportingDepartmentId());
    }

    public function test_department_manager_scope_includes_tickets_for_their_department(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $operation = Seeds::department('operation', ['is_reporting' => true]);
        $maintenance = Seeds::department('maintenance', ['is_reporting' => true]);
        $branch = Seeds::branch('Heliopolis');
        $creator = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $manager = Seeds::user('department_manager', ['department_id' => $operation->id, 'is_department_manager' => true]);

        $toOperation = $this->observation($branch, $cctv, $creator);
        $toMaintenance = $this->observation($branch, $cctv, $creator);
        $reopened = $this->observation($branch, $cctv, $creator);

        $ticket = fn (Observation $obs, Department $dept, string $ref) => Ticket::create([
            'reference' => $ref,
            'title' => 'Ticket',
            'branch_id' => $branch->id,
            'department_id' => $dept->id,
            'status' => 'open',
            'observation_id' => $obs->id,
        ]);

        // Current tickets, linked both ways.
        $toOperation->update(['ticket_id' => $ticket($toOperation, $operation, 'TK-T0001')->id]);
        $toMaintenance->update(['ticket_id' => $ticket($toMaintenance, $maintenance, 'TK-T0002')->id]);

        // An older ticket for operation, linked only through tickets.observation_id; no current ticket.
        $ticket($reopened, $operation, 'TK-T0003');
        $this->assertNull($reopened->fresh()->ticket_id);

        $this->assertSame([$toOperation->id, $reopened->id], $this->scopedIds($manager));
        $this->assertTrue($manager->can('view', $toOperation));
        $this->assertTrue($manager->can('view', $reopened));
        $this->assertFalse($manager->can('view', $toMaintenance));
    }
}
