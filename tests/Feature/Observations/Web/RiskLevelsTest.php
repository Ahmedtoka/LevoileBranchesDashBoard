<?php

namespace Tests\Feature\Observations\Web;

use App\Models\RiskLevel;
use Tests\Support\Seeds;
use Tests\TestCase;

class RiskLevelsTest extends TestCase
{
    private function level(string $slug, int $order, ?int $sla, bool $verify): RiskLevel
    {
        return RiskLevel::create([
            'slug' => $slug, 'name_ar' => $slug.' ar', 'name_en' => ucfirst($slug), 'color' => '#000000',
            'sort_order' => $order, 'default_sla_hours' => $sla, 'requires_field_verification' => $verify,
            'ticket_priority' => $slug === 'none' ? null : $slug, 'active' => true,
        ]);
    }

    public function test_index_lists_levels_for_super_admin_only(): void
    {
        $admin = Seeds::user('super_admin');
        $this->level('high', 2, 48, true);

        $this->actingAs($admin)->get('/admin/risk-levels')->assertOk()->assertSee('High');
        $this->actingAs(Seeds::user('ops_manager'))->get('/admin/risk-levels')->assertForbidden();
    }

    public function test_update_sla_and_flag(): void
    {
        $admin = Seeds::user('super_admin');
        $high = $this->level('high', 2, 48, true);

        $this->actingAs($admin)
            ->from('/admin/risk-levels')
            ->put('/admin/risk-levels/'.$high->id, [
                'name_ar' => 'عالية',
                'name_en' => 'High',
                'color' => '#f97316',
                'default_sla_hours' => 36,
                // requires_field_verification omitted => unchecked => false
                'active' => 1,
            ])
            ->assertRedirect('/admin/risk-levels')
            ->assertSessionHasNoErrors();

        $high->refresh();
        $this->assertSame('عالية', $high->name_ar);
        $this->assertSame('#f97316', $high->color);
        $this->assertSame(36, (int) $high->default_sla_hours);
        $this->assertFalse($high->requires_field_verification);
        $this->assertTrue($high->active);

        // Empty SLA = no deadline (the `none` level).
        $this->actingAs($admin)
            ->put('/admin/risk-levels/'.$high->id, ['name_ar' => 'عالية', 'name_en' => 'High', 'color' => '#f97316', 'default_sla_hours' => '', 'requires_field_verification' => 1, 'active' => 1])
            ->assertSessionHasNoErrors();
        $this->assertNull($high->fresh()->default_sla_hours);
        $this->assertTrue($high->fresh()->requires_field_verification);
    }

    public function test_slug_is_immutable(): void
    {
        $admin = Seeds::user('super_admin');
        $high = $this->level('high', 2, 48, true);

        $this->actingAs($admin)
            ->put('/admin/risk-levels/'.$high->id, [
                'slug' => 'urgent', 'sort_order' => 9, 'ticket_priority' => 'critical',
                'name_ar' => 'عالية', 'name_en' => 'High', 'color' => '#f97316', 'default_sla_hours' => 48, 'active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $high->refresh();
        $this->assertSame('high', $high->slug);
        $this->assertSame(2, (int) $high->sort_order);
        $this->assertSame('high', $high->ticket_priority);
    }

    public function test_rejects_zero_hours(): void
    {
        $admin = Seeds::user('super_admin');
        $high = $this->level('high', 2, 48, true);

        $this->actingAs($admin)
            ->from('/admin/risk-levels')
            ->put('/admin/risk-levels/'.$high->id, ['name_ar' => 'عالية', 'name_en' => 'High', 'color' => '#f97316', 'default_sla_hours' => 0, 'active' => 1])
            ->assertRedirect('/admin/risk-levels')
            ->assertSessionHasErrors('default_sla_hours');
        $this->assertSame(48, (int) $high->fresh()->default_sla_hours);

        $this->actingAs($admin)
            ->put('/admin/risk-levels/'.$high->id, ['name_ar' => 'عالية', 'name_en' => 'High', 'color' => '#f97316', 'default_sla_hours' => -5, 'active' => 1])
            ->assertSessionHasErrors('default_sla_hours');

        $this->actingAs(Seeds::user('ops_manager'))
            ->put('/admin/risk-levels/'.$high->id, ['name_ar' => 'x', 'name_en' => 'x', 'default_sla_hours' => 10])
            ->assertForbidden();
    }
}
