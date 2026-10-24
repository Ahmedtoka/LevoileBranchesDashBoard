<?php

namespace App\Http\Resources\Observations;

use App\Models\ObservationVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The "visit item" JSON shape of the observations API (plain array, not a JsonResource). */
class ObservationVisitItem
{
    /** Relations to eager-load before serializing a list. */
    public const WITH = ['department:id,name', 'branch:id,branch_name', 'approver:id,name'];

    /** Counts to load with `withCount()` / `loadCount()` before serializing. */
    public static function counts(): array
    {
        return [
            'observations',
            'observations as issues_count' => fn (Builder $q) => $q->where('kind', 'issue'),
            // Issues the manager still has to route before the visit can be approved.
            'observations as pending_points' => fn (Builder $q) => $q->where('kind', 'issue')->whereNull('responsible_department_id'),
        ];
    }

    /** @param  ObservationVisit  $m */
    public static function make(Model $m): array
    {
        return [
            'id' => $m->id,
            'code' => $m->code,
            'type' => $m->type,
            'status' => $m->status,
            'source' => $m->source,
            'department' => $m->department ? ['id' => $m->department->id, 'name' => $m->department->name] : null,
            'branch' => $m->branch ? ['id' => $m->branch->id, 'name' => $m->branch->branch_name] : null,
            'visit_date' => $m->visit_date?->toDateString(),
            'started_at' => $m->started_at?->toIso8601String(),
            'checkin_latitude' => $m->checkin_latitude,
            'checkin_longitude' => $m->checkin_longitude,
            'checkin_simulated' => (bool) $m->checkin_simulated,
            'observations_count' => (int) ($m->observations_count ?? $m->observations()->count()),
            'issues_count' => (int) ($m->issues_count ?? $m->observations()->where('kind', 'issue')->count()),
            'pending_points' => (int) ($m->pending_points ?? $m->observations()->where('kind', 'issue')->whereNull('responsible_department_id')->count()),
            'submitted_at' => $m->submitted_at?->toIso8601String(),
            'approved_at' => $m->approved_at?->toIso8601String(),
            'approved_by' => $m->approver ? ['id' => $m->approver->id, 'name' => $m->approver->name] : null,
        ];
    }
}
