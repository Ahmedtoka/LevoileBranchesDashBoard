<?php

namespace App\Support;

use App\Models\Department;
use App\Models\ObservationCatalogItem;
use App\Models\ObservationCategory;
use App\Models\ObservationVisit;
use App\Models\User;
use App\Services\Observations\ObservationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Routing of the CCTV / finance entry screens (owner request 2026-11-03): each problem carries the department
 * that must answer it, picked from a dropdown that starts on the catalog's default. The visit is approved on
 * save when the recorder may approve it, so the ticket lands on that department's board at once; it stays open
 * there until the department closes it.
 */
class ProblemRouting
{
    /** Departments a problem can be sent to. */
    public static function departments(): Collection
    {
        return Department::query()->when(Schema::hasColumn('departments', 'active'), fn ($q) => $q->where('active', true))
            ->orderBy('name')->get(['id', 'slug', 'name']);
    }

    /** Default responsible department of an item (or of its category, else Operation). */
    public static function defaultFor(?ObservationCatalogItem $item, ?ObservationCategory $category): ?int
    {
        $id = $item?->responsible_department_id ?? $category?->default_responsible_department_id
            ?? Department::where('slug', 'operation')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Submit, then approve at once when the recorder may (manager of the department, ops, super admin). */
    public static function submit(ObservationService $service, ObservationVisit $visit, User $user): ObservationVisit
    {
        $result = $service->submitVisit($visit, $user);
        if ($result['visit']->status === 'submitted' && $user->can('approve', $result['visit'])) {
            $result = $service->approveVisit($result['visit'], $user);
        }

        return $result['visit'];
    }
}
