<?php

namespace App\Services\Observations\Ai;

use App\Models\AiClassification;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use Illuminate\Support\Collection;

/**
 * Builds the classifier's prompt, output schema and the sanitiser of its answer (spec section 8).
 *
 * The system prompt depends only on the department's data (no timestamps, deterministic ordering)
 * so that it stays byte-identical between calls and the prompt cache keeps hitting. Anything
 * volatile (branch, date, the points) goes in the user message.
 */
class PromptBuilder
{
    public const MAX_EXAMPLES = 40;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function system(int $departmentId): string
    {
        $department = Department::find($departmentId);
        $categories = $this->categories($departmentId);
        $items = $this->items($categories->pluck('id'));

        $sections = [
            $this->rules($department?->name ?? (string) $departmentId),
            $this->section('الفئات (categories)', $categories->map(fn (ObservationCategory $c) => [
                'id' => $c->id,
                'name_ar' => $c->name_ar,
                'name_en' => $c->name_en,
                'default_kind' => $c->default_kind,
                'default_risk' => $c->riskLevel?->slug,
                'default_responsible_department_id' => $c->default_responsible_department_id,
            ])),
            $this->section('بنود الكتالوج (catalog items)', $items->map(fn (ObservationCatalogItem $i) => [
                'id' => $i->id,
                'category_id' => $i->category_id,
                'code' => $i->code,
                'label_ar' => $i->label_ar,
                'label_en' => $i->label_en,
                'keywords' => array_values((array) ($i->keywords ?? [])),
                'kind' => $i->kind,
                'risk' => $i->riskLevel?->slug,
                'responsible_department_id' => $i->responsible_department_id,
            ])),
            $this->section('درجات الريسك (risk levels)', RiskLevel::query()
                ->where('active', true)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (RiskLevel $r) => [
                    'slug' => $r->slug,
                    'name_ar' => $r->name_ar,
                    'name_en' => $r->name_en,
                    'default_sla_hours' => $r->default_sla_hours,
                ])),
            $this->section('الإدارات المسؤولة المحتملة (departments)', Department::query()
                ->orderBy('id')->get(['id', 'slug', 'name'])
                ->map(fn (Department $d) => ['id' => $d->id, 'slug' => $d->slug, 'name' => $d->name])),
            $this->section('أمثلة معتمدة من الأدمن (examples: text -> admin decision)', $this->examples($departmentId)
                ->map(fn (AiClassification $a) => ['text' => $a->input_text, 'decision' => $a->admin_final])),
        ];

