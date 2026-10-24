<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Area;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Tests\Support\Seeds;
use Tests\TestCase;

class DemoLoginTest extends TestCase
{
    public function test_demo_users_endpoint_hidden_when_disabled(): void
    {
        config(['app.demo_login' => false]);

        $this->getJson('/api/demo-users')->assertNotFound();

        config(['app.demo_login' => true]);

        $res = $this->getJson('/api/demo-users')->assertOk();
        $res->assertJsonCount(count(DemoUsersSeeder::users()), 'data');
        $res->assertJsonStructure(['data' => [['email', 'name', 'role', 'hint']]]);
        $this->assertContains('cctv.emp@levoile.test', array_column($res->json('data'), 'email'));
    }

    public function test_quick_login_logs_in_seeded_user(): void
    {
        config(['app.demo_login' => true]);

        // Fresh DB: the user row does not exist yet, quick login creates it.
        $this->post('/login/quick', ['email' => 'cctv.emp@levoile.test'])
            ->assertRedirect(route('dashboard'));

        $user = User::where('email', 'cctv.emp@levoile.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('department_employee', $user->role->slug);
        $this->assertSame('cctv', $user->department->slug);

        // Disabled flag -> 404 and no login.
        auth()->logout();
        config(['app.demo_login' => false]);
        $this->post('/login/quick', ['email' => 'ops@levoile.test'])->assertNotFound();
        $this->assertGuest();
    }

    public function test_quick_login_refuses_unknown_email(): void
    {
        config(['app.demo_login' => true]);

        $other = Seeds::user('store_manager', ['email' => 'someone.else@levoile.test']);

        $this->from('/login')->post('/login/quick', ['email' => $other->email])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_page_shows_quick_login_card_only_when_enabled(): void
    {
        config(['app.demo_login' => false]);
        $this->get('/login')->assertOk()->assertDontSee('login/quick', false)->assertDontSee('cctv.emp@levoile.test');

        config(['app.demo_login' => true]);
        $this->get('/login')->assertOk()->assertSee('login/quick', false)->assertSee('cctv.emp@levoile.test', false);
    }

    public function test_seeder_idempotent(): void
    {
        (new DemoUsersSeeder)->run();
        $users = User::count();
        $areas = Area::count();
        $branches = Branch::count();

        (new DemoUsersSeeder)->run();

        $this->assertSame($users, User::count());
        $this->assertSame($areas, Area::count());
        $this->assertSame($branches, Branch::count());
        $this->assertSame(1, Area::where('slug', 'demo-cairo')->count());

        $zayed = Branch::where('branch_name', 'like', '%Saraya Mall')->firstOrFail();
        $stars = Branch::where('branch_name', 'like', '%City Stars Mall')->firstOrFail();
        $this->assertNotNull($zayed->area_id);
        $this->assertSame($zayed->area_id, $stars->area_id);

        $area = Area::find($zayed->area_id);
        $this->assertSame('area.cairo@levoile.test', $area->manager->email);

        $store = User::where('email', 'store.zayed@levoile.test')->firstOrFail();
        $this->assertSame($zayed->id, $store->branch_id);
        $this->assertSame($store->id, $zayed->fresh()->manager_id);

        $ops = User::where('email', 'operation.emp@levoile.test')->firstOrFail();
        $this->assertEqualsCanonicalizing([$zayed->id, $stars->id], $ops->branches()->pluck('branches.id')->all());
    }

    public function test_seeder_never_resets_passwords_or_touches_real_people_and_areas(): void
    {
        // A real world first: the two demo branches already in a real area with a real manager, Saraya with a real store manager.
        $realAreaManager = Seeds::user('area_manager', ['email' => 'real.area@levoile.com']);
        $realStore = Seeds::user('store_manager', ['email' => 'real.store@levoile.com']);
        $real = Area::create(['name' => 'Real West', 'slug' => 'real-west', 'manager_id' => $realAreaManager->id]);
        $zayed = Seeds::branch('Sheikh Zayed - Saraya Mall', ['area_id' => $real->id, 'manager_id' => $realStore->id]);
        $stars = Seeds::branch('Nasr City - City Stars Mall', ['area_id' => $real->id]);
        $untouched = fn () => [
            User::whereKey($realAreaManager->id)->first(['password', 'role_id', 'name', 'branch_id'])->toArray(),
            User::whereKey($realStore->id)->first(['password', 'role_id', 'name', 'branch_id'])->toArray(),
        ];
        $before = $untouched();

        (new DemoUsersSeeder)->run();

        // A demo user changed their password; the next run keeps it.
        $admin = User::where('email', 'admin@levoile.test')->firstOrFail();
        $admin->update(['password' => 'changed-by-owner']);
        $hash = $admin->fresh()->password;

        (new DemoUsersSeeder)->run();

        $this->assertSame($hash, User::where('email', 'admin@levoile.test')->value('password'));
        $this->assertSame($before, $untouched());
        $this->assertSame($realAreaManager->id, (int) $real->fresh()->manager_id);
        $this->assertSame($real->id, (int) $zayed->fresh()->area_id);
        $this->assertSame($real->id, (int) $stars->fresh()->area_id);
        $this->assertSame($realStore->id, (int) $zayed->fresh()->manager_id);

        // The demo people still see their branches (branch_user / branch_id), without changing the real rows.
        $am = User::where('email', 'area.cairo@levoile.test')->firstOrFail();
        $this->assertEqualsCanonicalizing([$zayed->id, $stars->id], $am->scopedBranchIds());
        $store = User::where('email', 'store.zayed@levoile.test')->firstOrFail();
        $this->assertSame([$zayed->id], $store->scopedBranchIds());
    }

    public function test_login_form_prefills_demo_credentials_only_when_enabled(): void
    {
        config(['app.demo_login' => false]);
        $this->get('/login')->assertOk()
            ->assertDontSee('admin@levoile.test')
            ->assertDontSee('value="password"', false);

        config(['app.demo_login' => true]);
        $this->get('/login')->assertOk()
            ->assertSee('value="admin@levoile.test"', false)
            ->assertSee('value="password"', false);
    }
}
