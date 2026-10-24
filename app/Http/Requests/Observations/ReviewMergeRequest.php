<?php

namespace App\Http\Requests\Observations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /observations/review/{observation}/merge and POST /observations/review/bulk-merge.
 * Authorization in the controller.
 */
class ReviewMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bulk = $this->routeIs('obs.review.bulk');

        return [
            'catalog_item_id' => ['required', 'integer', 'exists:observation_catalog_items,id'],
            'risk_level_id' => ['nullable', 'integer', 'exists:risk_levels,id'],
            'responsible_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'observation_ids' => [$bulk ? 'required' : 'nullable', 'array', 'min:1', 'max:200'],
            'observation_ids.*' => ['integer', 'exists:observations,id'],
        ];
    }
}
