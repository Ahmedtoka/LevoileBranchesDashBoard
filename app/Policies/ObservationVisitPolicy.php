<?php

namespace App\Policies;

use App\Models\ObservationVisit;
use App\Models\User;
use App\Support\ObservationScope;

class ObservationVisitPolicy
{
    public function create(User $user): bool
    {
        return $user->isReportingUser();
    }

    public function view(User $user, ObservationVisit $visit): bool
    {
        return $this->owns($user, $visit)
            || ObservationScope::apply(ObservationVisit::whereKey($visit->id), $user, 'branch_id', 'department_id')->exists();
    }

    public function update(User $user, ObservationVisit $visit): bool
    {
        return $this->owns($user, $visit) && $visit->status === 'draft';
    }

    public function submit(User $user, ObservationVisit $visit): bool
    {
        return $this->owns($user, $visit) && $visit->status === 'draft';
    }

    /** Manager side of the approval gate (Plan H): ops / super, or the manager of the visit's reporting department. */
    public function supervise(User $user, ObservationVisit $visit): bool
    {
        return ObservationScope::approvesDepartment($user, $visit->department_id);
    }

    public function approve(User $user, ObservationVisit $visit): bool
    {
        return $visit->status === 'submitted' && $this->supervise($user, $visit);
    }

    public function classify(User $user, ObservationVisit $visit): bool
    {
        return $visit->status === 'submitted' && $this->supervise($user, $visit);
    }

    private function owns(User $user, ObservationVisit $visit): bool
    {
        return (int) $visit->user_id === (int) $user->id;
    }
}
