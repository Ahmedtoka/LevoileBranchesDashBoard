<?php

namespace App\Http\Requests\Observations;

use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST/PUT /admin/observation-catalog. Authorization stays in the controller (policy `manageCategory`
 * on the category's department).
 *
 * Form shape (no raw JSON in the UI):
 * - keywords: comma-separated string -> array
 * - extra_fields[i][key|label_ar|label_en|type|options|required] -> [{key,label_ar,label_en,type,options|null,required}]
 * - escalation_rules[i][field|op|value|risk] -> [{field, <op>: value, risk}] (the shape RiskResolver reads)
 * Blank rows (no key / no field) are dropped.
 */
class CatalogItemRequest extends FormRequest
{
    public const FIELD_TYPES = ['number', 'text', 'employee', 'select'];

    public const RULE_OPS = ['gte', 'lte', 'eq'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'extra_fields' => $this->dropBlankRows((array) $this->input('extra_fields', []), 'key'),
            'escalation_rules' => $this->dropBlankRows((array) $this->input('escalation_rules', []), 'field'),
        ]);
    }

    public function rules(): array
    {
        $item = $this->route('item');

        return [
            'category_id' => ['required', 'integer', 'exists:observation_categories,id'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_\-]*$/',
                Rule::unique('observation_catalog_items', 'code')->ignore($item instanceof ObservationCatalogItem ? $item->id : null)],
            'label_ar' => ['required', 'string', 'max:191'],
            'label_en' => ['required', 'string', 'max:191'],
            'keywords' => ['nullable', 'string', 'max:2000'],
            'kind' => ['required', Rule::in(ObservationCategory::KINDS)],
            'risk_level_id' => ['nullable', 'integer', 'exists:risk_levels,id'],
            'responsible_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'cc_department_ids' => ['nullable', 'array'],
            'cc_department_ids.*' => ['integer', 'exists:departments,id'],
            'sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],

            'extra_fields' => ['nullable', 'array', 'max:20'],
            'extra_fields.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'extra_fields.*.label_ar' => ['required', 'string', 'max:191'],
            'extra_fields.*.label_en' => ['required', 'string', 'max:191'],
            'extra_fields.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'extra_fields.*.options' => ['nullable', 'string', 'max:1000', 'required_if:extra_fields.*.type,select'],
            'extra_fields.*.required' => ['nullable', 'boolean'],

            'escalation_rules' => ['nullable', 'array', 'max:10'],
            'escalation_rules.*.field' => ['required', 'string', 'max:40'],
            'escalation_rules.*.op' => ['required', Rule::in(self::RULE_OPS)],
            'escalation_rules.*.value' => ['required', 'numeric'],
            'escalation_rules.*.risk' => ['required', Rule::in(RiskLevel::SLUGS)],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => dt('الكود حروف إنجليزية كبيرة وأرقام وشرطات فقط، مثل CCTV-OPEN-LATE.', 'The code may only contain capital letters, digits, dashes and underscores, e.g. CCTV-OPEN-LATE.'),
            'code.unique' => dt('هذا الكود مستخدم لبند آخر.', 'This code is already used by another item.'),
            'extra_fields.*.key.regex' => dt('مفتاح الحقل بحروف إنجليزية صغيرة وأرقام و_ فقط.', 'The field key may only contain lowercase letters, digits and underscores.'),
            'extra_fields.*.key.distinct' => dt('مفتاح الحقل مكرر.', 'Duplicate field key.'),
            'extra_fields.*.options.required_if' => dt('حقل الاختيار يحتاج قائمة خيارات مفصولة بفواصل.', 'A select field needs a comma-separated options list.'),
            'escalation_rules.*.value.numeric' => dt('قيمة القاعدة لازم تكون رقم.', 'The rule value must be a number.'),
        ];
    }

    /** Validated data converted to the stored shapes. */
    public function itemAttributes(): array
    {
        $data = $this->validated();

        $keywords = array_values(array_filter(array_map('trim', preg_split('/[,\n،]+/u', (string) ($data['keywords'] ?? ''))), fn ($k) => $k !== ''));
        $cc = array_values(array_unique(array_map('intval', (array) ($data['cc_department_ids'] ?? []))));

        $fields = [];
        foreach ((array) ($data['extra_fields'] ?? []) as $row) {
            $options = null;
            if (($row['type'] ?? null) === 'select') {
                $options = array_values(array_filter(array_map('trim', preg_split('/[,\n،]+/u', (string) ($row['options'] ?? ''))), fn ($o) => $o !== ''));
            }
            $fields[] = [
                'key' => $row['key'],
                'label_ar' => $row['label_ar'],
                'label_en' => $row['label_en'],
                'type' => $row['type'],
                'options' => $options,
                'required' => filter_var($row['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        $rules = [];
        foreach ((array) ($data['escalation_rules'] ?? []) as $row) {
            $rules[] = ['field' => $row['field'], $row['op'] => $row['value'] + 0, 'risk' => $row['risk']]; // + 0: int when integral, float otherwise
        }

        return [
            'category_id' => (int) $data['category_id'],
            'code' => $data['code'],
            'label_ar' => $data['label_ar'],
            'label_en' => $data['label_en'],
            'keywords' => $keywords,
            'kind' => $data['kind'],
            'risk_level_id' => $data['risk_level_id'] ?? null,
            'responsible_department_id' => $data['responsible_department_id'] ?? null,
            'cc_department_ids' => $cc === [] ? null : $cc,
            'sla_hours' => filled($data['sla_hours'] ?? null) ? (int) $data['sla_hours'] : null,
            'extra_fields' => $fields,
            'escalation_rules' => $rules === [] ? null : $rules,
            'active' => $this->has('active') ? $this->boolean('active') : $this->isMethod('post'),
        ];
    }

    private function dropBlankRows(array $rows, string $requiredKey): array
    {
        return array_values(array_filter($rows, fn ($row) => is_array($row) && trim((string) ($row[$requiredKey] ?? '')) !== ''));
    }
}
