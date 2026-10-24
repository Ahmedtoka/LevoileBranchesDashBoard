<?php

namespace App\Support;

use App\Models\AiClassification;
use App\Models\Department;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;

/**
 * Turns one ai_classifications row (suggested → employee_final → admin_final) into display rows,
 * resolving the stored ids / slugs to names with one query per table.
 */
class ClassificationPresenter
{
    private const COLUMNS = ['suggested', 'employee_final', 'admin_final'];

    /**
     * @return list<array{key:string, title:string, rows:?array<string,string>}> rows is null when that stage has no data
     */
    public static function columns(?AiClassification $classification): array
    {
        $payloads = [];
        foreach (self::COLUMNS as $column) {
            $value = $classification?->{$column};
            $payloads[$column] = is_array($value) && $value !== [] ? $value : null;
        }

        $collect = fn (string $key) => collect($payloads)->filter()->pluck($key)->filter(fn ($v) => $v !== null && $v !== '')->unique()->values();

        $items = ObservationCatalogItem::whereIn('id', $collect('catalog_item_id'))->get()->keyBy('id');
        $categories = ObservationCategory::whereIn('id', $collect('category_id'))->get()->keyBy('id');
        $departments = Department::whereIn('id', $collect('responsible_department_id'))->pluck('name', 'id');
        $risks = RiskLevel::whereIn('slug', $collect('risk_level'))->orWhereIn('id', $collect('risk_level_id'))->get();

        $titles = [
            'suggested' => dt('اقتراح الإيجينت', 'Agent suggestion'),
            'employee_final' => dt('تأكيد الموظف', 'Employee confirmation'),
            'admin_final' => dt('اعتماد الأدمن', 'Admin approval'),
        ];

        $columns = [];
        foreach (self::COLUMNS as $column) {
            $p = $payloads[$column];
            $columns[] = [
                'key' => $column,
                'title' => $titles[$column],
                'rows' => $p === null ? null : self::rows($p, $items, $categories, $departments, $risks),
            ];
        }

        return $columns;
    }

    private static function rows(array $p, $items, $categories, $departments, $risks): array
    {
        $item = isset($p['catalog_item_id']) ? $items->get($p['catalog_item_id']) : null;
        $category = isset($p['category_id']) ? $categories->get($p['category_id']) : null;
        $risk = isset($p['risk_level'])
            ? $risks->firstWhere('slug', $p['risk_level'])
            : (isset($p['risk_level_id']) ? $risks->firstWhere('id', $p['risk_level_id']) : null);
        $riskFallback = $p['risk_level'] ?? (isset($p['risk_level_id']) ? '#'.$p['risk_level_id'] : null);
        $departmentId = $p['responsible_department_id'] ?? null;

        $rows = [
            dt('البند', 'Catalog item') => $item ? dt($item->label_ar, $item->label_en ?: $item->label_ar) : self::fallback($p['catalog_item_id'] ?? null),
            dt('الفئة', 'Category') => $category ? dt($category->name_ar, $category->name_en ?: $category->name_ar) : self::fallback($p['category_id'] ?? null),
            dt('النوع', 'Kind') => isset($p['kind']) && $p['kind'] !== '' ? ObservationLabels::kind((string) $p['kind']) : '—',
            dt('درجة الخطورة', 'Risk') => $risk ? dt($risk->name_ar, $risk->name_en) : ($riskFallback ?? '—'),
            dt('الإدارة المسؤولة', 'Responsible department') => $departmentId !== null ? ($departments[$departmentId] ?? '#'.$departmentId) : '—',
        ];

        if (array_key_exists('confidence', $p) && is_numeric($p['confidence'])) {
            $rows[dt('الثقة', 'Confidence')] = round((float) $p['confidence'] * 100).'%';
        }
        if (! empty($p['reason'])) {
            $rows[dt('السبب', 'Reason')] = (string) $p['reason'];
        }

        return $rows;
    }

    private static function fallback(mixed $id): string
    {
        return $id === null || $id === '' ? '—' : '#'.$id;
    }
}
