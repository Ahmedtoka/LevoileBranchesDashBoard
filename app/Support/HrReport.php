<?php

namespace App\Support;

use App\Models\Department;
use App\Models\User;
use App\Services\Observations\ObservationService;

/**
 * The HR menu (owner request 2026-11-03): the staff directory loaded from the HR export, one page per
 * employee, and the upload of a new export. Phones are personal data, so the menu is for management and
 * the HR department only; uploading is for HR and ops / super admin.
 */
class HrReport
{
    public static function department(): ?Department
    {
        return Department::where('slug', 'hr')->first();
    }

    public static function canView(User $u): bool
    {
        return $u->hasRole(ObservationService::ALL_DEPARTMENTS_ROLES) || self::inHr($u);
    }

    public static function canManage(User $u): bool
    {
        return $u->hasRole(['super_admin', 'ops_manager']) || self::inHr($u);
    }

    private static function inHr(User $u): bool
    {
        $d = self::department();

        return $d !== null && (int) $u->department_id === $d->id;
    }
}
