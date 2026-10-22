<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\RiskLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Observations module setup: risk levels, reporting departments, areas and the initial catalog.
 * Idempotent (updateOrCreate on slug/code); never deletes or truncates. Run only with --class=.
 */
class ObservationSetupSeeder extends Seeder
{
    private const REPORTING_DEPARTMENTS = [
        'cctv', 'stock_control', 'finance', 'merchandise', 'hr', 'it', 'lp', 'maintenance', 'operation', 'vm',
    ];

    private const RISK_LEVELS = [
        ['slug' => 'critical', 'name_ar' => 'حرج', 'name_en' => 'Critical', 'color' => '#b3261e', 'sort_order' => 1, 'default_sla_hours' => 24, 'requires_field_verification' => true, 'ticket_priority' => 'critical'],
        ['slug' => 'high', 'name_ar' => 'عالي', 'name_en' => 'High', 'color' => '#d9480f', 'sort_order' => 2, 'default_sla_hours' => 48, 'requires_field_verification' => true, 'ticket_priority' => 'high'],
        ['slug' => 'medium', 'name_ar' => 'متوسط', 'name_en' => 'Medium', 'color' => '#c58a00', 'sort_order' => 3, 'default_sla_hours' => 72, 'requires_field_verification' => false, 'ticket_priority' => 'medium'],
        ['slug' => 'low', 'name_ar' => 'منخفض', 'name_en' => 'Low', 'color' => '#2f7d4f', 'sort_order' => 4, 'default_sla_hours' => 168, 'requires_field_verification' => false, 'ticket_priority' => 'low'],
        ['slug' => 'none', 'name_ar' => 'بدون', 'name_en' => 'None', 'color' => '#7a7a7a', 'sort_order' => 5, 'default_sla_hours' => null, 'requires_field_verification' => false, 'ticket_priority' => null],
    ];

    /** @var array<string, int> department slug => id */
    private array $departmentIds = [];

    /** @var array<string, int> risk slug => id */
    private array $riskIds = [];

    public function run(): void
    {
        $this->seedRiskLevels();
        $this->markReportingDepartments();
        $this->seedAreas();
        $this->seedCatalog();
    }

    private function seedRiskLevels(): void
    {
        foreach (self::RISK_LEVELS as $level) {
            RiskLevel::updateOrCreate(['slug' => $level['slug']], $level + ['active' => true]);
        }

        $this->command?->info('Risk levels: '.RiskLevel::count());
    }

    private function markReportingDepartments(): void
    {
        foreach (self::REPORTING_DEPARTMENTS as $slug) {
            $department = Department::where('slug', $slug)->first();

            if (! $department) {
                $this->command?->warn("Department [{$slug}] not found; skipped marking it as reporting.");

                continue;
            }

            $department->update(['is_reporting' => true]);
        }
    }

    private function seedAreas(): void
    {
        $values = Branch::query()
            ->whereNotNull('area')
            ->where('area', '!=', '')
            ->distinct()
            ->pluck('area');

        foreach ($values as $value) {
            $name = trim($value);
            $slug = Str::slug($name);

            if ($name === '' || $slug === '') {
                $this->command?->warn("Branch area [{$value}] has no usable slug; skipped.");

                continue;
            }

            $area = Area::updateOrCreate(['slug' => $slug], ['name' => $name]);

            // Only fill branches that have no area yet, so an admin's later assignment is kept.
            Branch::where('area', $value)->whereNull('area_id')->update(['area_id' => $area->id]);
        }

        $this->command?->info('Areas: '.Area::count());
    }

    private function seedCatalog(): void
    {
        $path = database_path('data/observation_catalog.json');
        $entries = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->departmentIds = Department::pluck('id', 'slug')->all();
        $this->riskIds = RiskLevel::pluck('id', 'slug')->all();

        DB::transaction(function () use ($entries) {
            $itemPosition = 0;

            foreach ($entries as $index => $entry) {
                $departmentId = $this->departmentIds[$entry['department']] ?? null;
                $cat = $entry['category'];

                if (! $departmentId) {
                    $this->command?->warn("Department [{$entry['department']}] not found; skipped category [{$cat['slug']}] and its items.");

                    continue;
                }

                $category = ObservationCategory::updateOrCreate(
                    ['department_id' => $departmentId, 'slug' => $cat['slug']],
                    [
                        'name_ar' => $cat['name_ar'],
                        'name_en' => $cat['name_en'],
                        'default_kind' => $cat['default_kind'],
                        'default_risk_level_id' => $this->riskId($cat['risk'] ?? null),
                        'default_responsible_department_id' => $this->departmentId($cat['responsible'] ?? null),
                        'default_cc_department_ids' => $this->departmentIdList($cat['cc'] ?? []),
                        'default_sla_hours' => $cat['sla_hours'] ?? null,
                        'sort_order' => $index + 1,
                    ],
                );

                foreach ($entry['items'] as $item) {
                    $itemPosition++;

                    ObservationCatalogItem::updateOrCreate(
                        ['code' => $item['code']],
                        [
                            'category_id' => $category->id,
                            'label_ar' => $item['label_ar'],
                            'label_en' => $item['label_en'],
                            'keywords' => $item['keywords'] ?? [],
                            'kind' => $item['kind'],
                            'risk_level_id' => $this->riskId($item['risk'] ?? null),
                            'responsible_department_id' => $this->departmentId($item['responsible'] ?? null),
                            'cc_department_ids' => $this->departmentIdList($item['cc'] ?? []),
                            'sla_hours' => $item['sla_hours'] ?? null,
                            'extra_fields' => $item['extra_fields'] ?? [],
                            'escalation_rules' => $item['escalation_rules'] ?? null,
                            'sort_order' => $itemPosition,
                        ],
                    );
                }
            }
        });

        $this->command?->info('Observation categories: '.ObservationCategory::count().', catalog items: '.ObservationCatalogItem::count());
    }

    private function riskId(?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }

        if (! isset($this->riskIds[$slug])) {
            $this->command?->warn("Risk level [{$slug}] not found; left empty.");

            return null;
        }

        return $this->riskIds[$slug];
    }

    private function departmentId(?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }

        if (! isset($this->departmentIds[$slug])) {
            $this->command?->warn("Department [{$slug}] not found; responsible department left empty.");

            return null;
        }

        return $this->departmentIds[$slug];
    }

    /** @return list<int>|null */
    private function departmentIdList(array $slugs): ?array
    {
        $ids = [];

        foreach ($slugs as $slug) {
            if (! isset($this->departmentIds[$slug])) {
                $this->command?->warn("Department [{$slug}] not found; dropped from cc.");

                continue;
            }

            $ids[] = $this->departmentIds[$slug];
        }

        return $ids === [] ? null : $ids;
    }
}
