<?php

namespace App\Policies;

use App\Models\Observation;
use App\Models\User;
use App\Support\ObservationScope;

class ObservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isReportingUser() || $user->hasRole(ObservationScope::BRANCH_ROLES);
    }

    public function view(User $user, Observation $observation): bool
    {
        return ObservationScope::apply(Observation::whereKey($observation->id), $user)->exists();
    }

    public function verify(User $user, Observation $observation): bool
    {
        if ($user->hasRole(['super_admin', 'ops_manager'])) {
            return true;
        }

        if ($user->department_id && (int) $user->department_id === (int) $observation->reporting_department_id) {
            return true;
        }

        return $user->hasRole('area_manager')
            && $observation->branch_id
            && in_array((int) $observation->branch_id, $user->scopedBranchIds() ?? [], true);
    }

    public function reroute(User $user, Observation $observation): bool
    {
        return $user->hasRole(['super_admin', 'ops_manager']);
    }

    public function closeNoAction(User $user, Observation $observation): bool
    {
        return $user->hasRole(['super_admin', 'ops_manager']);
    }
}
