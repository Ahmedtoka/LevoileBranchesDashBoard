<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Http\JsonResponse;

class DemoUserController extends Controller
{
    /** Public list for the demo picker. Answers only when DEMO_LOGIN=true. */
    public function index(): JsonResponse
    {
        abort_unless(config('app.demo_login'), 404);

        return response()->json([
            'data' => array_map(
                fn (array $u) => array_intersect_key($u, array_flip(['email', 'name', 'role', 'hint', 'group']))
                    + ['group_label' => DemoUsersSeeder::GROUPS[$u['group']] ?? $u['group']],
                DemoUsersSeeder::users()
            ),
        ]);
    }
}
