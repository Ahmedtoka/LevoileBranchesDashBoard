<?php

namespace App\Services\Observations\Import;

use App\Models\ObservationCategory;
use Illuminate\Support\Collection;

/**
 * Workbook "Category" + finding text -> seeded category and catalog item (spec section 14). The category
 * comes from an exact name table; the item from the table too when the raw category IS an item (the
 * maintenance trades) or else from a keyword match inside the category. No match -> the department's
 * "other" category, unmatched.
 */
class CatalogMapper
{
    /** Normalised raw category => [category slug, item code|null]. */
    private const TABLE = [
        // CCTV
        'مخالفات تتعلق بالتأخير عن ميعاد فتح الفرع' => ['branch_opening', 'CCTV-OPEN-LATE'],
        'مخالفات تتعلق بمنطقة الكاشير' => ['cashier', null],
        'مخالفات تتعلق بمنطقة الفلور' => ['floor', null],
        'مخالفات تتعلق بمنطقة الفيتنج رووم' => ['fitting_room', null],
        'مخالفات تتعلق بمنطقة الفيتنج روم' => ['fitting_room', null],
        'مخالفات تتعلق بمنطقة الاستوك' => ['stockroom', 'CCTV-STOCK-TEAM-INSIDE'],
        'مخالفات تتعلق بالكاميرات والديفي أر' => ['cameras_dvr', null],
        'مخالفات تتعلق بالكاميرات والديفي ار' => ['cameras_dvr', null],
        // Stock Control
        'البضاعة التالفة' => ['damaged_goods', null],
        'الباركود وتكويد المنتجات' => ['barcode_pricing', null],
        'التعامل مع شيت check price' => ['barcode_pricing', null],
        'تنظيم المخزون داخل الفرع' => ['stockroom_organization', null],
        'جاهزية الفرع للجرد' => ['count_readiness', null],
        'الدورة المستندية (in&out)' => ['document_cycle', null],
        'الدورة المستندية' => ['document_cycle', null],
        'إجراءات استلام البضاعة' => ['goods_receiving', null],
        'اجراءات استلام البضاعة' => ['goods_receiving', null],
        'training' => ['training', null],
        'تدريب' => ['training', null],
        // Finance
        'financial compliance' => ['financial_compliance', null],
        'cash violation' => ['cash_violation', null],
        // Merchandising
        'vm' => ['vm', null],
        'vm / pricing' => ['vm', null],
        'capacity / vm' => ['vm', null],
        'quality' => ['quality', null],
        'availability' => ['availability', null],
        'pricing' => ['pricing', null],
        'storage' => ['storage', null],
        'knowledge' => ['knowledge', null],
        'delivery' => ['delivery_replenishment', null],
        'replenishment' => ['delivery_replenishment', null],
        'delivery / replenishment' => ['delivery_replenishment', null],
        'delivery / vm' => ['delivery_replenishment', null],
        // Maintenance (the raw category is the trade = the catalog item)
        'كهرباء' => ['maintenance_works', 'MTN-ELECTRICAL'],
        'نجارة' => ['maintenance_works', 'MTN-CARPENTRY'],
        'نقاشة' => ['maintenance_works', 'MTN-PAINTING'],
        'نقاشه' => ['maintenance_works', 'MTN-PAINTING'],
        'سباكة' => ['maintenance_works', 'MTN-PLUMBING'],
        'تكييف' => ['maintenance_works', 'MTN-AC'],
        'سيكوريت وابواب' => ['maintenance_works', 'MTN-DOORS'],
        'أبواب' => ['maintenance_works', 'MTN-DOORS'],
        'مكوة' => ['maintenance_works', 'MTN-STEAM-IRON'],
        'تركيبات' => ['maintenance_works', 'MTN-FIXTURES'],
        'م/ محمود' => ['maintenance_works', null],
        'أعمال صيانة' => ['maintenance_works', null],
        // HR / IT
        'operational follow-up' => ['operational_followup', null],
        // Other
        'أخري' => ['other', null],
        'أخرى' => ['other', null],
        'other' => ['other', null],
    ];

    /** @var array<int, Collection<int, ObservationCategory>> department id => categories (items loaded) */
    private array $byDepartment = [];

    /** @param  Collection<int, ObservationCategory>  $categories  with `items` loaded */
    public function __construct(Collection $categories)
    {
        foreach ($categories as $category) {
            $this->byDepartment[(int) $category->department_id][] = $category;
        }
        $this->byDepartment = array_map(fn ($list) => collect($list), $this->byDepartment);
    }

    public static function fromDatabase(): self
    {
        return new self(ObservationCategory::with(['items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->orderBy('sort_order')->get());
    }

    /**
     * @param  bool  $allowItems  false keeps the category only (assessments / positives are not catalog findings)
     * @return array{category_id:?int, catalog_item_id:?int, matched:bool}
     */
    public function match(int $departmentId, string $rawCategory, string $finding, bool $allowItems = true): array
    {
        $entry = self::TABLE[BranchMapper::normalise($rawCategory)] ?? null;
        $category = $entry ? $this->category($departmentId, $entry[0]) : null;
        $matched = $category !== null;

        $category ??= $this->category($departmentId, 'other');
        if ($category === null) {
            return ['category_id' => null, 'catalog_item_id' => null, 'matched' => false];
        }

        $itemId = null;
        if ($matched && $allowItems) {
            $itemId = $entry[1] !== null
                ? $category->items->firstWhere('code', $entry[1])?->id
                : $this->keywordMatch($category, $rawCategory.' '.$finding);
        }

        return ['category_id' => (int) $category->id, 'catalog_item_id' => $itemId !== null ? (int) $itemId : null, 'matched' => $matched];
    }

    private function category(int $departmentId, string $slug): ?ObservationCategory
    {
        return ($this->byDepartment[$departmentId] ?? collect())->first(fn (ObservationCategory $c) => $c->slug === $slug && (bool) $c->active);
    }

    /** The active item with the most keyword hits in the text (at least one); ties keep the first by sort order. */
    private function keywordMatch(ObservationCategory $category, string $text): ?int
    {
        $haystack = BranchMapper::normalise($text);
        $best = null;
        $bestScore = 0;

        foreach ($category->items as $item) {
            if (! $item->active) {
                continue;
            }
            $score = 0;
            foreach ((array) ($item->keywords ?? []) as $keyword) {
                $needle = BranchMapper::normalise((string) $keyword);
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $item;
            }
        }

        return $best?->id;
    }
}
