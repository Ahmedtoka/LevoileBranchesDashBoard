<?php

namespace Tests\Feature\Observations;

use App\Models\AiClassification;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use App\Services\Observations\ObservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Seeds;
use Tests\TestCase;

/** 2026-10-29: a free point that keeps the agent's risk level needs no hand-written override reason. */
class AgentReasonOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_suggested_level_is_its_own_override_reason(): void
    {
        $cctv = Seeds::department('cctv', ['name' => 'CCTV', 'ticket_prefix' => 'CCT', 'active' => true]);
        Seeds::department('operation', ['name' => 'Operation', 'ticket_prefix' => 'OPR', 'active' => true]);
        Seeds::department('lp', ['name' => 'Loss Prevention', 'ticket_prefix' => 'LSP', 'active' => true]);
        $this->seed(\Database\Seeders\ObservationSetupSeeder::class);
        $branch = Seeds::branch('Zayed Test');
        $user = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        // The CCTV "الكاشير" category defaults to high; the agent proposes medium.
        $cashier = ObservationCategory::where('department_id', $cctv->id)->where('slug', 'cashier')->firstOrFail();
        $this->assertNotSame(RiskLevel::bySlug('medium')->id, $cashier->default_risk_level_id);

        $svc = app(ObservationService::class);
        $visit = $svc->createVisit($user, ['branch_id' => $branch->id, 'type' => 'field', 'visit_date' => now()->toDateString(), 'source' => 'mobile']);
        $classification = AiClassification::create([
            'observation_visit_id' => $visit->id, 'request_hash' => sha1('x'), 'input_text' => 'الدرج اتقفل لوحده',
            'status' => 'ok', 'model' => 'test',
            'suggested' => ['index' => 0, 'catalog_item_id' => null, 'category_id' => $cashier->id, 'kind' => 'issue', 'risk_level' => 'medium',
                'responsible_department_id' => null, 'confidence' => 0.35, 'reason' => 'النص غامض'],
        ]);

        $point = ['kind' => 'issue', 'description' => 'الدرج اتقفل لوحده', 'category_id' => $cashier->id, 'risk_level_slug' => 'medium'];

        // Without the classification the old rule still asks for a reason.
        try {
            $svc->upsertObservations($visit, [$point], $user);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertNotEmpty(array_filter(array_keys($e->errors()), fn ($k) => str_ends_with($k, 'risk_override_reason')), json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        // With it, the agent's reason is recorded as the override reason.
        $rows = $svc->upsertObservations($visit, [$point + ['ai_classification_id' => $classification->id]], $user);
        $this->assertSame(RiskLevel::bySlug('medium')->id, $rows->first()->risk_level_id);
        $this->assertMatchesRegularExpression('/اقتراح الإيجينت|Agent suggestion/u', (string) $rows->first()->risk_override_reason);
        $this->assertStringContainsString('النص غامض', (string) $rows->first()->risk_override_reason);

        // A level the agent did not propose is still a manual override and needs a reason.
        try {
            $svc->upsertObservations($visit, [array_merge($point, ['id' => $rows->first()->id, 'risk_level_slug' => 'low', 'ai_classification_id' => $classification->id])], $user);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertNotEmpty(array_filter(array_keys($e->errors()), fn ($k) => str_ends_with($k, 'risk_override_reason')), json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }
}
