<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCategory;
use App\Models\User;
use App\Services\Cctv\CctvSheetMapper;
use App\Services\Observations\ObservationService;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The CCTV department's own pages (owner request 2026-11-03): who may see and record, the base query over
 * the cctv register, and the two pivots of the weekly sheet ("By Store" and "By EMP").
 */
class CctvReport
{
    public static function department(): ?Department
    {
        return Department::where('slug', 'cctv')->first();
    }

    /** Management roles and the CCTV department's own staff. */
    public static function canView(User $u): bool
    {
        $cctv = self::department();

        return $cctv !== null && $u->can('viewAny', Observation::class)
            && ($u->hasRole(ObservationService::ALL_DEPARTMENTS_ROLES) || (int) $u->department_id === $cctv->id);
    }

    public static function canRecord(User $u): bool
    {
        $cctv = self::department();

        return $cctv !== null && app(ObservationService::class)->reportingDepartmentsFor($u)->contains('id', $cctv->id);
    }

    /** CCTV violations the user may see, of submitted or approved visits, between two dates (inclusive). */
    public static function query(User $u, ?Carbon $from = null, ?Carbon $to = null, ?int $branchId = null): Builder
    {
        return ObservationScope::apply(Observation::query(), $u)
            ->where('observations.reporting_department_id', self::department()?->id ?? 0)
            ->whereIn('observations.observation_visit_id', ObservationFilters::submittedVisitIds())
            ->when($from, fn ($q) => $q->where('observations.event_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('observations.event_date', '<=', $to->toDateString()))
            ->when($branchId, fn ($q) => $q->where('observations.branch_id', $branchId));
    }

    /** The cctv categories in order, labelled like the sheet's columns ("منطقة الفلور" ...). */
    public static function categories(): Collection
    {
        $labels = [];
        foreach (CctvSheetMapper::CATEGORIES as $sheet => $slug) {
            $labels[$slug] = preg_replace('/^مخالفات تتعلق ب/u', '', $sheet) ?? $sheet;
        }

        return ObservationCategory::where('department_id', self::department()?->id ?? 0)->where('active', true)
            ->orderBy('sort_order')->orderBy('id')->get()
            ->each(fn ($c) => $c->setAttribute('sheet_label', $labels[$c->slug] ?? $c->name_ar)
                ->setAttribute('sheet_title', isset($labels[$c->slug]) ? array_search($c->slug, CctvSheetMapper::CATEGORIES, true) : $c->name_ar));
    }

    /** The short store name of the sheet ("City Stars") for a branch, else the system name. */
    public static function storeName(?string $branchName): string
    {
        static $short = null;
        $short ??= array_flip(CctvSheetMapper::BRANCHES);

        if ($branchName === null) {
            return dt('بدون فرع', 'No branch');
        }

        // Branches the sheets never named: the part after "Area - " ("Alexandria - El Ekbal" -> "El Ekbal").
        return $short[$branchName] ?? (str_contains($branchName, ' - ') ? trim(substr($branchName, strrpos($branchName, ' - ') + 3)) : $branchName);
    }

    /**
     * "By Store": one row per branch, one column per category, Grand Total row and column.
     *
     * @return array{columns: Collection, rows: list<array{branch_id:int, name:string, cells:array<int,int>, total:int}>, totals: array<int,int>, total: int}
     */
    public static function byStore(Builder $base): array
    {
        $columns = self::categories();
        $counts = (clone $base)->leftJoin('branches', 'branches.id', '=', 'observations.branch_id')
            ->selectRaw('observations.branch_id, branches.branch_name, observations.category_id, COUNT(*) AS n')
            ->groupBy('observations.branch_id', 'branches.branch_name', 'observations.category_id')
            ->toBase()->get();

        $rows = [];
        foreach ($counts->groupBy('branch_id') as $branchId => $cells) {
            $byCategory = $cells->pluck('n', 'category_id')->map(fn ($n) => (int) $n)->all();
            $rows[] = ['branch_id' => (int) $branchId, 'name' => self::storeName($cells->first()->branch_name), 'cells' => $byCategory, 'total' => array_sum($byCategory)];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return ['columns' => self::usedColumns($columns, $counts)] + self::totals($rows);
    }

    /**
     * "By EMP": branches with their subtotal, the employees of each under it; points without an employee are
     * one "(بدون اسم)" line per branch, as the sheet's "(blank)".
     *
     * @return array{columns: Collection, branches: list<array>, totals: array<int,int>, total: int}
     */
    public static function byEmployee(Builder $base): array
    {
        $columns = self::categories();
        $counts = (clone $base)->leftJoin('branches', 'branches.id', '=', 'observations.branch_id')
            ->leftJoin('employees', 'employees.id', '=', 'observations.employee_id')
            ->selectRaw("observations.branch_id, branches.branch_name, observations.employee_id,
                COALESCE(employees.name, NULLIF(observations.employee_name, '')) AS employee, observations.category_id, COUNT(*) AS n")
            ->groupBy('observations.branch_id', 'branches.branch_name', 'observations.employee_id', 'employees.name', 'observations.employee_name', 'observations.category_id')
            ->toBase()->get();

        $branches = [];
        foreach ($counts->groupBy('branch_id') as $branchId => $branchCells) {
            $people = [];
            foreach ($branchCells->groupBy(fn ($r) => $r->employee_id ? 'id:'.$r->employee_id : 'name:'.($r->employee ?? '')) as $cells) {
                $byCategory = [];
                foreach ($cells as $c) {
                    $byCategory[$c->category_id] = ($byCategory[$c->category_id] ?? 0) + (int) $c->n;
                }
                $people[] = [
                    'employee_id' => $cells->first()->employee_id ? (int) $cells->first()->employee_id : null,
                    'name' => $cells->first()->employee ?? dt('(بدون اسم)', '(no name)'),
                    'cells' => $byCategory, 'total' => array_sum($byCategory),
                ];
            }
            usort($people, fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);
            $sum = self::totals($people);
            $branches[] = ['branch_id' => (int) $branchId, 'name' => self::storeName($branchCells->first()->branch_name), 'people' => $people, 'cells' => $sum['totals'], 'total' => $sum['total']];
        }
        usort($branches, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $all = self::totals($branches);

        return ['columns' => self::usedColumns($columns, $counts), 'branches' => $branches, 'totals' => $all['totals'], 'total' => $all['total']];
    }

    /**
     * Saturday-Friday weeks with the count per category, newest first.
     *
     * @return list<array{from: Carbon, to: Carbon, cells: array<int,int>, total: int}>
     */
    public static function byWeek(Builder $base): array
    {
        $days = (clone $base)->whereNotNull('observations.event_date')
            ->selectRaw('observations.event_date AS d, observations.category_id, COUNT(*) AS n')
            ->groupBy('observations.event_date', 'observations.category_id')->toBase()->get();

        $weeks = [];
        foreach ($days as $r) {
            [$from, $to] = ObservationWeeklyReport::weekOf(Carbon::parse($r->d));
            $key = $from->toDateString();
            $weeks[$key] ??= ['from' => $from, 'to' => $to, 'cells' => [], 'total' => 0];
            $weeks[$key]['cells'][$r->category_id] = ($weeks[$key]['cells'][$r->category_id] ?? 0) + (int) $r->n;
            $weeks[$key]['total'] += (int) $r->n;
        }
        krsort($weeks);

        return array_values($weeks);
    }

    /**
     * Excel's three-colour scale (green, yellow, red) over one column of numbers, as on the weekly sheet.
     * Empty cells stay white; a column with one value shows green.
     */
    public static function heat(?int $n, int $min, int $max): string
    {
        if (! $n) {
            return '';
        }
        $t = $max > $min ? ($n - $min) / ($max - $min) : 0.0;
        [$a, $b, $t] = $t < 0.5 ? [[99, 190, 123], [255, 235, 132], $t * 2] : [[255, 235, 132], [248, 105, 107], ($t - 0.5) * 2];
        $rgb = array_map(fn ($i) => (int) round($a[$i] + ($b[$i] - $a[$i]) * $t), [0, 1, 2]);

        return sprintf('background:#%02x%02x%02x', ...$rgb);
    }

    /** [min, max] of the non-empty values of one column over rows of `cells` (or `total` when $categoryId is null). */
    public static function range(array $rows, ?int $categoryId): array
    {
        $values = array_filter(array_map(fn ($r) => $categoryId === null ? $r['total'] : ($r['cells'][$categoryId] ?? 0), $rows));

        return $values === [] ? [0, 0] : [min($values), max($values)];
    }

    /** The sheet shows only the categories that have violations in the period. */
    private static function usedColumns(Collection $columns, Collection $counts): Collection
    {
        $used = $counts->pluck('category_id')->filter()->unique()->all();

        return $columns->filter(fn ($c) => in_array($c->id, $used))->values();
    }

    /** @return array{rows: list<array>, totals: array<int,int>, total: int} */
    private static function totals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $r) {
            foreach ($r['cells'] as $categoryId => $n) {
                $totals[$categoryId] = ($totals[$categoryId] ?? 0) + $n;
            }
        }

        return ['rows' => $rows, 'totals' => $totals, 'total' => array_sum($totals)];
    }
}
