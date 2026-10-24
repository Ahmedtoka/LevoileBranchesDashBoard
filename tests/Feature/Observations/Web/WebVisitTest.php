<?php

namespace Tests\Feature\Observations\Web;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /** What the page relies on for row edits / deletes: an emptied saved row is a 422 (not ignored); DELETE only on drafts. */
    public function test_web_api_row_delete_and_emptied_row(): void
    {
        $this->actingAs($this->recorder);

        $visitId = $this->postJson('/web-api/observation-visits', [
            'type' => 'field', 'branch_id' => $this->branch->id, 'visit_date' => now()->toDateString(), 'simulated' => true,
        ])->assertCreated()->json('visit.id');
        [$keepId, $dropId] = $this->postJson("/web-api/observation-visits/{$visitId}/observations", [
            'items' => [['kind' => 'activity', 'description' => 'يبقى'], ['kind' => 'activity', 'description' => 'يُحذف']],
        ])->assertOk()->json('data.*.id');

        // A saved row sent emptied is rejected on that row, and the old text is not silently kept as valid.
        $this->postJson("/web-api/observation-visits/{$visitId}/observations", [
            'items' => [['id' => $keepId, 'kind' => 'activity', 'description' => null]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.description']);

        $this->deleteJson("/web-api/observations/{$dropId}")->assertOk();
        $this->assertNull(Observation::find($dropId));
        $this->deleteJson("/web-api/observations/{$dropId}")->assertNotFound();

        $this->postJson("/web-api/observation-visits/{$visitId}/submit")->assertOk();
        $this->deleteJson("/web-api/observations/{$keepId}")->assertForbidden();
        $this->assertNotNull(Observation::find($keepId));
    }

    public function test_desk_all_branches_forces_activity(): void
    {
        // The ticketability check (branch required for an issue) runs where tickets are created: at submit with
        // the manager-approval gate off (Plan H), otherwise at approveVisit. This test keeps the pre-Plan-H path.
        config(['observations.manager_approval' => false]);
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
            ->assertJsonPath('visit.status', 'approved')
            ->assertJsonPath('created_tickets', []);

        $this->assertSame('closed_no_action', Observation::findOrFail($rowId)->status);
        $this->assertSame(1, Observation::where('observation_visit_id', $visitId)->count());
        $this->assertSame(0, Ticket::count());
    }

    private function draftVisit(User $owner, array $attrs = []): ObservationVisit
    {
        return ObservationVisit::create(array_merge([
            'type' => 'field', 'department_id' => $this->cctv->id, 'branch_id' => $this->branch->id,
            'user_id' => $owner->id, 'visit_date' => now()->toDateString(), 'status' => 'draft', 'source' => 'web',
        ], $attrs));
    }

    public function test_upsert_photo_paths_must_be_uploads_of_the_callers_visits(): void
    {
        Storage::fake('public');
        $this->actingAs($this->recorder);
        $visitId = $this->postJson('/web-api/observation-visits', [
            'type' => 'field', 'branch_id' => $this->branch->id, 'visit_date' => now()->toDateString(), 'simulated' => true,
        ])->assertCreated()->json('visit.id');
        $upsert = fn (array $photos) => $this->postJson("/web-api/observation-visits/{$visitId}/observations", [
            'items' => [['kind' => 'activity', 'description' => 'صورة', 'photos' => $photos]],
        ]);

        $upsert(['http://evil/x.png'])->assertStatus(422)->assertJsonValidationErrors(['items.0.photos.0']);
        $upsert(['evidence/observations/'.$visitId.'/../../x.png'])->assertStatus(422);

        $other = $this->draftVisit(Seeds::user('department_employee', ['department_id' => $this->cctv->id]));
        $upsert(["evidence/observations/{$other->id}/stolen.jpg"])->assertStatus(422)->assertJsonValidationErrors(['items.0.photos.0']);

        $path = $this->post("/web-api/observation-visits/{$visitId}/photos", ['photo' => UploadedFile::fake()->image('shelf.jpg'), 'kind' => 'before'],
            ['Accept' => 'application/json'])->assertOk()->json('path');
        Storage::disk('public')->assertExists($path);
        $upsert([$path])->assertOk()->assertJsonPath('data.0.photos.0.path', $path);

        // The same upload reused for the caller's other (desk-multi) visit is accepted.
        $second = $this->draftVisit($this->recorder, ['type' => 'desk', 'branch_id' => Seeds::branch('Second', ['active' => true])->id]);
        $this->postJson("/web-api/observation-visits/{$second->id}/observations", [
            'items' => [['kind' => 'activity', 'description' => 'صورة', 'photos' => [$path]]],
        ])->assertOk();
    }

    public function test_new_page_lists_own_drafts_and_resumes_only_own_drafts(): void
    {
        $mine = $this->draftVisit($this->recorder);
        $submitted = $this->draftVisit($this->recorder, ['status' => 'submitted']);
        $theirs = $this->draftVisit(Seeds::user('department_employee', ['department_id' => $this->cctv->id]));

        $this->actingAs($this->recorder)->get('/observations/new')->assertOk()
            ->assertSee('مسوداتي')
            ->assertSee($mine->code)
            ->assertSee(route('observations.create', ['visit' => $mine->id]), false)
            ->assertDontSee($theirs->code)
            ->assertDontSee($submitted->code)
            ->assertSee('"resumeVisitId":null', false);

        $this->actingAs($this->recorder)->get(route('observations.create', ['visit' => $mine->id]))->assertOk()
            ->assertSee('"resumeVisitId":'.$mine->id, false);
        $this->actingAs($this->recorder)->get(route('observations.create', ['visit' => $theirs->id]))->assertForbidden();
        $this->actingAs($this->recorder)->get(route('observations.create', ['visit' => $submitted->id]))->assertForbidden();
        $this->actingAs($this->recorder)->get(route('observations.create', ['visit' => 'abc']))->assertNotFound();

        // What the page loads to hydrate the draft.
        $this->actingAs($this->recorder)->getJson("/web-api/observation-visits/{$mine->id}")->assertOk()
            ->assertJsonPath('visit.status', 'draft')->assertJsonPath('visit.type', 'field')
            ->assertJsonPath('visit.branch.id', $this->branch->id);
    }

    public function test_visit_page_uses_a_modal_not_confirm(): void
    {
        $this->actingAs($this->recorder)->get('/observations/new')->assertOk()
            ->assertSee('obs-submit-modal', false)->assertSee('obs-submit-confirm', false);
        $this->assertStringNotContainsString('confirm(', file_get_contents(public_path('js/observations-visit.js')));
    }
}
