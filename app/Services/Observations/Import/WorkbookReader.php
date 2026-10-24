<?php

namespace App\Services\Observations\Import;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipArchive;

/**
 * Reads the three sheets of the control-center workbook into header-keyed rows (every cell trimmed to a
 * string; `row` = the Excel row number).
 */
class WorkbookReader
{
    public const SHEET_ISSUES = 'Central Issues';

    public const SHEET_OBSERVATIONS = 'Original Observations';

    public const SHEET_VISITS = 'Original Visits';

    /** @return array{issues: Collection<int, array>, observations: Collection<int, array>, visits: Collection<int, array>} */
    public function rows(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Workbook not found: {$path}");
        }

        $workbook = $this->load($path);

        try {
            return [
                'issues' => $this->sheetRows($workbook, self::SHEET_ISSUES),
                'observations' => $this->sheetRows($workbook, self::SHEET_OBSERVATIONS),
                'visits' => $this->sheetRows($workbook, self::SHEET_VISITS),
            ];
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function sheetRows(Spreadsheet $workbook, string $name): Collection
    {
        $sheet = $workbook->getSheetByName($name);
        if (! $sheet instanceof Worksheet) {
            throw new \RuntimeException("Sheet [{$name}] not found in the workbook.");
        }

        $cells = $sheet->toArray(null, true, false, false);
        $header = array_map(fn ($h) => trim((string) $h), $cells[0] ?? []);
        $rows = [];

        foreach (array_slice($cells, 1, null, true) as $index => $values) {
            $row = ['row' => $index + 1];
            $empty = true;
            foreach ($header as $column => $key) {
                if ($key === '') {
                    continue;
                }
                $value = trim((string) ($values[$column] ?? ''));
                $row[$key] = $value;
                $empty = $empty && $value === '';
            }
            if (! $empty) {
                $rows[] = $row;
            }
        }

        return collect($rows);
    }

    /**
     * The workbook's tables are named "T1".."T6", which PhpSpreadsheet rejects as cell references, so the
     * table parts are stripped from a temporary copy before loading (data only; cells are untouched).
     */
    private function load(string $path): Spreadsheet
    {
        $temp = tempnam(sys_get_temp_dir(), 'lvwb');
        if ($temp === false || ! copy($path, $temp)) {
            throw new \RuntimeException('Could not copy the workbook to a temporary file.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($temp) === true) {
                $names = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $names[] = $zip->getNameIndex($i);
                }
                foreach ($names as $name) {
                    if (! preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                        continue;
                    }
                    $xml = $zip->getFromName($name);
                    $stripped = preg_replace('#<(?:\w+:)?tableParts\b[^>]*>.*?</(?:\w+:)?tableParts>|<(?:\w+:)?tableParts\b[^>]*/>#s', '', $xml);
                    if ($stripped !== null && $stripped !== $xml) {
                        $zip->addFromString($name, $stripped);
                    }
                }
                $zip->close();
            }

            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);

            return $reader->load($temp);
        } finally {
            @unlink($temp);
        }
    }
}
