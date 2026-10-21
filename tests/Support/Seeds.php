<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

class Seeds
{
    public static function role(string $slug): Role
    {
        return Role::updateOrCreate(['slug' => $slug], ['name' => Str::headline($slug)]);
    }

    public static function department(string $slug, array $attrs = []): Department
    {
        return Department::updateOrCreate(['slug' => $slug], array_merge([
            'name' => Str::headline($slug),
            'ticket_prefix' => strtoupper(substr($slug, 0, 3)),
        ], $attrs));
    }

    public static function branch(string $name, array $attrs = []): Branch
    {
        return Branch::updateOrCreate(['branch_name' => $name], $attrs);
    }

    public static function user(string $roleSlug, array $attrs = []): User
    {
        $role = self::role($roleSlug);

        return User::create(array_merge([
            'name' => Str::headline($roleSlug),
            'email' => Str::slug($roleSlug).'-'.Str::random(6).'@test.local',
            'password' => 'password',
            'role_id' => $role->id,
            'active' => true,
        ], $attrs));
    }
}
