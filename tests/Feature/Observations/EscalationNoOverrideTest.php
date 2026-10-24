<?php

namespace Tests\Feature\Observations;

use App\Models\ObservationCatalogItem;
use App\Models\RiskLevel;
use App\Services\Observations\ObservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Seeds;
use Tests\TestCase;

class EscalationNoOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_escalation_with_prefilled_default_risk_needs_no_reason(): void
    {
        $cctv = Seeds::department('cctv', ['name' => 'CCTV', 'ticket_prefix' => 'CCT', 'active' => true]);
        Seeds::department('operation', ['name' => 'Operation', 'ticket_prefix' => 'OPR', 'active' => true]);
        Seeds::department('lp', ['name' => 'Loss Prevention', 'ticket_prefix' => 'LSP', 'active' => true]);
        $this->seed(\Database\Seeders\ObservationSetupSeeder::class);
        $branch = Seeds::branch('Zayed Test');
        $user = Seeds::user('department_employee', ['department_id' => $cctv->id]);
        $item = ObservationCatalogItem::where('code', 'CCTV-OPEN-LATE')->firstOrFail();

        $svc = app(ObservationService::class);
        $visit = $svc->createVisit($user, ['branch_id' => $branch->id, 'type' => 'field', 'visit_date' => now()->toDateString(), 'source' => 'web']);
        $rows = $svc->upsertObservations($visit, [[
            'catalog_item_id' => $item->id,
            'kind' => 'issue',
            'description' => 'late',
            'risk_level_slug' => $item->riskLevel->slug,   // the pre-filled default (medium)
            'extra_values' => ['minutes_late' => 35],      // rule: >= 30 -> high
        ]], $user);

        $this->assertSame(RiskLevel::bySlug('high')->id, $rows->first()->risk_level_id);
        $this->assertNull($rows->first()->risk_override_reason);
    }
}
