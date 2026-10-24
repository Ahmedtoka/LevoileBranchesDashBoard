<?php

namespace App\Http\Controllers\Web\Observations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Observations\RiskLevelRequest;
use App\Models\RiskLevel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/** /admin/risk-levels: edit the 5 fixed levels (no add / delete, spec decision 4). */
class RiskLevelController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $this->authorize('manageRiskLevels', RiskLevel::class);

        $levels = RiskLevel::orderBy('sort_order')->get();

        return view('dashboard.observations.risk-levels.index', compact('levels'));
    }

    public function update(RiskLevelRequest $request, RiskLevel $riskLevel)
    {
        $this->authorize('manageRiskLevels', RiskLevel::class);
        $data = $request->validated();

        $riskLevel->update([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'color' => $data['color'] ?? $riskLevel->color,
            'default_sla_hours' => filled($data['default_sla_hours'] ?? null) ? (int) $data['default_sla_hours'] : null,
            'requires_field_verification' => $request->boolean('requires_field_verification'),
            'active' => $request->boolean('active'),
        ]);

        return back()->with('status', dt('تم تحديث درجة الخطورة.', 'Risk level updated.'));
    }
}
