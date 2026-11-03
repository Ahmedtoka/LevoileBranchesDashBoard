<?php

namespace App\Services\Observations\Import;

use App\Models\Observation;
use App\Models\ObservationPhoto;
use App\Models\ObservationVisit;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Removes one department's rows of one import (with their tickets and the visits left empty), so a better
 * import of the same weeks can replace them. Used by the per-department weekly imports (2026-11-03).
 */
class ImportedRows
{
    public const OCTOBER_SOURCE = 'levoile_control_center_oct2026.xlsx';

    public static function remove(int $departmentId, string $source): int
    {
        return DB::transaction(function () use ($departmentId, $source) {
            $ids = Observation::where('reporting_department_id', $departmentId)->where('import_source', $source)->pluck('id');
            $visitIds = Observation::whereIn('id', $ids)->distinct()->pluck('observation_visit_id');
            $ticketIds = Ticket::whereIn('observation_id', $ids)->pluck('id');
            Observation::whereIn('id', $ids)->update(['ticket_id' => null]);
            DB::table('notifications')->whereIn('ticket_id', $ticketIds)->delete();
            Ticket::whereIn('id', $ticketIds)->delete(); // ticket_updates cascade
            ObservationPhoto::whereIn('observation_id', $ids)->delete();
            DB::table('observation_verifications')->whereIn('observation_id', $ids)->delete();
            DB::table('ai_classifications')->whereIn('observation_id', $ids)->delete();
            Observation::whereIn('id', $ids)->delete();
            ObservationVisit::whereIn('id', $visitIds)->whereDoesntHave('observations')->delete();

            return $ids->count();
        });
    }
}
