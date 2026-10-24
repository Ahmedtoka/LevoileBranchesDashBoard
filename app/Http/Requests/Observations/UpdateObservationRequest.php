<?php

namespace App\Http\Requests\Observations;

use App\Models\Observation;
use App\Models\RiskLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/observations/{observation} and the web approval page (Plan H): the reporting department's
 * manager corrects a point before approving the visit. Only the keys present are changed; a key sent as
 * null clears it. Authorization in the controller (policy `updateClassification`); the service checks
 * that the category belongs to the visit's department.
 */
class UpdateObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'responsible_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'risk_level_slug' => ['nullable', Rule::in(RiskLevel::SLUGS)],
            'category_id' => ['nullable', 'integer', 'exists:observation_categories,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'kind' => ['nullable', Rule::in(Observation::KINDS)],
        ];
    }
}
