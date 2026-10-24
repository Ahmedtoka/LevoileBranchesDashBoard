<?php

namespace App\Http\Requests\Observations\Web;

use App\Models\RiskLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /observations/{observation}/raise-risk (web). Policy `raiseRisk`: super_admin / ops_manager. */
class RaiseRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('raiseRisk', $this->route('observation'));
    }

    public function rules(): array
    {
        return [
            'risk_level_slug' => ['required', Rule::in(RiskLevel::SLUGS)],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
