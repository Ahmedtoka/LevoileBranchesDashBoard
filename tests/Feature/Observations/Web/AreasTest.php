<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Area;
use Illuminate\Support\Facades\DB;
use Tests\Support\Seeds;
use Tests\TestCase;

class AreasTest extends TestCase
{
    public function test_super_admin_creates_area(): void
    {
        $admin = Seeds::user('super_admin');
        $manager = Seeds::user('area_manager');
        $b1 = Seeds::branch('Saraya Mall');
        $b2 = Seeds::branch('City Stars');

        $this->actingAs($admin)->get('/admin/areas')->assertOk()->assertSee('Saraya Mall');

        $this->actingAs($admin)
            ->from('/admin/areas')
            ->post('/admin/areas', [
                'name' => 'Cairo West',
                'name_ar' => 'غرب القاهرة',
                'manager_id' => $manager->id,
                'branch_ids' => [$b1->id, $b2->id],
                'active' => 1,
            ])
            ->assertRedirect('/admin/areas')
            ->assertSessionHasNoErrors();

        $area = Area::where('slug', 'cairo-west')->firstOrFail();
        $this->assertSame('غرب القاهرة', $area->name_ar);
        $this->assertSame($manager->id, $area->manager_id);
        $this->assertTrue($area->active);
        $this->assertEqualsCanonicalizing([$b1->id, $b2->id], $area->branches()->pluck('id')->all());
    }

    public function test_branch_moves_between_areas(): void
    {
        $admin = Seeds::user('super_admin');
        $east = Area::create(['name' => 'East', 'slug' => 'east', 'active' => true]);
        $west = Area::create(['name' => 'West', 'slug' => 'west', 'active' => true]);
        $b1 = Seeds::branch('Heliopolis', ['area_id' => $east->id]);
        $b2 = Seeds::branch('Nasr City', ['area_id' => $east->id]);

        $this->actingAs($admin)
            ->post('/admin/areas/'.$west->id.'/branches', ['branch_ids' => [$b1->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($west->id, $b1->fresh()->area_id);
        $this->assertSame($east->id, $b2->fresh()->area_id);
        $this->assertSame([$b1->id], $west->branches()->pluck('id')->all());

        // Dropping a branch from the area's list clears its area.
        $this->actingAs($admin)
            ->post('/admin/areas/'.$east->id.'/branches', ['branch_ids' => []])
            ->assertRedirect();

        $this->assertNull($b2->fresh()->area_id);
    }

    public function test_manager_sync_updates_branch_user(): void
    {
        $admin = Seeds::user('super_admin');
        $old = Seeds::user('area_manager');
        $new = Seeds::user('area_manager');
        $area = Area::create(['name' => 'Delta', 'slug' => 'delta', 'manager_id' => $old->id, 'active' => true]);
        $b1 = Seeds::branch('Mansoura', ['area_id' => $area->id]);
        $b2 = Seeds::branch('Tanta', ['area_id' => $area->id]);
        $stray = Seeds::branch('Alex');
        DB::table('branch_user')->insert([
            ['user_id' => $old->id, 'branch_id' => $b1->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $old->id, 'branch_id' => $stray->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $new->id, 'branch_id' => $stray->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($admin)
            ->put('/admin/areas/'.$area->id, ['name' => 'Delta', 'manager_id' => $new->id, 'active' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($new->id, $area->fresh()->manager_id);
        // New manager's branch_user = exactly the area's branches.
        $this->assertEqualsCanonicalizing([$b1->id, $b2->id], DB::table('branch_user')->where('user_id', $new->id)->pluck('branch_id')->all());
        // Old manager no longer manages an area, so their rows are cleared.
        $this->assertSame([], DB::table('branch_user')->where('user_id', $old->id)->pluck('branch_id')->all());

        // Reassigning branches re-syncs the current manager.
        $this->actingAs($admin)
            ->post('/admin/areas/'.$area->id.'/branches', ['branch_ids' => [$b1->id, $stray->id]])
            ->assertRedirect();
        $this->assertEqualsCanonicalizing([$b1->id, $stray->id], DB::table('branch_user')->where('user_id', $new->id)->pluck('branch_id')->all());
    }

    public function test_delete_blocked_when_area_has_branches(): void
    {
        $admin = Seeds::user('super_admin');
        $area = Area::create(['name' => 'Delta', 'slug' => 'delta', 'active' => true]);
        Seeds::branch('Mansoura', ['area_id' => $area->id]);

        $this->actingAs($admin)->delete('/admin/areas/'.$area->id)->assertRedirect();
        $this->assertNotNull($area->fresh());

        $empty = Area::create(['name' => 'Empty', 'slug' => 'empty', 'active' => true]);
        $this->actingAs($admin)->delete('/admin/areas/'.$empty->id)->assertRedirect();
        $this->assertNull($empty->fresh());
    }

    public function test_non_admin_forbidden(): void
    {
        $ops = Seeds::user('ops_manager');
        $area = Area::create(['name' => 'Delta', 'slug' => 'delta', 'active' => true]);

        $this->get('/admin/areas')->assertRedirect('/login');
        $this->actingAs($ops)->get('/admin/areas')->assertForbidden();
        $this->actingAs($ops)->post('/admin/areas', ['name' => 'X'])->assertForbidden();
        $this->actingAs($ops)->put('/admin/areas/'.$area->id, ['name' => 'Y'])->assertForbidden();
        $this->actingAs($ops)->post('/admin/areas/'.$area->id.'/branches', ['branch_ids' => []])->assertForbidden();
        $this->actingAs($ops)->delete('/admin/areas/'.$area->id)->assertForbidden();
    }
}
