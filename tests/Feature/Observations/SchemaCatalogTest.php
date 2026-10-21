<?php

namespace Tests\Feature\Observations;

use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use Illuminate\Database\QueryException;
use Tests\Support\Seeds;
use Tests\TestCase;

class SchemaCatalogTest extends TestCase
{
    private function category(array $attrs = []): ObservationCategory
    {
        return ObservationCategory::create(array_merge([
            'department_id' => Seeds::department('cctv')->id,
            'slug' => 'opening',
            'name_ar' => 'الفتح',
            'name_en' => 'Opening',
        ], $attrs));
    }

    public function test_unique_slug_per_department(): void
    {
        $cctv = Seeds::department('cctv');
        $finance = Seeds::department('finance');

        $this->category(['department_id' => $cctv->id, 'slug' => 'opening']);
        $this->category(['department_id' => $finance->id, 'slug' => 'opening']);

        $this->assertSame(2, ObservationCategory::where('slug', 'opening')->count());

        $this->expectException(QueryException::class);
        $this->category(['department_id' => $cctv->id, 'slug' => 'opening']);
    }

    public function test_effective_sla_falls_back_in_order(): void
    {
        $level = RiskLevel::create([
            'slug' => 'high',
            'name_ar' => 'عالي',
            'name_en' => 'High',
            'color' => '#E67E22',
            'sort_order' => 2,
            'default_sla_hours' => 48,
        ]);
        $category = $this->category(['default_sla_hours' => 20]);

        $item = ObservationCatalogItem::create([
            'category_id' => $category->id,
            'code' => 'CCTV-OPEN-LATE',
            'label_ar' => 'تأخر الفتح',
            'label_en' => 'Late opening',
            'risk_level_id' => $level->id,
            'sla_hours' => 10,
        ]);
        $this->assertSame(10, $item->effectiveSlaHours());

        $item->update(['sla_hours' => null]);
        $this->assertSame(20, $item->fresh()->effectiveSlaHours());

        $category->update(['default_sla_hours' => null]);
        $this->assertSame(48, $item->fresh()->effectiveSlaHours());
    }
}
