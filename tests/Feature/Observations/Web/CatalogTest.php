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

class CatalogTest extends TestCase
{
    private function category(Department $dept, string $slug = 'cat'): ObservationCategory
    {
        return ObservationCategory::create([
            'department_id' => $dept->id, 'slug' => $slug, 'name_ar' => $slug, 'name_en' => ucfirst($slug),
            'default_kind' => 'issue', 'sort_order' => 1, 'active' => true,
        ]);
    }

    private function item(ObservationCategory $cat, string $code, array $attrs = []): ObservationCatalogItem
    {
        return ObservationCatalogItem::create(array_merge([
            'category_id' => $cat->id, 'code' => $code, 'label_ar' => $code, 'label_en' => $code, 'kind' => 'issue',
            'keywords' => [], 'extra_fields' => [], 'usage_count' => 0, 'sort_order' => 1, 'active' => true,
        ], $attrs));
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

    public function test_create_item_with_extra_fields_and_rule(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $operation = Seeds::department('operation');
        $lp = Seeds::department('lp');
        $medium = RiskLevel::create(['slug' => 'medium', 'name_ar' => 'متوسطة', 'name_en' => 'Medium', 'color' => '#eab308', 'sort_order' => 3, 'default_sla_hours' => 72, 'requires_field_verification' => false, 'ticket_priority' => 'medium', 'active' => true]);
        RiskLevel::create(['slug' => 'high', 'name_ar' => 'عالية', 'name_en' => 'High', 'color' => '#f97316', 'sort_order' => 2, 'default_sla_hours' => 48, 'requires_field_verification' => true, 'ticket_priority' => 'high', 'active' => true]);
        $cat = $this->category($cctv, 'branch_opening');
        $manager = Seeds::user('department_manager', ['department_id' => $cctv->id, 'is_department_manager' => true]);

        $this->actingAs($manager)->get('/admin/observation-catalog')->assertOk()->assertSee('Branch_opening');

        $this->actingAs($manager)
            ->from('/admin/observation-catalog')
            ->post('/admin/observation-catalog', [
                'category_id' => $cat->id,
                'code' => 'cctv-open-late',
                'label_ar' => 'تأخير عن ميعاد الفتح',
                'label_en' => 'Late opening',
                'keywords' => 'تأخير, فتح ,late, , opening',
                'kind' => 'issue',
                'risk_level_id' => $medium->id,
                'responsible_department_id' => $operation->id,
                'cc_department_ids' => [$lp->id],
                'sla_hours' => 12,
                'extra_fields' => [
                    ['key' => 'minutes_late', 'label_ar' => 'دقائق التأخير', 'label_en' => 'Minutes late', 'type' => 'number', 'required' => 1],
                    ['key' => 'employee', 'label_ar' => 'الموظف', 'label_en' => 'Employee', 'type' => 'employee'],
                    ['key' => 'shift', 'label_ar' => 'الوردية', 'label_en' => 'Shift', 'type' => 'select', 'options' => 'صباحي, مسائي'],
                    ['key' => '', 'label_ar' => '', 'label_en' => '', 'type' => 'text'], // blank row ignored
                ],
                'escalation_rules' => [
                    ['field' => 'minutes_late', 'op' => 'gte', 'value' => 30, 'risk' => 'high'],
                    ['field' => '', 'op' => 'gte', 'value' => '', 'risk' => ''], // blank row ignored
                ],
                'active' => 1,
            ])
            ->assertRedirect('/admin/observation-catalog')
            ->assertSessionHasNoErrors();

        $item = ObservationCatalogItem::where('code', 'CCTV-OPEN-LATE')->firstOrFail();
        $this->assertSame($cat->id, $item->category_id);
        $this->assertSame(['تأخير', 'فتح', 'late', 'opening'], $item->keywords);
        $this->assertSame($medium->id, $item->risk_level_id);
        $this->assertSame($operation->id, $item->responsible_department_id);
        $this->assertSame([$lp->id], $item->cc_department_ids);
        $this->assertSame(12, (int) $item->sla_hours);
        $this->assertCount(3, $item->extra_fields);
        $this->assertSame(['key' => 'minutes_late', 'label_ar' => 'دقائق التأخير', 'label_en' => 'Minutes late', 'type' => 'number', 'options' => null, 'required' => true], $item->extra_fields[0]);
        $this->assertFalse($item->extra_fields[1]['required']);
        $this->assertSame(['صباحي', 'مسائي'], $item->extra_fields[2]['options']);
        $this->assertSame([['field' => 'minutes_late', 'gte' => 30, 'risk' => 'high']], $item->escalation_rules);
        $this->assertTrue($item->active);
        $this->assertSame(0, (int) $item->usage_count);

        // Duplicate code and unknown field type / op are rejected.
        $this->actingAs($manager)->post('/admin/observation-catalog', ['category_id' => $cat->id, 'code' => 'CCTV-OPEN-LATE', 'label_ar' => 'x', 'label_en' => 'x', 'kind' => 'issue'])
            ->assertSessionHasErrors('code');
        $this->actingAs($manager)->post('/admin/observation-catalog', [
            'category_id' => $cat->id, 'code' => 'CCTV-Y', 'label_ar' => 'x', 'label_en' => 'x', 'kind' => 'issue',
            'extra_fields' => [['key' => 'a', 'label_ar' => 'a', 'label_en' => 'a', 'type' => 'date']],
            'escalation_rules' => [['field' => 'a', 'op' => 'gt', 'value' => 1, 'risk' => 'high']],
        ])->assertSessionHasErrors(['extra_fields.0.type', 'escalation_rules.0.op']);

        // Update keeps the code editable (still unique) and can clear the rules.
        $this->actingAs($manager)->put('/admin/observation-catalog/'.$item->id, [
            'category_id' => $cat->id, 'code' => 'CCTV-OPEN-LATE-2', 'label_ar' => 'تأخير', 'label_en' => 'Late', 'kind' => 'issue', 'active' => 1,
        ])->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame('CCTV-OPEN-LATE-2', $item->code);
        $this->assertSame([], $item->extra_fields);
        $this->assertNull($item->escalation_rules);
        $this->assertNull($item->cc_department_ids);
        $this->assertSame([], $item->keywords);
    }

    public function test_other_department_manager_forbidden(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $stock = Seeds::department('stock_control', ['is_reporting' => true]);
        $cctvCat = $this->category($cctv, 'cameras');
        $stockCat = $this->category($stock, 'damaged');
        $cctvItem = $this->item($cctvCat, 'CCTV-A');
        $stockItem = $this->item($stockCat, 'STO-A');
        $manager = Seeds::user('department_manager', ['department_id' => $stock->id, 'is_department_manager' => true]);

        // The list is locked to the manager's department even when asking for another one.
        $this->actingAs($manager)->get('/admin/observation-catalog?department_id='.$cctv->id)
            ->assertOk()->assertSee('STO-A')->assertDontSee('CCTV-A');

        $this->actingAs($manager)->post('/admin/observation-catalog', ['category_id' => $cctvCat->id, 'code' => 'CCTV-B', 'label_ar' => 'x', 'label_en' => 'x', 'kind' => 'issue'])->assertForbidden();
        $this->actingAs($manager)->put('/admin/observation-catalog/'.$cctvItem->id, ['category_id' => $cctvCat->id, 'code' => 'CCTV-A', 'label_ar' => 'x', 'label_en' => 'x', 'kind' => 'issue'])->assertForbidden();
        // Moving own item into another department's category is forbidden too.
        $this->actingAs($manager)->put('/admin/observation-catalog/'.$stockItem->id, ['category_id' => $cctvCat->id, 'code' => 'STO-A', 'label_ar' => 'x', 'label_en' => 'x', 'kind' => 'issue'])->assertForbidden();
        $this->actingAs($manager)->post('/admin/observation-catalog/'.$cctvItem->id.'/toggle')->assertForbidden();
        $this->actingAs($manager)->delete('/admin/observation-catalog/'.$cctvItem->id)->assertForbidden();
        $this->assertNotNull($cctvItem->fresh());
        $this->assertTrue($cctvItem->fresh()->active);

        // Plain employees and non-reporting managers: 403 on the list.
        $this->actingAs(Seeds::user('department_employee', ['department_id' => $stock->id]))->get('/admin/observation-catalog')->assertForbidden();
        $this->actingAs(Seeds::user('ops_manager'))->get('/admin/observation-catalog')->assertForbidden();
    }

    public function test_delete_blocked_when_used_toggle_allowed(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $admin = Seeds::user('super_admin');
        $cat = $this->category($cctv);
        $used = $this->item($cat, 'CCTV-USED', ['usage_count' => 3]);
        $referenced = $this->item($cat, 'CCTV-REF');
        $this->observation($cctv, $admin, ['category_id' => $cat->id, 'catalog_item_id' => $referenced->id]);
        $free = $this->item($cat, 'CCTV-FREE');

        $this->actingAs($admin)->from('/admin/observation-catalog')->delete('/admin/observation-catalog/'.$used->id)
            ->assertRedirect('/admin/observation-catalog')->assertSessionHas('status');
        $this->assertNotNull($used->fresh());

        $this->actingAs($admin)->delete('/admin/observation-catalog/'.$referenced->id)->assertRedirect();
        $this->assertNotNull($referenced->fresh());

        $this->actingAs($admin)->delete('/admin/observation-catalog/'.$free->id)->assertRedirect();
        $this->assertNull($free->fresh());

        // Toggle flips active on a used item.
        $this->actingAs($admin)->post('/admin/observation-catalog/'.$used->id.'/toggle')->assertRedirect();
        $this->assertFalse($used->fresh()->active);
        $this->actingAs($admin)->post('/admin/observation-catalog/'.$used->id.'/toggle')->assertRedirect();
        $this->assertTrue($used->fresh()->active);
        $this->assertSame(3, (int) $used->fresh()->usage_count);
    }
}
