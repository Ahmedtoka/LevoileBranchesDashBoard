<?php

namespace App\Http\Requests\Observations;

use App\Models\AiClassification;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationVisit;
use App\Models\RiskLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST /api/observation-visits/{visit}/observations. Authorization stays in the controller (policy `update`). */
class UpsertObservationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.catalog_item_id' => ['nullable', 'integer', 'exists:observation_catalog_items,id'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:observation_categories,id'],
            'items.*.kind' => ['required', Rule::in(Observation::KINDS)],
            'items.*.risk_level_slug' => ['nullable', Rule::in(RiskLevel::SLUGS)],
            'items.*.risk_override_reason' => ['nullable', 'string', 'max:1000'],
            'items.*.responsible_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'items.*.title' => ['nullable', 'string', 'max:191'],
            'items.*.description' => ['required', 'string', 'max:5000'],
            'items.*.product_or_area' => ['nullable', 'string', 'max:191'],
            'items.*.extra_values' => ['nullable', 'array'],
            'items.*.rating' => ['nullable', 'in:good,average,poor'],
            'items.*.photos' => ['nullable', 'array'],
            'items.*.photos.*' => ['string', 'max:255'],
            'items.*.ai_classification_id' => ['nullable', 'integer', 'exists:ai_classifications,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'أضف ملاحظة واحدة على الأقل.',
            'items.*.description.required' => 'وصف الملاحظة مطلوب.',
        ];
    }

    /** Cross-field checks: the catalog item matches the category; the AI classification belongs to this visit. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $visit = $this->route('visit');
            $visitId = (int) ($visit instanceof ObservationVisit ? $visit->id : $visit);
            $items = array_values((array) $this->input('items', []));

            $catalogIds = array_filter(array_column($items, 'catalog_item_id'));
            $categoryOf = $catalogIds === [] ? [] : ObservationCatalogItem::whereIn('id', $catalogIds)->pluck('category_id', 'id')->all();
            $classificationIds = array_filter(array_column($items, 'ai_classification_id'));
            $visitOf = $classificationIds === [] ? [] : AiClassification::whereIn('id', $classificationIds)->pluck('observation_visit_id', 'id')->all();

            foreach ($items as $i => $item) {
                $catalogId = $item['catalog_item_id'] ?? null;
                $categoryId = $item['category_id'] ?? null;
                if ($catalogId && $categoryId && (int) ($categoryOf[$catalogId] ?? 0) !== (int) $categoryId) {
                    $validator->errors()->add("items.{$i}.category_id", 'الفئة لا تطابق بند الكتالوج');
                }

                $classificationId = $item['ai_classification_id'] ?? null;
                if ($classificationId && (int) ($visitOf[$classificationId] ?? 0) !== $visitId) {
                    $validator->errors()->add("items.{$i}.ai_classification_id", 'نتيجة الفحص لا تخص هذه الزيارة.');
                }
            }
        }];
    }
}
