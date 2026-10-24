<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Services\Observations\Import\ImportWriter;
use App\Services\Observations\Import\RowMapper;
use App\Services\Observations\Import\UnknownBranch;
use App\Services\Observations\Import\UnknownDepartment;
use App\Services\Observations\Import\VisitGrouper;
use App\Services\Observations\Import\WorkbookReader;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Imports the October 2026 control-center workbook into observation visits, observations and tickets
 * (spec section 13). Idempotent on (import_source, import_row); silent (no notifications, no auto-assign).
 */
class ObservationsImport extends Command
{
    protected $signature = 'observations:import
        {path=database/data/levoile_control_center_oct2026.xlsx : Workbook path (relative to the project root)}
        {--dry-run : Print the per-department counts and the unknown branches without writing}
        {--only= : Import one reporting department only (slug, e.g. cctv)}';

    protected $description = 'Import the October 2026 control-center workbook into the observations module';

    /**
     * Branches in the workbook that the branch table may lack. "Mall of Egypt 2" is the spec section 13
     * target of "MOE 2" (same name as RealTeamSeeder); the other three are the owner's decision of
     * 2026-10-24: new branches without an area, to be placed by the admin.
     */
    public const EXTRA_BRANCHES = [
        '6th of October - Mall of Egypt 2' => ['area' => '6th of October', 'city' => 'Giza'],
        'Shehab Store' => ['city' => 'Cairo'],
        'Gamal Abd El Nasser Store' => ['city' => 'Cairo'],
        'MDS Store' => ['city' => 'Cairo'],
    ];

    public function handle(WorkbookReader $reader, ImportWriter $writer): int
    {
        $path = (string) $this->argument('path');
        if (! $this->isAbsolute($path)) {
            $path = base_path($path);
        }
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('only') ? trim((string) $this->option('only')) : null;
        $importSource = basename($path);

        $this->line(($dryRun ? '[dry run] ' : '').'Reading '.$path);
        $data = $reader->rows($path);

        // 1. Map every row with placeholder ids for the branches that do not exist yet: unknown names abort
        //    before anything is written, including those branches.
        $missing = array_keys(array_diff_key(self::EXTRA_BRANCHES, Branch::whereIn('branch_name', array_keys(self::EXTRA_BRANCHES))->pluck('id', 'branch_name')->all()));
        $placeholders = [];
        foreach (array_values($missing) as $i => $name) {
            $placeholders[$name] = -1 - $i;
        }

        [$rows, $visits, $unknownBranches, $unknownDepartments] = $this->mapAll($data, RowMapper::fromDatabase($placeholders), $only);

        if ($unknownBranches !== [] || $unknownDepartments !== []) {
            $this->error('Import aborted: nothing was written.');
            foreach ($unknownBranches as $name => $count) {
                $this->line("  unknown branch: \"{$name}\" ({$count} rows)");
            }
            foreach ($unknownDepartments as $name => $count) {
                $this->line("  unknown department: \"{$name}\" ({$count} rows)");
            }

            return self::FAILURE;
        }

        if ($rows->isEmpty()) {
            $this->warn('No rows to import'.($only ? " for department [{$only}]" : '').'.');

            return self::SUCCESS;
        }

        // 2. Real run: create the missing branches, then map again with their real ids.
        if (! $dryRun && $missing !== []) {
            foreach ($missing as $name) {
                Branch::updateOrCreate(['branch_name' => $name], array_merge(['active' => true, 'checkin_radius' => 150, 'area_id' => null], self::EXTRA_BRANCHES[$name]));
            }
            [$rows, $visits] = $this->mapAll($data, RowMapper::fromDatabase(), $only);
        }

        $groups = (new VisitGrouper)->group($rows, $visits);
        $departments = Department::pluck('slug', 'id')->all();
        $summary = [];
        $failures = [];
        $add = function (int $departmentId, array $counts) use (&$summary, $departments) {
            $slug = $departments[$departmentId] ?? (string) $departmentId;
            $summary[$slug] ??= ['visits' => 0, 'observations' => 0, 'tickets' => 0, 'skipped' => 0, 'escalated' => 0];
            foreach ($counts as $key => $value) {
                $summary[$slug][$key] = ($summary[$slug][$key] ?? 0) + $value;
            }
        };

        if ($dryRun) {
            $imported = Observation::where('import_source', $importSource)->pluck('import_row')->flip()->all();
            foreach ($groups as $group) {
                $pending = array_filter($group['rows'], fn ($row) => ! isset($imported[(string) $row['row']]));
                $add($group['department_id'], [
                    'visits' => $pending === [] ? 0 : 1,
                    'observations' => count($pending),
                    'tickets' => count(array_filter($pending, fn ($row) => $row['creates_ticket'])),
                    'skipped' => count($group['rows']) - count($pending),
                ]);
            }
        } else {
            $importUser = ImportWriter::ensureImportUser();
            $bar = $this->output->createProgressBar($groups->count());
            foreach ($groups as $group) {
                try {
                    $add($group['department_id'], $writer->write($group, $importUser, $importSource));
                } catch (\Throwable $e) {
                    $failures[] = [$group['key'], $e->getMessage()];
                }
                $bar->advance();
            }
            $bar->finish();
            $this->newLine(2);
        }

        $this->printSummary($summary, $dryRun, $missing, $groups, $failures);

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: Collection, 1: Collection, 2: array<string, int>, 3: array<string, int>}
     */
    private function mapAll(array $data, RowMapper $mapper, ?string $only): array
    {
        $originals = $data['observations']->keyBy('row');
        $unknownBranches = [];
        $unknownDepartments = [];
        $rows = [];

        foreach ($data['issues'] as $raw) {
            try {
                $original = ($raw['Source File'] ?? '') === 'Existing Merchandising database' && is_numeric($raw['Source Row'] ?? null)
                    ? ($originals[(int) $raw['Source Row']] ?? null)
                    : null;
                $row = $mapper->map($raw, $original);
                if ($only === null || $row['department_slug'] === $only) {
                    $rows[] = $row;
                }
            } catch (UnknownBranch $e) {
                $unknownBranches[$e->rawName] = ($unknownBranches[$e->rawName] ?? 0) + 1;
            } catch (UnknownDepartment $e) {
                $unknownDepartments[$e->rawName] = ($unknownDepartments[$e->rawName] ?? 0) + 1;
            }
        }

        $visits = [];
        foreach ($data['visits'] as $raw) {
            try {
                $visits[] = $mapper->mapVisit($raw);
            } catch (UnknownBranch $e) {
                $unknownBranches[$e->rawName] = ($unknownBranches[$e->rawName] ?? 0) + 1;
            } catch (UnknownDepartment $e) {
                $unknownDepartments[$e->rawName] = ($unknownDepartments[$e->rawName] ?? 0) + 1;
            }
        }

        return [collect($rows), collect($visits), $unknownBranches, $unknownDepartments];
    }

