<?php

namespace App\Services\Observations\Import;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Groups mapped rows into visits (spec section 13): `department|branch|event_date` field visits for dated
 * branch rows; `department|ALL|week_start` desk visits for "All branches" rows; `department|branch|week_start|undated`
 * desk visits for branch rows without a confirmed date. Merchandising groups matching an `Original Visits`
 * row (same branch and date) take its start / end times and notes.
 */
class VisitGrouper
{
    public const UNDATED_NOTE = 'تاريخ غير مؤكد: التاريخ المسجل هو بداية الأسبوع في المصدر.';

    /**
     * @param  Collection<int, array>  $rows  RowMapper::map() records, in workbook order
     * @param  Collection<int, array>|null  $visits  RowMapper::mapVisit() records
     * @return Collection<int, array{key:string, department_id:int, branch_id:?int, type:string, visit_date:string, general_notes:?string, started_at:?Carbon, submitted_at:?Carbon, rows:array}>
     */
    public function group(Collection $rows, ?Collection $visits = null): Collection
    {
        $groups = [];

        foreach ($rows as $row) {
            if ($row['all_branches']) {
                $key = "{$row['department_id']}|ALL|{$row['week_start']}";
                $type = 'desk';
                $date = $row['week_start'];
                $notes = null;
            } elseif ($row['event_date'] !== null) {
                $date = $row['event_date']->toDateString();
                $key = "{$row['department_id']}|{$row['branch_id']}|{$date}";
                $type = 'field';
                $notes = null;
            } else {
                $key = "{$row['department_id']}|{$row['branch_id']}|{$row['week_start']}|undated";
                $type = 'desk';
                $date = $row['week_start'];
                $notes = self::UNDATED_NOTE;
            }

            $groups[$key] ??= [
                'key' => $key,
                'department_id' => (int) $row['department_id'],
                'branch_id' => $row['branch_id'],
                'type' => $type,
                'visit_date' => $date,
                'general_notes' => $notes,
                'started_at' => null,
                'submitted_at' => null,
                'rows' => [],
            ];
            $groups[$key]['rows'][] = $row;
        }

        foreach ($visits ?? [] as $visit) {
            $key = "{$visit['department_id']}|{$visit['branch_id']}|{$visit['date']}";
            if (! isset($groups[$key])) {
                continue;
            }
            $groups[$key]['started_at'] = $visit['started_at'];
            $groups[$key]['submitted_at'] = $visit['submitted_at'];
            if (! empty($visit['notes'])) {
                $groups[$key]['general_notes'] = trim(($groups[$key]['general_notes'] ?? '')."\n".$visit['notes']) ?: null;
            }
        }

        return collect(array_values($groups));
    }
}
