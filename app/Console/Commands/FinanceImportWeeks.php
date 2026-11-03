<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Services\Finance\FinanceReportMapper;
use App\Services\Observations\Import\ImportedRows;
use App\Services\Observations\Import\ImportWriter;
use App\Services\Observations\ObservationService;
use Illuminate\Console\Command;

/**
 * Imports the weekly finance reports (Word, owner request 2026-11-03) as branch reviews of the finance
 * department: one review per followed branch per report, its problems from the report's matrix (one per
 * tick) with the detail of the report's problem list laid on the matching tick. A followed branch the
 * matrix does not name was reviewed clean. Runs again without duplicating (file + branch + date).
 */
class FinanceImportWeeks extends Command
{
    protected $signature = 'finance:import-weeks
        {paths* : The weekly reports (.docx), oldest first}
        {--dry-run : Print what would be written}
        {--replace-october : Remove the finance rows of the October control-center workbook import first}';

    protected $description = 'Import the weekly finance reports as branch reviews';

    public function handle(ObservationService $service): int
    {
        $department = Department::where('slug', 'finance')->firstOrFail();
        $branches = Branch::pluck('id', 'branch_name');
        $categories = ObservationCategory::where('department_id', $department->id)->pluck('id', 'slug');
        $catalog = ObservationCatalogItem::whereIn('category_id', $categories->values())->pluck('id', 'code');
        $followed = Branch::where('active', true)->whereNotIn('branch_name', FinanceReportMapper::NOT_FOLLOWED)->pluck('id', 'branch_name');

        $plans = [];
        $problems = [];
        foreach ((array) $this->argument('paths') as $path) {
            $file = basename($path);
            $report = FinanceReportMapper::read($path);
            $date = $report['from'] ?? $report['to']?->copy()->subDays(7);
            if ($date === null) {
                $problems[] = "{$file}: no period";

                continue;
            }
            // Two weeks' reports share one file name, so the source carries the report's date too.
            $file = basename($path).'@'.$date->toDateString();

            // branch name => rows to write
            $rows = [];
            foreach ($report['matrix'] as $m) {
                $name = FinanceReportMapper::branch($m['branch']);
                if ($name === null || ! isset($branches[$name])) {
                    $problems[] = "{$file}: unknown branch in the matrix: {$m['branch']}";

                    continue;
                }
                foreach ($m['columns'] as $slug) {
                    $rows[$name][] = ['category' => $slug, 'code' => FinanceReportMapper::DEFAULT_ITEM[$slug], 'text' => null, 'extra' => [], 'row' => "matrix:{$m['branch']}:{$slug}", 'detailed' => false];
                }
                $rows[$name] ??= [];
            }
            foreach ($report['problems'] as $i => $p) {
                $name = FinanceReportMapper::branch($p['branch']);
                $hit = FinanceReportMapper::problem($p['text']);
                if ($name === null || ! isset($branches[$name]) || $hit === null) {
                    $problems[] = "{$file}: problem ".($i + 1)." not mapped: {$p['branch']} / {$p['text']}";

                    continue;
                }
                [$code, $slug] = $hit;
                $extra = array_filter([
                    'amount' => FinanceReportMapper::amount($p['text']),
                    'action' => $p['action'] !== '' && $p['action'] !== $p['text'] ? $p['action'] : null,
                    'lp_referred' => str_contains(FinanceReportMapper::normalize($p['action']), 'منع الخسائر') ? true : null,
                ], fn ($v) => $v !== null);
                $detail = ['category' => $slug, 'code' => $code, 'text' => $p['text'], 'extra' => $extra, 'row' => 'problem:'.($i + 1), 'detailed' => true];
                // Lay the detail on the first plain tick of the same branch and control, else add it.
                $at = collect($rows[$name] ?? [])->search(fn ($r) => $r['category'] === $slug && ! $r['detailed']);
                if ($at !== false) {
                    $rows[$name][$at] = $detail + ['row' => $rows[$name][$at]['row']];
                    $rows[$name][$at]['row'] = $rows[$name][$at]['row'].'+problem:'.($i + 1);
                } else {
                    $rows[$name][] = $detail;
                }
            }
            $notes = [];
            foreach ($report['notes'] as $n) {
                if ($name = FinanceReportMapper::branch($n['branch'])) {
                    $notes[$name] = trim($n['text'].($n['action'] !== '' ? ' — '.$n['action'] : ''));
                }
            }
            foreach ($followed as $name => $id) {
                $rows[$name] ??= [];
            }

            $plans[] = compact('file', 'date', 'rows', 'notes');
            $count = array_sum(array_map('count', $rows));
            $clean = count(array_filter($rows, fn ($r) => $r === []));
            $reviewed = count($rows);
            $this->line(sprintf('%s: %s, %d branches reviewed, %d clean (%d%%), %d problems',
                $file, $date->toDateString(), $reviewed, $clean, $reviewed ? round($clean * 100 / $reviewed) : 0, $count));
        }

        foreach ($plans as $plan) {
            foreach ($plan['rows'] as $rows) {
                foreach ($rows as $r) {
                    if (! isset($catalog[$r['code']]) || ! isset($categories[$r['category']])) {
                        $problems[] = "{$plan['file']}: catalog item {$r['code']} missing (run the catalog seeder)";
                    }
                }
            }
        }
        if ($problems !== []) {
            $this->error('Import aborted, nothing was written:');
            foreach (array_unique($problems) as $p) {
                $this->line('  '.$p);
            }

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if ($this->option('replace-october')) {
            $this->line('Removed October finance rows: '.ImportedRows::remove($department->id, ImportedRows::OCTOBER_SOURCE));
        }

        $importUser = ImportWriter::ensureImportUser();
        $written = $visits = $skipped = 0;
        foreach ($plans as $plan) {
            $marker = 'استيراد: '.$plan['file'];
            foreach ($plan['rows'] as $name => $rows) {
                $branchId = (int) $branches[$name];
                $exists = ObservationVisit::where('department_id', $department->id)->where('branch_id', $branchId)
                    ->whereDate('visit_date', $plan['date']->toDateString())->where('source', 'import')
                    ->where('general_notes', 'like', $marker.'%')->exists();
                if ($exists) {
                    $skipped++;

                    continue;
                }
                $visit = $service->createVisit($importUser, [
                    'type' => 'desk', 'department_id' => $department->id, 'branch_id' => $branchId,
                    'visit_date' => $plan['date']->toDateString(), 'source' => 'import',
                    'general_notes' => $marker.(isset($plan['notes'][$name]) ? "\n".$plan['notes'][$name] : ''),
                ]);
                $saved = $rows === [] ? collect() : $service->upsertObservations($visit, array_map(fn ($r) => [
                    'catalog_item_id' => $catalog[$r['code']],
                    'category_id' => $categories[$r['category']],
                    'description' => $r['text'] ?? '',
                    'extra_values' => $r['extra'] ?: null,
                ], $rows), $importUser);
                $service->submitVisit($visit, $importUser, ['silent' => true, 'due_from' => $plan['date']]);
                $service->approveVisit($visit->fresh(), $importUser, ['silent' => true, 'due_from' => $plan['date']]);
                foreach ($saved->values() as $i => $o) {
                    Observation::whereKey($o->id)->update(['import_source' => $plan['file'], 'import_row' => $rows[$i]['row']]);
                }
                $visits++;
                $written += $saved->count();
            }
        }

        $this->info("Imported {$visits} branch reviews with {$written} problems ({$skipped} reviews already imported).");

        return self::SUCCESS;
    }
}
