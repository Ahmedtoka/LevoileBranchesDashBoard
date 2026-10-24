<?php

namespace Tests\Feature\Observations\Web;

use Tests\Support\Seeds;
use Tests\TestCase;

class AdminNavTest extends TestCase
{
    public function test_sidebar_links_follow_the_catalog_policy(): void
    {
        $cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $hr = Seeds::department('hr', ['is_reporting' => false]);

        // super_admin: every admin link.
        $this->actingAs(Seeds::user('super_admin'))->get('/dashboard')->assertOk()
            ->assertSee('/observations/review')->assertSee('/admin/observation-categories')->assertSee('/admin/observation-catalog')
            ->assertSee('/admin/areas')->assertSee('/admin/risk-levels');

        // Manager of a reporting department: review, categories, catalog; not areas / risk levels.
        $this->actingAs(Seeds::user('department_manager', ['department_id' => $cctv->id, 'is_department_manager' => true]))->get('/dashboard')->assertOk()
            ->assertSee('/observations/review')->assertSee('/admin/observation-catalog')
            ->assertDontSee('/admin/areas')->assertDontSee('/admin/risk-levels');

        // Manager of a non-reporting department, ops manager and employees: no admin section.
        foreach ([
            Seeds::user('department_manager', ['department_id' => $hr->id, 'is_department_manager' => true]),
            Seeds::user('ops_manager'),
            Seeds::user('department_employee', ['department_id' => $cctv->id]),
        ] as $user) {
            $this->actingAs($user)->get('/dashboard')->assertOk()
                ->assertDontSee('/observations/review')->assertDontSee('/admin/observation-catalog')->assertDontSee('/admin/areas');
        }
    }
}