        return implode("\n\n", $sections)."\n";
    }

    /**
     * The volatile part of the request: branch, today's date and one line per point.
     *
     * @param  array<int, array{0:int, 1:string}>  $points
     */
    public function user(?int $branchId, array $points): string
    {
        $branch = $branchId !== null ? Branch::find($branchId)?->branch_name : null;

        $lines = [
            'الفرع: '.($branch ?? 'غير محدد'),
            'التاريخ: '.now()->toDateString(),
            'النقط (index: النص):',
        ];
        foreach ($points as $point) {
            [$index, $text] = array_values($point);
            $lines[] = (int) $index.': '.preg_replace('/\s+/u', ' ', trim((string) $text));
        }

        return implode("\n", $lines);
    }

    /**
     * JSON schema of the structured output. The API needs an object root, so the per-point
     * results sit under "results". minimum/maximum are not supported by structured outputs,
     * so the 0..1 range of confidence is stated in its description and enforced by sanitise().
     */
    public function schema(): array
    {
        $nullableInt = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];

        return [
            'type' => 'object',
            'properties' => [
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'index' => ['type' => 'integer'],
                            'catalog_item_id' => $nullableInt,
                            'category_id' => $nullableInt,
                            'kind' => ['type' => 'string', 'enum' => Observation::KINDS],
                            'risk_level' => ['type' => 'string', 'enum' => RiskLevel::SLUGS],
                            'responsible_department_id' => $nullableInt,
                            'confidence' => ['type' => 'number', 'description' => 'between 0 and 1'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => [
                            'index', 'catalog_item_id', 'category_id', 'kind', 'risk_level',
                            'responsible_department_id', 'confidence', 'reason',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['results'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Checks the model's answer against the database: ids it is not allowed to use become null,
     * unknown enums fall back to safe defaults, and entries without an integer index are dropped.
     *
     * @return ClassificationResult[] sorted by index, one per index
     */
    public function sanitise(array $raw, int $departmentId): array
    {
        $categoryIds = array_map('intval', $this->categories($departmentId)->pluck('id')->all());
        $itemCategory = ObservationCatalogItem::query()
            ->whereIn('category_id', $categoryIds)->where('active', true)
            ->pluck('category_id', 'id')->all();
        $departmentIds = array_map('intval', Department::query()->pluck('id')->all());

        $results = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || ! is_int($entry['index'] ?? null) || isset($results[$entry['index']])) {
                continue;
            }

            $itemId = $this->intOrNull($entry['catalog_item_id'] ?? null);
            $itemId = $itemId !== null && isset($itemCategory[$itemId]) ? $itemId : null;

            $categoryId = $this->intOrNull($entry['category_id'] ?? null);
            if ($itemId !== null) {
                $categoryId = (int) $itemCategory[$itemId];
            } elseif (! in_array($categoryId, $categoryIds, true)) {
                $categoryId = null;
            }

            $responsibleId = $this->intOrNull($entry['responsible_department_id'] ?? null);
            $responsibleId = in_array($responsibleId, $departmentIds, true) ? $responsibleId : null;

            $kind = $entry['kind'] ?? null;
            $risk = $entry['risk_level'] ?? null;
            $confidence = $entry['confidence'] ?? null;

            $results[$entry['index']] = new ClassificationResult(
                index: $entry['index'],
                catalogItemId: $itemId,
                categoryId: $categoryId,
                kind: in_array($kind, Observation::KINDS, true) ? $kind : 'issue',
                riskLevelSlug: in_array($risk, RiskLevel::SLUGS, true) ? $risk : 'medium',
                responsibleDepartmentId: $responsibleId,
                confidence: is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0,
                reason: is_string($entry['reason'] ?? null) ? trim($entry['reason']) : '',
            );
        }

        ksort($results);

        return array_values($results);
    }

    private function rules(string $departmentName): string
    {
        return <<<TXT
        أنت مساعد تصنيف ملاحظات الفروع لإدارة "{$departmentName}" في شركة ملابس لها فروع.
        هيوصلك في رسالة المستخدم اسم الفرع والتاريخ وقائمة نقط حرة كتبها موظف الإدارة، كل نقطة في سطر بصيغة "index: النص".
        صنّف كل نقطة لوحدها وارجع نتيجة واحدة لكل index بنفس الرقم.

        القواعد:
        1. لو النقطة بتطابق بوضوح بند من "بنود الكتالوج" تحت، حط catalog_item_id بتاعه و category_id بتاع فئته. لو مفيش بند مطابق بوضوح، خلي catalog_item_id = null واختار أقرب فئة من "الفئات" في category_id، ولو مفيش فئة مناسبة خليه null.
        2. kind واحد من: issue (مشكلة محتاجة حل)، activity (نشاط اتعمل)، assessment (تقييم)، positive (ملاحظة إيجابية). استخدم kind البند أو default_kind الفئة إلا لو النص واضح إنه غير كده.
        3. risk_level واحد من slugs "درجات الريسك". استخدم risk البند أو default_risk الفئة إلا لو النص بيقول بوضوح إن الخطورة أعلى أو أقل.
        4. responsible_department_id = الإدارة المسؤولة عن الحل من قائمة "الإدارات المسؤولة المحتملة". استخدم قيمة البند أو الفئة إلا لو النص واضح إنها إدارة تانية. لو مش عارف خليه null.
        5. confidence رقم من 0 لـ 1 بيعبر عن ثقتك في التصنيف.
        6. reason سطر واحد قصير بالعربي يشرح سبب التصنيف.
        7. ممنوع تخترع أي id مش موجود في القوائم تحت؛ لو مش متأكد استخدم null.
        8. "الأمثلة المعتمدة" قرارات الأدمن على نقط سابقة لنفس الإدارة؛ اتبع نفس أسلوبها في التصنيف.
        كل سطر في القوائم التالية كائن JSON.
        TXT;
    }

    private function section(string $title, Collection $rows): string
    {
        $lines = $rows->map(fn (array $row) => json_encode($row, self::JSON_FLAGS))->all();

        return '## '.$title."\n".($lines === [] ? '(لا يوجد)' : implode("\n", $lines));
    }

    /** @return Collection<int, ObservationCategory> */
    private function categories(int $departmentId): Collection
    {
        return ObservationCategory::query()
            ->with('riskLevel:id,slug')
            ->where('department_id', $departmentId)->where('active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /** @return Collection<int, ObservationCatalogItem> */
    private function items(Collection $categoryIds): Collection
    {
        return ObservationCatalogItem::query()
            ->with('riskLevel:id,slug')
            ->whereIn('category_id', $categoryIds)->where('active', true)
            ->orderBy('category_id')->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /**
     * The last MAX_EXAMPLES admin-reviewed classifications of this department (through the visit's
     * department or the observation's reporting department), oldest first.
     *
     * @return Collection<int, AiClassification>
     */
    private function examples(int $departmentId): Collection
    {
        return AiClassification::query()
            ->whereNotNull('admin_final')
            ->where(fn ($q) => $q
                ->whereHas('visit', fn ($v) => $v->where('department_id', $departmentId))
                ->orWhereHas('observation', fn ($o) => $o->where('reporting_department_id', $departmentId)))
            ->orderByDesc('id')
            ->limit(self::MAX_EXAMPLES)
            ->get(['id', 'input_text', 'admin_final'])
            ->sortBy('id')
            ->values();
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
