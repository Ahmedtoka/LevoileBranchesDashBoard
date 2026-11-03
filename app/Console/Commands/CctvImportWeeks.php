<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Observation;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Services\Cctv\CctvSheetMapper;
use App\Services\Observations\Import\ImportedRows;
use App\Services\Observations\Import\ImportWriter;
use App\Services\Observations\ObservationService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the weekly CCTV action-plan sheets (owner request 2026-11-03) into the cctv department's register:
 * one desk visit per branch and day, approved by the import user like the October workbook import, with an
 * employee record per branch and name. Rows repeated from an earlier sheet are skipped; a row already
 * imported (same file + row) is skipped too, so the command can run again.
 */
class CctvImportWeeks extends Command
{
    protected $signature = 'cctv:import-weeks
        {paths* : The weekly sheets, oldest first}
        {--dry-run : Print the counts and the problems without writing}
        {--replace-october : Remove the cctv rows of the October control-center workbook import first}';

    protected $description = 'Import the weekly CCTV action-plan sheets into the CCTV register';

    public const OCTOBER_SOURCE = ImportedRows::OCTOBER_SOURCE;

    public function handle(ObservationService $service): int
    {
        $department = Department::where('slug', 'cctv')->firstOrFail();
        $branches = Branch::pluck('id', 'branch_name');
        $categories = ObservationCategory::where('department_id', $department->id)->pluck('id', 'slug');
        $catalog = ObservationCatalogItem::whereIn('category_id', $categories->values())->pluck('id', 'code');

        $rows = [];
        $seen = [];
        $problems = [];
        foreach ((array) $this->argument('paths') as $path) {
            $file = basename($path);
            $kept = $dupes = 0;
            foreach ($this->sheetRows($path) as $line => $raw) {
                $m = CctvSheetMapper::map($raw);
                if ($m['text'] === '' && $m['store'] === '') {
                    continue;
                }
                $where = "{$file}:{$line}";
                foreach ([
                    'branch' => $m['branch_name'] !== null && isset($branches[$m['branch_name']]),
                    'date' => $m['date'] !== null,
                    'category' => $m['category_slug'] !== null && isset($categories[$m['category_slug']]),
                    'violation' => $m['code'] !== null && isset($catalog[$m['code']]),
                ] as $what => $ok) {
                    if (! $ok) {
                        $problems[] = "{$where} unknown {$what}: ".($what === 'branch' ? $m['store'] : $m['text']);
                    }
                }
                $key = CctvSheetMapper::dedupeKey($m);
                if (isset($seen[$key])) {
                    $dupes++;

                    continue;
                }
                $seen[$key] = $where;
                $kept++;
                $rows[] = $m + ['file' => $file, 'line' => $line];
            }
            $this->line("{$file}: {$kept} rows, {$dupes} repeated from an earlier sheet");
        }

        if ($problems !== []) {
            $this->error('Import aborted, nothing was written:');
            foreach ($problems as $p) {
                $this->line('  '.$p);
            }

            return self::FAILURE;
        }

        $october = Observation::where('reporting_department_id', $department->id)->where('import_source', self::OCTOBER_SOURCE);
        $this->line(count($rows).' rows to import; October workbook cctv rows in the register: '.$october->count());
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if ($this->option('replace-october')) {
            $this->line('Removed October cctv rows: '.ImportedRows::remove($department->id, self::OCTOBER_SOURCE));
        }

        $importUser = ImportWriter::ensureImportUser();
        $employees = [];
        $written = $skipped = 0;
        $groups = collect($rows)->groupBy(fn ($r) => $r['branch_name'].'|'.$r['date']->toDateString());
        foreach ($groups as $group) {
            $group = $group->reject(fn ($r) => Observation::where('import_source', $r['file'])->where('import_row', (string) $r['line'])->exists())->values();
            if ($group->isEmpty()) {
                $skipped++;

                continue;
            }
            $first = $group->first();
            $branchId = (int) $branches[$first['branch_name']];

            $visit = $service->createVisit($importUser, [
                'type' => 'desk', 'department_id' => $department->id, 'branch_id' => $branchId,
                'visit_date' => $first['date']->toDateString(), 'source' => 'import',
            ]);
            $saved = $service->upsertObservations($visit, $group->map(fn ($r) => [
                'catalog_item_id' => $catalog[$r['code']],
                'category_id' => $categories[$r['category_slug']],
                'description' => $r['text'],
                'employee_name' => $r['employee'] ?: null,
                'extra_values' => $r['extra_values'] ?: null,
            ])->all(), $importUser);
            $service->submitVisit($visit, $importUser, ['silent' => true, 'due_from' => $first['date']]);
            $service->approveVisit($visit->fresh(), $importUser, ['silent' => true, 'due_from' => $first['date']]);

            foreach ($saved->values() as $i => $o) {
                $r = $group[$i];
                $employeeId = $r['employee'] === '' ? null
                    : ($employees[$branchId.'|'.Employee::normalizeName($r['employee'])] ??= $this->employee($r['employee'], $branchId));
                Observation::whereKey($o->id)->update([
                    'import_source' => $r['file'], 'import_row' => (string) $r['line'], 'employee_id' => $employeeId,
                ]);
                $written++;
            }
        }

        $this->info("Imported {$written} violations into ".$groups->count()." branch-days ({$skipped} already imported); employees: ".Employee::count());

        return self::SUCCESS;
    }

    /** First sheet of the workbook as header => cell rows, keyed by the Excel row number. */
    private function sheetRows(string $path): array
    {
        $book = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path);
        try {
            $cells = $book->getSheet(0)->toArray(null, false, false, false);
        } finally {
            $book->disconnectWorksheets();
        }
        $head = array_map(fn ($h) => trim((string) $h), array_shift($cells) ?? []);
        $rows = [];
        foreach ($cells as $i => $cells_) {
            $row = [];
            foreach ($head as $c => $h) {
                if ($h !== '') {
                    $row[$h] = trim((string) ($cells_[$c] ?? ''));
                }
            }
            $rows[$i + 2] = $row;
        }

        return $rows;
    }

    /** One record per branch and spelling-folded name; an existing one (added by hand) is reused. */
    private function employee(string $name, int $branchId): int
    {
        $key = Employee::normalizeName($name);
        $match = Employee::where('branch_id', $branchId)->get()->first(fn (Employee $e) => Employee::normalizeName($e->name) === $key);

        return ($match ?? Employee::create(['name' => $name, 'branch_id' => $branchId]))->id;
    }
}
