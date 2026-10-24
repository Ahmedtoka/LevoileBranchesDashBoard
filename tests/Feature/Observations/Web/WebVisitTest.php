<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Models\User;
use Tests\Support\Seeds;
use Tests\TestCase;

/** Task D3: the laptop visit page /observations/new and Plan B's API mounted under the web session (/web-api). */
class WebVisitTest extends TestCase
{
    private Department $cctv;

    private Branch $branch;

    private User $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cctv = Seeds::department('cctv', ['is_reporting' => true]);
        $this->branch = Seeds::branch('Saraya Mall', ['active' => true]);
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
    }

    public function test_new_page_renders_with_meta(): void
    {
        $this->actingAs($this->recorder)
            ->get('/observations/new')
            ->assertOk()
            ->assertSee('"reporting_departments"', false)
            ->assertSee('"default_department_id":'.$this->cctv->id, false)
            ->assertSee(asset('js/observations-visit.js'), false)
            ->assertSee('window.OBS_I18N', false);

        // A department that does not report cannot record.
        $maintenance = Seeds::department('maintenance', ['is_reporting' => false]);
        $outsider = Seeds::user('department_employee', ['department_id' => $maintenance->id]);

        $this->actingAs($outsider)->get('/observations/new')->assertForbidden();
    }

    public function test_web_api_routes_use_session_auth(): void
    {
        $this->getJson('/web-api/observations/meta')->assertUnauthorized();

        // Web guard session only: no Sanctum::actingAs, no bearer token.
        $this->actingAs($this->recorder)
            ->getJson('/web-api/observations/meta')
            ->assertOk()
            ->assertJsonStructure([
                'reporting_departments', 'default_department_id', 'department_id', 'risk_levels',
                'categories', 'catalog_items', 'responsible_departments', 'areas', 'branches',
            ])
            ->assertJsonPath('department_id', $this->cctv->id);

        $response = $this->actingAs($this->recorder)
            ->postJson('/web-api/observation-visits', [
                'type' => 'field',
                'branch_id' => $this->branch->id,
                'visit_date' => now()->toDateString(),
                'simulated' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('visit.source', 'web')
            ->assertJsonPath('visit.checkin_simulated', true);

        $visit = ObservationVisit::findOrFail($response->json('visit.id'));
        $this->assertSame('web', $visit->source);
        $this->assertSame((int) $this->recorder->id, (int) $visit->user_id);
        $this->assertSame((int) $this->cctv->id, (int) $visit->department_id);
    }

    public function test_desk_all_branches_forces_activity(): void
    {
        $this->actingAs($this->recorder);

        $visitId = $this->postJson('/web-api/observation-visits', [
            'type' => 'desk',
            'branch_id' => null,
            'visit_date' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('visit.branch', null)->json('visit.id');

        // An issue without a branch can never become a ticket.
        $rowId = $this->postJson("/web-api/observation-visits/{$visitId}/observations", [
            'items' => [['kind' => 'issue', 'description' => 'مراجعة كشوف الحضور لكل الفروع']],
        ])->assertOk()->json('data.0.id');

        $this->postJson("/web-api/observation-visits/{$visitId}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.branch_id']);
        $this->assertSame(0, Ticket::count());
        $this->assertSame('draft', ObservationVisit::findOrFail($visitId)->status);

        // What the page sends in "all branches" mode: the same row as an activity.
        $this->postJson("/web-api/observation-visits/{$visitId}/observations", [
            'items' => [['id' => $rowId, 'kind' => 'activity', 'description' => 'مراجعة كشوف الحضور لكل الفروع']],
        ])->assertOk()->assertJsonPath('data.0.id', $rowId)->assertJsonPath('data.0.kind', 'activity');

        $this->postJson("/web-api/observation-visits/{$visitId}/submit")
            ->assertOk()
            ->assertJsonPath('visit.status', 'submitted')
            ->assertJsonPath('created_tickets', []);

        $this->assertSame('closed_no_action', Observation::findOrFail($rowId)->status);
        $this->assertSame(1, Observation::where('observation_visit_id', $visitId)->count());
        $this->assertSame(0, Ticket::count());
    }
}
