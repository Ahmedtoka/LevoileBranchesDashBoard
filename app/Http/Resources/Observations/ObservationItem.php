<?php

namespace App\Http\Resources\Observations;

use App\Models\Department;
use App\Models\Observation;
use App\Models\ObservationPhoto;
use Illuminate\Database\Eloquent\Model;

/** The "observation item" JSON shape of the observations API (plain array, not a JsonResource). */
class ObservationItem
{
    /** Relations to eager-load before serializing a list. */
    public const WITH = [
        'riskLevel', 'responsibleDepartment:id,name', 'reportingDepartment:id,name', 'branch:id,branch_name',
        'ticket', 'photos', 'creator:id,name',
    ];

    /**
     * @param  Observation  $m
     * @param  array<int, string>|null  $departmentNames  id => name for `cc_departments`; looked up when null
     */
    public static function make(Model $m, ?array $departmentNames = null): array
    {
        $ccIds = array_values(array_map('intval', (array) ($m->cc_department_ids ?? [])));
        $departmentNames ??= $ccIds === [] ? [] : Department::whereIn('id', $ccIds)->pluck('name', 'id')->all();
        $ticket = $m->ticket;
        $risk = $m->riskLevel ? ['slug' => $m->riskLevel->slug, 'name_ar' => $m->riskLevel->name_ar, 'color' => $m->riskLevel->color] : null;

        return [
            'id' => $m->id,
            'reference' => $m->reference,
            'observation_visit_id' => $m->observation_visit_id,
            'kind' => $m->kind,
            'status' => $m->status,
            'classification_status' => $m->classification_status,
            'category_id' => $m->category_id,
            'catalog_item_id' => $m->catalog_item_id,
            'title' => $m->title,
            'description' => $m->description,
            'product_or_area' => $m->product_or_area,
            'extra_values' => $m->extra_values,
            'rating' => $m->rating,
            'risk' => $risk,
            'risk_level' => $risk,
            'risk_override_reason' => $m->risk_override_reason,
            'responsible_department' => self::ref($m->responsibleDepartment),
            'cc_departments' => collect($ccIds)
                ->filter(fn (int $id) => isset($departmentNames[$id]))
                ->map(fn (int $id) => ['id' => $id, 'name' => $departmentNames[$id]])
                ->values()->all(),
            'branch' => $m->branch ? ['id' => $m->branch->id, 'name' => $m->branch->branch_name] : null,
            'reporting_department' => self::ref($m->reportingDepartment),
            'event_date' => $m->event_date?->toDateString(),
            'ticket' => $ticket ? [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'title' => $ticket->title,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'due_at' => $ticket->due_at?->toIso8601String(),
                'is_overdue' => (bool) $ticket->isOverdue(),
            ] : null,
            'photos' => $m->photos->sortBy('id')->values()->map(fn (ObservationPhoto $p) => self::photo($p))->all(),
            'repeat_count' => (int) $m->repeat_count,
            'created_by' => self::ref($m->creator),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /**
     * Serialize many rows with one department lookup for all `cc_departments`.
     *
     * @param  iterable<Observation>  $rows
     */
    public static function many(iterable $rows): array
    {
        $rows = collect($rows);
        $ids = $rows->flatMap(fn (Observation $o) => array_map('intval', (array) ($o->cc_department_ids ?? [])))->unique()->values()->all();
        $names = $ids === [] ? [] : Department::whereIn('id', $ids)->pluck('name', 'id')->all();

        return $rows->map(fn (Observation $o) => self::make($o, $names))->values()->all();
    }

    public static function photo(ObservationPhoto $p): array
    {
        return ['id' => $p->id, 'kind' => $p->kind, 'path' => $p->path, 'url' => asset('storage/'.$p->path)];
    }

    private static function ref(?Model $m): ?array
    {
        return $m ? ['id' => $m->id, 'name' => $m->name] : null;
    }
}
