<?php

namespace App\Support;

use App\Models\ObservationVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Restricts observation / observation-visit queries to what a user may see (spec §5). */
class ObservationScope
{
    /** Roles that see every branch and every reporting department. */
    public const UNRESTRICTED_ROLES = ['super_admin', 'ops_manager', 'branch_director', 'vm_manager'];

    /** Roles restricted to their own branch(es). */
    public const BRANCH_ROLES = ['store_manager', 'branch_manager', 'sales'];

    public static function apply(Builder $q, User $u, string $branchColumn = 'branch_id', string $reportingColumn = 'reporting_department_id'): Builder
    {
        if ($u->hasRole(self::UNRESTRICTED_ROLES)) {
            return $q;
        }

        if ($u->hasRole(array_merge(['area_manager'], self::BRANCH_ROLES))) {
            return $q->whereIn($q->qualifyColumn($branchColumn), $u->scopedBranchIds() ?? []);
        }

        $model = $q->getModel();
        $reporting = $q->qualifyColumn($reportingColumn);

        if ($departmentId = self::managedReportingDepartmentId($u)) {
            return $q->where(function (Builder $w) use ($model, $reporting, $departmentId) {
                $w->where($reporting, $departmentId);

                if (method_exists($model, 'ticket')) {
                    $w->orWhereHas('ticket', fn (Builder $t) => $t->where($t->qualifyColumn('department_id'), $departmentId));
                }
            });
        }

        if ($departmentId = self::employeeReportingDepartmentId($u)) {
            $owner = $q->qualifyColumn($model instanceof ObservationVisit ? 'user_id' : 'created_by');

            return $q->where(fn (Builder $w) => $w->where($owner, $u->id)->orWhere($reporting, $departmentId));
        }

        return $q->whereRaw('0 = 1');
    }

    /** Department manager (flag or role) of a reporting department: that department's id, else null. */
    public static function managedReportingDepartmentId(User $u): ?int
    {
        $isManager = $u->is_department_manager || $u->hasRole('department_manager');

        return $isManager ? self::reportingDepartmentId($u) : null;
    }

    /** Department employee of a reporting department: that department's id, else null. */
    public static function employeeReportingDepartmentId(User $u): ?int
    {
        return $u->hasRole('department_employee') ? self::reportingDepartmentId($u) : null;
    }

    private static function reportingDepartmentId(User $u): ?int
    {
        return $u->department_id && $u->department?->is_reporting ? (int) $u->department_id : null;
    }
}
