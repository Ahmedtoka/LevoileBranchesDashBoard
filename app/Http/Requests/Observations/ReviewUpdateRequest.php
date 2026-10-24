<?php

namespace App\Http\Requests\Observations;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /observations/review/{observation}: correct category / risk / responsible / cc. Authorization in the controller. */
class ReviewUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', 'exists:observation_categories,id'],
            'risk_level_id' => ['nullable', 'integer', 'exists:risk_levels,id'],
            'responsible_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'cc_department_ids' => ['nullable', 'array'],
            'cc_department_ids.*' => ['integer', 'exists:departments,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
