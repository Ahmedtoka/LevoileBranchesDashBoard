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

    private function ticketFor(Observation $observation, Department $department, string $reference): Ticket
    {
        $ticket = Ticket::create([
            'reference' => $reference,
            'title' => 'Ticket',
            'branch_id' => $observation->branch_id,
            'department_id' => $department->id,
            'status' => 'open',
            'observation_id' => $observation->id,
        ]);
        $observation->update(['ticket_id' => $ticket->id]);

        return $ticket;
    }

    private function scopedIds(User $user): array
    {
        return ObservationScope::apply(Observation::query(), $user)->orderBy('id')->pluck('id')->all();
    }

    public function test_non_reporting_employee_denied(): void
    {
        $cleaning = Seeds::department('cleaning', ['is_reporting' => false]);
        $employee = Seeds::user('department_employee', ['department_id' => $cleaning->id]);
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $reporter = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $this->observation(Seeds::branch('Heliopolis'), $cctv, $reporter);

        $this->assertFalse($employee->can('create', ObservationVisit::class));
        $this->assertFalse($employee->can('viewAny', Observation::class));
        $this->assertSame([], $this->scopedIds($employee));

        // Contrast: an employee of a reporting department is allowed, so the denial above is the policy, not a missing Gate.
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

    public function test_sales_role_has_no_module_access(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $own = Seeds::branch('Own Branch');
        $sales = Seeds::user('sales', ['branch_id' => $own->id]);
        $this->observation($own, $cctv, Seeds::user('department_employee', ['department_id' => $cctv->id]));

        // Spec section 5 grants sales nothing, even with a branch set.
        $this->assertFalse($sales->can('viewAny', Observation::class));
        $this->assertSame([], $sales->scopedBranchIds());
        $this->assertSame([], $this->scopedIds($sales));
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

    public function test_non_reporting_department_with_routed_ticket_sees_nothing(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $warehouse = Seeds::department('warehouse', ['is_reporting' => false]);
        $reporter = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $manager = Seeds::user('department_manager', ['department_id' => $warehouse->id, 'is_department_manager' => true]);
        $employee = Seeds::user('department_employee', ['department_id' => $warehouse->id]);

        $observation = $this->observation(Seeds::branch('Heliopolis'), $cctv, $reporter, ['responsible_department_id' => $warehouse->id]);
        $this->ticketFor($observation, $warehouse, 'TK-W0001');

        $this->assertSame([$observation->id], $this->scopedIds(Seeds::user('super_admin')));
        foreach ([$manager, $employee] as $user) {
            $this->assertSame([], $this->scopedIds($user));
            $this->assertFalse($user->can('view', $observation));
        }
    }

    public function test_verify_policy(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $am = Seeds::user('area_manager');
        $otherAm = Seeds::user('area_manager');
        $area = Area::create(['name' => 'East', 'slug' => 'east', 'manager_id' => $am->id]);
        Area::create(['name' => 'West', 'slug' => 'west', 'manager_id' => $otherAm->id]);
        $branch = Seeds::branch('Branch A', ['area_id' => $area->id]);
        $member = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $storeManager = Seeds::user('store_manager', ['branch_id' => $branch->id]);

        $observation = $this->observation($branch, $cctv, Seeds::user('department_employee', ['department_id' => $cctv->id]));

        $this->assertTrue($member->can('verify', $observation));
        $this->assertTrue($am->can('verify', $observation));
        $this->assertFalse($otherAm->can('verify', $observation));
        $this->assertFalse($storeManager->can('verify', $observation));
    }

    public function test_reroute_and_close_no_action_only_ops_and_super(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $am = Seeds::user('area_manager');
        $area = Area::create(['name' => 'East', 'slug' => 'east', 'manager_id' => $am->id]);
        $branch = Seeds::branch('Branch A', ['area_id' => $area->id]);
        $deptManager = Seeds::user('department_manager', ['department_id' => $cctv->id, 'is_department_manager' => true]);
        $ops = Seeds::user('ops_manager');
        $super = Seeds::user('super_admin');
        $observation = $this->observation($branch, $cctv, Seeds::user('department_employee', ['department_id' => $cctv->id]));

        foreach (['reroute', 'closeNoAction'] as $ability) {
            $this->assertTrue($ops->can($ability, $observation), $ability);
            $this->assertTrue($super->can($ability, $observation), $ability);
            $this->assertFalse($am->can($ability, $observation), $ability);
            $this->assertFalse($deptManager->can($ability, $observation), $ability);
        }
    }

    public function test_raise_risk_only_ops_and_super(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $am = Seeds::user('area_manager');
        $area = Area::create(['name' => 'East', 'slug' => 'east', 'manager_id' => $am->id]);
        $branch = Seeds::branch('Branch A', ['area_id' => $area->id]);
        $deptManager = Seeds::user('department_manager', ['department_id' => $cctv->id, 'is_department_manager' => true]);
        $recorder = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $observation = $this->observation($branch, $cctv, $recorder);

        $this->assertTrue(Seeds::user('ops_manager')->can('raiseRisk', $observation));
        $this->assertTrue(Seeds::user('super_admin')->can('raiseRisk', $observation));
        $this->assertFalse($am->can('raiseRisk', $observation));
        $this->assertFalse($deptManager->can('raiseRisk', $observation));
        $this->assertFalse($recorder->can('raiseRisk', $observation));
    }

    public function test_review_policy(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $cctvManager = Seeds::user('department_manager', ['department_id' => $cctv->id, 'is_department_manager' => true]);
        $stockManager = Seeds::user('department_manager', ['department_id' => $stock->id, 'is_department_manager' => true]);
        $observation = $this->observation(Seeds::branch('Heliopolis'), $cctv, Seeds::user('department_employee', ['department_id' => $cctv->id]));

        $this->assertTrue($cctvManager->can('review', [ObservationCategory::class, $observation]));
        $this->assertFalse($stockManager->can('review', [ObservationCategory::class, $observation]));
    }

    public function test_visit_update_and_submit_owner_draft_only(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $branch = Seeds::branch('Heliopolis');
        $owner = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $colleague = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $draft = $this->observation($branch, $cctv, $owner)->visit->fresh();
        $submitted = $this->observation($branch, $cctv, $owner)->visit;
        $submitted->update(['status' => 'submitted']);

        $this->assertSame('draft', $draft->status);
        foreach (['update', 'submit'] as $ability) {
            $this->assertTrue($owner->can($ability, $draft), $ability);
            $this->assertFalse($colleague->can($ability, $draft), $ability);
            $this->assertFalse($owner->can($ability, $submitted), $ability);
        }
    }

    public function test_employee_scope_covers_own_and_department_observations(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $branch = Seeds::branch('Heliopolis');
        $employee = Seeds::user('department_employee', ['department_id' => $cctv->id]);

        $own = $this->observation($branch, $cctv, $employee);
        $colleagues = $this->observation($branch, $cctv, Seeds::user('department_employee', ['department_id' => $cctv->id]));
        $this->observation($branch, $stock, Seeds::user('department_employee', ['department_id' => $stock->id]));

        $this->assertSame([$own->id, $colleagues->id], $this->scopedIds($employee));
    }

    public function test_store_manager_scope_via_branch_manager_id(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $manager = Seeds::user('store_manager');
        $managed = Seeds::branch('Managed Branch', ['manager_id' => $manager->id]);
        $creator = Seeds::user('department_employee', ['department_id' => $cctv->id]);

        $mine = $this->observation($managed, $cctv, $creator);
        $this->observation(Seeds::branch('Other Branch'), $cctv, $creator);

        $this->assertNull($manager->branch_id);
        $this->assertSame([$mine->id], $this->scopedIds($manager));
        $this->assertTrue($manager->can('view', $mine));
    }

    public function test_area_manager_scope_unions_branch_user(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $am = Seeds::user('area_manager');
        $area = Area::create(['name' => 'East', 'slug' => 'east', 'manager_id' => $am->id]);
        $viaArea = Seeds::branch('Branch A', ['area_id' => $area->id]);
        $viaPivot = Seeds::branch('Branch B');
        $am->branches()->attach($viaPivot->id);
        $creator = Seeds::user('department_employee', ['department_id' => $cctv->id]);

        $a = $this->observation($viaArea, $cctv, $creator);
        $b = $this->observation($viaPivot, $cctv, $creator);
        $this->observation(Seeds::branch('Branch C'), $cctv, $creator);

        $this->assertSame([$a->id, $b->id], $this->scopedIds($am));
    }
}
