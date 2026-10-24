<?php

namespace Tests\Feature\Observations\Web;

use App\Models\User;
use Tests\Support\Seeds;
use Tests\TestCase;

class NavTest extends TestCase
{
    private function assertLinks(User $user, array $visible, array $hidden): void
    {
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        // '/observations' is a prefix of the other URLs, so every href is asserted with its closing quote.
        foreach (['index', 'create', 'beforeAfter', 'dashboard'] as $key) {
            $href = 'href="'.route('observations.'.$key).'"';
            in_array($key, $visible, true)
                ? $response->assertSee($href, false)
                : $response->assertDontSee($href, false);
        }

        in_array('index', $visible, true)
            ? $response->assertSee(t('obs.nav.section', 'الملاحظات'), false)
            : $response->assertDontSee('data-obs-nav', false);
    }

    public function test_super_admin_and_ops_manager_see_all_four_links(): void
    {
        $all = ['index', 'create', 'beforeAfter', 'dashboard'];
        $this->assertLinks(Seeds::user('super_admin'), $all, []);
        $this->assertLinks(Seeds::user('ops_manager'), $all, []);
    }

    public function test_store_manager_sees_register_and_before_after_only(): void
    {
        $branch = Seeds::branch('Zamalek');
        $this->assertLinks(Seeds::user('store_manager', ['branch_id' => $branch->id]), ['index', 'beforeAfter'], ['create', 'dashboard']);
    }

    public function test_reporting_department_employee_sees_register_and_new_visit(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->assertLinks(Seeds::user('department_employee', ['department_id' => $cctv->id]), ['index', 'create', 'beforeAfter'], ['dashboard']);
    }

    public function test_non_reporting_department_staff_see_no_observation_links(): void
    {
        $hr = Seeds::department('hr', ['is_reporting' => false]);
        $none = ['index', 'create', 'beforeAfter', 'dashboard'];

        $this->assertLinks(Seeds::user('department_employee', ['department_id' => $hr->id]), [], $none);
        $this->assertLinks(Seeds::user('department_manager', ['department_id' => $hr->id, 'is_department_manager' => true]), [], $none);
    }
}