    private function printSummary(array $summary, bool $dryRun, array $missing, Collection $groups, array $failures): void
    {
        ksort($summary);
        $totals = ['visits' => 0, 'observations' => 0, 'tickets' => 0, 'skipped' => 0, 'escalated' => 0];
        $tableRows = [];
        foreach ($summary as $slug => $counts) {
            $tableRows[] = [$slug, $counts['visits'], $counts['observations'], $counts['tickets'], $counts['skipped'], $dryRun ? '-' : $counts['escalated']];
            foreach ($totals as $key => $_) {
                $totals[$key] += $counts[$key] ?? 0;
            }
        }
        $tableRows[] = ['TOTAL', $totals['visits'], $totals['observations'], $totals['tickets'], $totals['skipped'], $dryRun ? '-' : $totals['escalated']];

        $this->info($dryRun ? 'Dry run: nothing written. Counts that a real run would produce now:' : 'Import finished.');
        $this->table(['Department', 'Visits', 'Observations', 'Tickets', 'Skipped (already imported)', 'Risk escalated'], $tableRows);

        $this->line(sprintf('Visit groups in the workbook: %d (field %d, desk %d).',
            $groups->count(), $groups->where('type', 'field')->count(), $groups->where('type', 'desk')->count()));

        if ($missing !== []) {
            $this->line(($dryRun ? 'Branches the real run will create (no area): ' : 'Branches created (no area, to be placed by the admin): ').implode(', ', $missing));
        }

        foreach ($failures as [$key, $message]) {
            $this->error("Group {$key} failed: {$message}");
        }
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
