<?php

namespace App\Http\Requests\Observations;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/observation-visits. Authorization stays in the controller (policy `create`). */
class StoreObservationVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id', 'required_if:type,field'],
            'type' => ['required', 'in:field,desk'],
            'visit_date' => ['required', 'date'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'simulated' => ['nullable', 'boolean'],
            'source' => ['nullable', 'in:mobile,web'],
            'general_notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required_if' => 'الفرع مطلوب للزيارة الميدانية.',
            'type.in' => 'نوع الزيارة غير صحيح.',
        ];
    }
}
