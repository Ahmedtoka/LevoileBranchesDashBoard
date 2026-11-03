<?php

namespace Tests\Feature\Observations;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Seeds;
use Tests\TestCase;

/** 2026-10-28: employee / penalty / follow-up date on a point (legacy sheets' columns), over the API and the web pages. */
class EmployeePenaltyFollowUpTest extends TestCase
{
    private Department $cctv;

    private Department $operation;

    private Branch $branch;

    private User $recorder;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 10:00:00');
        config(['services.anthropic.key' => null, 'observations.manager_approval' => true]);

        $this->cctv = Seeds::department('vm', ['is_reporting' => true]);
        $this->operation = Seeds::department('operation', ['is_reporting' => true]);
        $this->branch = Seeds::branch('Saraya Mall');
        $this->recorder = Seeds::user('department_employee', ['department_id' => $this->cctv->id]);
        $this->manager = Seeds::user('department_manager', ['department_id' => $this->cctv->id, 'is_department_manager' => true]);

        foreach ([['high', 48, 'high'], ['medium', 72, 'medium'], ['low', 168, 'low']] as $i => [$slug, $sla, $priority]) {
            RiskLevel::create([
                'slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug, 'color' => '#00000'.$i, 'sort_order' => $i + 1,
                'default_sla_hours' => $sla, 'requires_field_verification' => $slug === 'high', 'ticket_priority' => $priority, 'active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function draft(): ObservationVisit
    {
        return ObservationVisit::create([
            'type' => 'field', 'department_id' => $this->cctv->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->recorder->id, 'visit_date' => now()->toDateString(), 'started_at' => now(),
            'status' => 'draft', 'source' => 'mobile',
        ]);
    }

    public function test_schema_has_the_three_columns(): void
    {
        foreach (['employee_name', 'penalty', 'follow_up_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('observations', $column), $column);
        }
    }

    public function test_upsert_stores_the_fields_and_falls_back_to_the_catalog_employee_value(): void
    {
        $category = ObservationCategory::create(['department_id' => $this->cctv->id, 'slug' => 'opening', 'name_ar' => 'فتح الفرع', 'name_en' => 'Opening', 'default_kind' => 'issue', 'active' => true]);
        $item = ObservationCatalogItem::create([
            'category_id' => $category->id, 'code' => 'CCTV-OPEN-LATE', 'label_ar' => 'تأخير عن ميعاد الفتح', 'label_en' => 'Late opening',
            'kind' => 'issue', 'risk_level_id' => RiskLevel::bySlug('medium')->id, 'responsible_department_id' => $this->operation->id,
            'extra_fields' => [['key' => 'minutes_late', 'type' => 'number'], ['key' => 'employee', 'type' => 'text']], 'active' => true,
        ]);
        $visit = $this->draft();
        Sanctum::actingAs($this->recorder);

        $response = $this->postJson("/api/observation-visits/{$visit->id}/observations", ['items' => [
            ['kind' => 'issue', 'description' => 'الكاشير سايب الدرج مفتوح', 'employee_name' => '  محمد علي ', 'penalty' => 'إنذار شفهي', 'follow_up_at' => '2026-11-02'],
            ['kind' => 'issue', 'catalog_item_id' => $item->id, 'extra_values' => ['minutes_late' => 10, 'employee' => 'أحمد سمير']],
            ['kind' => 'issue', 'description' => 'بدون موظف', 'employee_name' => '', 'penalty' => null, 'follow_up_at' => ''],
        ]])->assertOk();

        $rows = collect($response->json('data'));
        $this->assertSame('محمد علي', $rows[0]['employee_name'], 'trimmed');
        $this->assertSame('إنذار شفهي', $rows[0]['penalty']);
        $this->assertSame('2026-11-02', $rows[0]['follow_up_at']);
        $this->assertSame('أحمد سمير', $rows[1]['employee_name'], 'the catalog extra value `employee` fills employee_name');
        $this->assertNull($rows[2]['employee_name']);
        $this->assertNull($rows[2]['penalty']);
        $this->assertNull($rows[2]['follow_up_at']);

        $this->postJson("/api/observation-visits/{$visit->id}/observations", ['items' => [
            ['kind' => 'issue', 'description' => 'x', 'follow_up_at' => 'not-a-date'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.follow_up_at']);
    }

    public function test_manager_sets_penalty_and_follow_up_before_approval_on_api_and_web(): void
    {
        $visit = $this->draft();
        $visit->update(['status' => 'submitted', 'submitted_at' => now()]);
        $o = Observation::create([
            'observation_visit_id' => $visit->id, 'branch_id' => $visit->branch_id, 'reporting_department_id' => $this->cctv->id,
            'responsible_department_id' => $this->operation->id, 'kind' => 'issue', 'title' => 'نقطة', 'description' => 'نقطة',
            'event_date' => $visit->visit_date, 'status' => 'open', 'classification_status' => 'manual', 'created_by' => $this->recorder->id,
        ]);

        Sanctum::actingAs($this->manager);
        $this->putJson("/api/observations/{$o->id}", ['employee_name' => 'سارة', 'penalty' => 'خصم يوم', 'follow_up_at' => '2026-11-05'])
            ->assertOk()
            ->assertJsonPath('observation.employee_name', 'سارة')
            ->assertJsonPath('observation.penalty', 'خصم يوم')
            ->assertJsonPath('observation.follow_up_at', '2026-11-05');

        // Clearing: null / empty string remove the value; keys not sent are untouched.
        $this->putJson("/api/observations/{$o->id}", ['penalty' => ''])->assertOk()->assertJsonPath('observation.penalty', null)
            ->assertJsonPath('observation.employee_name', 'سارة');

        // The web approval page writes the same fields through the same request class.
        $this->actingAs($this->manager)
            ->put(route('obs.approval.observation', [$visit, $o]), ['penalty' => 'إنذار كتابي', 'follow_up_at' => '2026-11-10'])
            ->assertRedirect();
        $o->refresh();
        $this->assertSame('إنذار كتابي', $o->penalty);
        $this->assertSame('2026-11-10', $o->follow_up_at->toDateString());

        $this->actingAs($this->manager)->get(route('obs.approval.show', $visit))->assertOk()
            ->assertSee('name="penalty"', false)->assertSee('name="follow_up_at"', false)->assertSee('name="employee_name"', false);

        // Detail page and register show / filter the employee.
        $this->actingAs($this->manager)->get(route('observations.show', $o))->assertOk()->assertSee('سارة')->assertSee('إنذار كتابي')->assertSee('2026-11-10');
        $this->actingAs($this->manager)->get(route('observations.index', ['range' => 'all', 'employee' => 'سار']))->assertOk()->assertSee($o->reference);
        $this->actingAs($this->manager)->get(route('observations.index', ['range' => 'all', 'employee' => 'غير موجود']))->assertOk()->assertDontSee($o->reference);
    }
}
