<?php

namespace App\Http\Requests\Observations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /admin/risk-levels/{riskLevel}. Editable: names, color, default SLA, verification flag, active.
 * `slug`, `sort_order` and `ticket_priority` are never read from the request (spec section 10).
 */
class RiskLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:191'],
            'name_en' => ['required', 'string', 'max:191'],
            'color' => ['nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'requires_field_verification' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'default_sla_hours.min' => dt('المهلة لازم تكون ساعة واحدة على الأقل، أو اتركها فارغة لعدم وجود مهلة.', 'The SLA must be at least 1 hour, or leave it empty for no deadline.'),
            'default_sla_hours.integer' => dt('المهلة لازم تكون عدد ساعات صحيح.', 'The SLA must be a whole number of hours.'),
            'color.regex' => dt('اللون لازم يكون بصيغة #RRGGBB.', 'The color must be in #RRGGBB format.'),
        ];
    }
}
