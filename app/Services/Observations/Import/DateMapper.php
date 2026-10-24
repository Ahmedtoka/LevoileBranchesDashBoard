<?php

namespace App\Services\Observations\Import;

use Carbon\Carbon;

/**
 * Event date of a workbook row: the verified date when present, else the raw cell (Excel serial, d-m-Y or
 * "d / m / Y") when it falls inside the row's week; otherwise null (spec section 13).
 */
class DateMapper
{
    public function eventDate(?string $verified, ?string $raw, string $weekStart, string $weekEnd): ?Carbon
    {
        $verified = trim((string) $verified);
        if ($verified !== '') {
            $date = $this->parseRaw($verified);
            if ($date !== null) {
                return $date;
            }
        }

        $date = $this->parseRaw($raw);
        if ($date === null) {
            return null;
        }

        $start = Carbon::parse($weekStart)->startOfDay();
        $end = Carbon::parse($weekEnd)->endOfDay();

        return $date->between($start, $end) ? $date : null;
    }

    /** Excel serial (days since 1899-12-30), `Y-m-d`, `d-m-Y`, `d/m/Y` with optional spaces; null otherwise. */
    public function parseRaw(?string $raw): ?Carbon
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4,6}(\.\d+)?$/', $raw)) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $raw))->startOfDay();
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m)) {
            return $this->make((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})\s*[-\/.]\s*(\d{1,2})\s*[-\/.]\s*(\d{4})$/', $raw, $m)) {
            return $this->make((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private function make(int $y, int $m, int $d): ?Carbon
    {
        return checkdate($m, $d, $y) ? Carbon::create($y, $m, $d)->startOfDay() : null;
    }
}
