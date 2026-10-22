<?php

namespace Tests\Feature\Observations;

use App\Models\RiskLevel;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaRiskAreaTest extends TestCase
{
    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('risk_levels'));
        $this->assertTrue(Schema::hasTable('areas'));
        $this->assertTrue(Schema::hasColumn('branches', 'area_id'));
        $this->assertTrue(Schema::hasColumn('departments', 'is_reporting'));
    }

    public function test_one_above_returns_next_severity(): void
    {
        foreach (RiskLevel::SLUGS as $i => $slug) {
            RiskLevel::create([
                'slug' => $slug,
                'name_ar' => $slug,
                'name_en' => ucfirst($slug),
                'color' => '#000000',
                'sort_order' => $i + 1,
            ]);
        }

        $this->assertSame('high', RiskLevel::bySlug('medium')->oneAbove()->slug);
        $this->assertNull(RiskLevel::bySlug('critical')->oneAbove());
    }
}
