<?php

namespace Tests\Feature\Observations;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use Database\Seeders\ObservationSetupSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Seeds;
use Tests\TestCase;

class SetupSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['cctv', 'stock_control', 'finance', 'merchandise', 'hr', 'it', 'lp', 'maintenance', 'operation', 'vm', 'warehouse'] as $slug) {
            Seeds::department($slug);
        }

        Seeds::branch('Nasr City - One', ['area' => 'Nasr City']);
        Seeds::branch('Nasr City - Two', ['area' => 'Nasr City']);
        Seeds::branch('Heliopolis - One', ['area' => 'Heliopolis']);
    }

    public function test_seeder_creates_setup(): void
    {
        $this->seed(ObservationSetupSeeder::class);

        $this->assertSame(5, RiskLevel::count());
        $this->assertTrue(Department::where('slug', 'cctv')->first()->is_reporting);

        $this->assertSame(2, Area::count());
        $nasr = Branch::where('area', 'Nasr City')->pluck('area_id')->unique();
        $this->assertCount(1, $nasr);
        $this->assertNotNull($nasr->first());
        $this->assertSame('nasr-city', Area::find($nasr->first())->slug);

        $this->assertGreaterThanOrEqual(25, ObservationCategory::count());
        $this->assertGreaterThanOrEqual(60, ObservationCatalogItem::count());

        $late = ObservationCatalogItem::where('code', 'CCTV-OPEN-LATE')->first();
        $this->assertNotNull($late);
        $this->assertSame([['field' => 'minutes_late', 'gte' => 30, 'risk' => 'high']], $late->escalation_rules);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ObservationSetupSeeder::class);
        $first = $this->snapshot();

        $this->seed(ObservationSetupSeeder::class);

        $this->assertSame($first, $this->snapshot());
    }

    private function snapshot(): array
    {
        return [
            'risk_levels' => DB::table('risk_levels')->count(),
            'areas' => DB::table('areas')->count(),
            'categories' => DB::table('observation_categories')->count(),
            'items' => DB::table('observation_catalog_items')->count(),
            'branch_areas' => DB::table('branches')->orderBy('id')->pluck('area_id', 'id')->all(),
        ];
    }
}
