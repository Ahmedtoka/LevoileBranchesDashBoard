<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The CCTV and finance department users (owner request 2026-11-03). Each is the manager of their department,
 * so what they record is approved on save and lands at once on the responsible department's board, and they
 * see their department's menu only (DepartmentHome). Re-running keeps an existing user's password.
 *
 *   php artisan db:seed --class=DepartmentUsersSeeder
 */
class DepartmentUsersSeeder extends Seeder
{
    /** First password of a new user (change it after the first login). */
    public const PASSWORD = 'LeVoile@2026';

    private const USERS = [
        ['email' => 'cctv@levoile.test', 'name' => 'CCTV', 'department' => 'cctv', 'job_title' => 'CCTV Monitoring'],
        ['email' => 'finance@levoile.test', 'name' => 'Finance', 'department' => 'finance', 'job_title' => 'Finance Control'],
    ];

    public function run(): void
    {
        $role = Role::where('slug', 'department_manager')->firstOrFail();

        foreach (self::USERS as $u) {
            $department = Department::where('slug', $u['department'])->firstOrFail();
            $user = User::firstOrNew(['email' => $u['email']]);
            $user->fill([
                'name' => $u['name'], 'job_title' => $u['job_title'], 'role_id' => $role->id,
                'department_id' => $department->id, 'is_department_manager' => true, 'active' => true,
            ]);
            if (! $user->exists) {
                $user->password = Hash::make(self::PASSWORD);
            }
            $user->save();
        }
    }
}
