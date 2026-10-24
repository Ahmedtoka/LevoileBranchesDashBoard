<?php

namespace App\Http\Requests\Observations;

use Illuminate\Foundation\Http\FormRequest;

/** POST/PUT /admin/areas. Authorization stays in the controller (policy `manageAreas`). */
class AreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ];
    }
}
