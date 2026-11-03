<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\User;
use Tests\Support\Seeds;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    private function category(Department $dept, string $slug, int $order = 1): ObservationCategory
    {
        return ObservationCategory::create([
            'department_id' => $dept->id, 'slug' => $slug, 'name_ar' => $slug.' ar', 'name_en' => ucfirst($slug),
            'default_kind' => 'issue', 'sort_order' => $order, 'active' => true,
        ]);
    }

    private function observation(Department $dept, User $creator, array $attrs = []): Observation
    {
        $branch = Seeds::branch('Saraya Mall');
        $visit = ObservationVisit::create([
            'type' => 'field', 'department_id' => $dept->id, 'branch_id' => $branch->id, 'user_id' => $creator->id,
            'visit_date' => now()->toDateString(), 'source' => 'web',
        ]);

        return Observation::create(array_merge([
            'observation_visit_id' => $visit->id, 'branch_id' => $branch->id, 'reporting_department_id' => $dept->id,
            'kind' => 'issue', 'title' => 'Obs', 'description' => 'Obs', 'event_date' => now()->toDateString(),
            'created_by' => $creator->id, 'status' => 'open',
        ], $attrs));
    }

    public function test_manager_sees_only_own_department(): void
    {
        $cctv = Seeds::department('vm', ['is_reporting' => true]);
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $this->category($cctv, 'cameras');
        $this->category($stock, 'damaged_goods');
        $manager = Seeds::user('department_manager', ['department_id' => $cctv->id, 'is_department_manager' => true]);

        // Own department, even when asking for another one.
        $this->actingAs($manager)->get('/admin/observation-categories?department_id='.$stock->id)
            ->assertOk()->assertSee('Cameras')->assertDontSee('Damaged_goods');

        // Super admin sees the filter and all departments.
        $admin = Seeds::user('super_admin');
        $this->actingAs($admin)->get('/admin/observation-categories')->assertOk()->assertSee('Cameras')->assertSee('Damaged_goods');
        $this->actingAs($admin)->get('/admin/observation-categories?department_id='.$stock->id)->assertOk()->assertDontSee('Cameras');

        // Manager of a NON-reporting department and plain employees get nothing.
        $hr = Seeds::department('marketing', ['is_reporting' => false]);
        $this->actingAs(Seeds::user('department_manager', ['department_id' => $hr->id, 'is_department_manager' => true]))
            ->get('/admin/observation-categories')->assertForbidden();
        $this->actingAs(Seeds::user('department_employee', ['department_id' => $cctv->id]))
            ->get('/admin/observation-categories')->assertForbidden();

        // A manager cannot create for another department.
        $this->actingAs($manager)->post('/admin/observation-categories', [
            'department_id' => $stock->id, 'name_ar' => 'x', 'name_en' => 'X', 'default_kind' => 'issue',
        ])->assertForbidden();
    }

    public function test_create_with_defaults(): void
    {
        $cctv = Seeds::department('vm', ['is_reporting' => true]);
        $operation = Seeds::department('operation');
        $lp = Seeds::department('lp');
        $high = RiskLevel::create(['slug' => 'high', 'name_ar' => 'عالية', 'name_en' => 'High', 'color' => '#f97316', 'sort_order' => 2, 'default_sla_hours' => 48, 'requires_field_verification' => true, 'ticket_priority' => 'high', 'active' => true]);
        $manager = Seeds::user('department_manager', ['department_id' => $cctv->id, 'is_department_manager' => true]);

        $this->actingAs($manager)
            ->from('/admin/observation-categories')
            ->post('/admin/observation-categories', [
                'department_id' => $cctv->id,
                'name_ar' => 'الكاشير',
                'name_en' => 'Cashier',
                'default_kind' => 'issue',
                'default_risk_level_id' => $high->id,
                'default_responsible_department_id' => $operation->id,
                'default_cc_department_ids' => [$lp->id],
                'default_sla_hours' => 24,
                'active' => 1,
            ])
            ->assertRedirect('/admin/observation-categories')
            ->assertSessionHasNoErrors();

        $cat = ObservationCategory::where('department_id', $cctv->id)->where('slug', 'cashier')->firstOrFail();
        $this->assertSame('الكاشير', $cat->name_ar);
        $this->assertSame($high->id, $cat->default_risk_level_id);
        $this->assertSame($operation->id, $cat->default_responsible_department_id);
        $this->assertSame([$lp->id], $cat->default_cc_department_ids);
        $this->assertSame(24, (int) $cat->default_sla_hours);
        $this->assertSame(1, (int) $cat->sort_order);

        // Same slug in the same department is rejected; an update keeps the slug and stores empty cc as null.
        $this->actingAs($manager)->post('/admin/observation-categories', ['department_id' => $cctv->id, 'name_ar' => 'x', 'name_en' => 'Cashier', 'default_kind' => 'issue'])
            ->assertSessionHasErrors('name_en');

        $this->actingAs($manager)->put('/admin/observation-categories/'.$cat->id, [
            'department_id' => $cctv->id, 'name_ar' => 'الكاشير والنقدية', 'name_en' => 'Cashier & cash', 'default_kind' => 'issue', 'active' => 0,
        ])->assertSessionHasNoErrors();
        $cat->refresh();
        $this->assertSame('cashier', $cat->slug);
        $this->assertSame('الكاشير والنقدية', $cat->name_ar);
        $this->assertNull($cat->default_cc_department_ids);
        $this->assertNull($cat->default_risk_level_id);
        $this->assertFalse($cat->active);

        $this->actingAs($manager)->post('/admin/observation-categories', ['department_id' => $cctv->id, 'name_ar' => 'x', 'name_en' => 'Bad', 'default_kind' => 'nope'])
            ->assertSessionHasErrors('default_kind');
    }

    public function test_reorder(): void
    {
        $cctv = Seeds::department('vm', ['is_reporting' => true]);
        $a = $this->category($cctv, 'a', 1);
        $b = $this->category($cctv, 'b', 2);
        $c = $this->category($cctv, 'c', 3);
        $manager = Seeds::user('department_manager', ['department_id' => $cctv->id, 'is_department_manager' => true]);

        $this->actingAs($manager)
            ->post('/admin/observation-categories/reorder', ['ids' => [$c->id, $a->id, $b->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, (int) $c->fresh()->sort_order);
        $this->assertSame(2, (int) $a->fresh()->sort_order);
        $this->assertSame(3, (int) $b->fresh()->sort_order);

        // Another department's category in the list is forbidden for a manager.
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $other = $this->category($stock, 'z', 1);
        $this->actingAs($manager)
            ->post('/admin/observation-categories/reorder', ['ids' => [$other->id, $a->id]])
            ->assertForbidden();
        $this->assertSame(1, (int) $other->fresh()->sort_order);
    }

    public function test_category_delete_blocked_when_used(): void
    {
        $cctv = Seeds::department('vm', ['is_reporting' => true]);
        $admin = Seeds::user('super_admin');
        $withItem = $this->category($cctv, 'with_item');
        ObservationCatalogItem::create(['category_id' => $withItem->id, 'code' => 'CCTV-X', 'label_ar' => 'x', 'label_en' => 'x', 'kind' => 'issue', 'keywords' => [], 'extra_fields' => [], 'usage_count' => 0, 'sort_order' => 1, 'active' => true]);
        $withObs = $this->category($cctv, 'with_obs');
        $this->observation($cctv, $admin, ['category_id' => $withObs->id]);
        $empty = $this->category($cctv, 'empty');

        $this->actingAs($admin)->from('/admin/observation-categories')->delete('/admin/observation-categories/'.$withItem->id)
            ->assertRedirect('/admin/observation-categories')->assertSessionHas('status');
        $this->assertNotNull($withItem->fresh());
        $this->assertSame(1, $withItem->items()->count());

        $this->actingAs($admin)->delete('/admin/observation-categories/'.$withObs->id)->assertRedirect();
        $this->assertNotNull($withObs->fresh());

        $this->actingAs($admin)->delete('/admin/observation-categories/'.$empty->id)->assertRedirect();
        $this->assertNull($empty->fresh());

        // Another department's manager cannot delete.
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $other = Seeds::user('department_manager', ['department_id' => $stock->id, 'is_department_manager' => true]);
        $this->actingAs($other)->delete('/admin/observation-categories/'.$withItem->id)->assertForbidden();
    }
}
