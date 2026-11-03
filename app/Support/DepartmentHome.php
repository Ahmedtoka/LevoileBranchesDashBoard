<?php

namespace App\Support;

use App\Models\User;

/**
 * Departments that work in their own menu only (owner request 2026-11-03): a member of the CCTV or finance
 * department (department manager or employee) sees that department's pages and nothing else. Management
 * roles keep the full system.
 */
class DepartmentHome
{
    /** department slug => route-name prefix of its menu */
    public const MODULES = ['cctv' => 'cctv', 'finance' => 'finance', 'hr' => 'hr'];

    /**
     * Pages outside the menu such a user still needs: one problem's page and its routing, the department's
     * tasks (answering and approving tickets), alerts, language, logout.
     */
    public const ALSO_ALLOWED = ['observations.show', 'observations.reroute', 'observations.closeNoAction',
        'tasks.index', 'tickets.show', 'tickets.reply', 'tickets.transition',
        'notifications.read', 'notifications.readAll', 'locale.switch', 'logout'];

    /** The route-name prefix the user is limited to, or null for the full system. */
    public static function prefixFor(?User $u): ?string
    {
        if ($u === null || ! $u->hasRole(['department_manager', 'department_employee'])) {
            return null;
        }

        return self::MODULES[$u->department?->slug] ?? null;
    }

    public static function allows(string $prefix, ?string $routeName): bool
    {
        return $routeName !== null && (str_starts_with($routeName, $prefix.'.') || in_array($routeName, self::ALSO_ALLOWED, true));
    }
}
