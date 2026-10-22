<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\Observation;
use App\Models\User;
use App\Support\ObservationScope;

/** Catalog administration: categories, catalog items, areas and risk levels. */
class ObservationCatalogPolicy
{
    public function manageAreas(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function manageRiskLevels(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function manageCategory(User $user, ?Department $department = null): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $department !== null
            && ObservationScope::managedReportingDepartmentId($user) === (int) $department->id;
    }

    public function review(User $user, Observation $observation): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        $departmentId = ObservationScope::managedReportingDepartmentId($user);

        return $departmentId !== null && $departmentId === (int) $observation->reporting_department_id;
    }
}
