<?php

namespace App\Http\Requests\Observations;

use App\Models\ObservationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** POST/PUT /admin/observation-categories. Authorization stays in the controller (policy `manageCategory`). */
class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->route('category');
        $departmentId = (int) $this->input('department_id');

        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'name_ar' => ['required', 'string', 'max:191'],
            'name_en' => [
                'required', 'string', 'max:191',
                // The slug is derived from name_en on create and must be unique within the department.
                function (string $attribute, mixed $value, \Closure $fail) use ($category, $departmentId) {
                    if ($category instanceof ObservationCategory) {
                        return; // slug is immutable on update
                    }
                    $slug = static::slugFor((string) $value);
                    if (ObservationCategory::where('department_id', $departmentId)->where('slug', $slug)->exists()) {
                        $fail(dt('توجد فئة بنفس الاسم في هذه الإدارة.', 'A category with this name already exists in this department.'));
                    }
                },
            ],
            'default_kind' => ['required', Rule::in(ObservationCategory::KINDS)],
            'default_risk_level_id' => ['nullable', 'integer', 'exists:risk_levels,id'],
            'default_responsible_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'default_cc_department_ids' => ['nullable', 'array'],
            'default_cc_department_ids.*' => ['integer', 'exists:departments,id'],
            'default_sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public static function slugFor(string $nameEn): string
    {
        return Str::slug($nameEn, '_') ?: 'category';
    }
}
