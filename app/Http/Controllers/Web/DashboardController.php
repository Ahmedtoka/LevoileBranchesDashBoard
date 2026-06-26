<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\Analytics;
use App\Support\DateRange;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $user = $request->user();

        // Department managers see a dashboard scoped to their own department only.
        $isDeptManager = $user->is_department_manager && $user->department_id;
        $deptId = $isDeptManager ? $user->department_id : null;
        $scopeDept = $deptId ? Department::find($deptId) : null;

        $data = Analytics::overview($range->from, $range->to, $deptId);

        return view('dashboard.overview', array_merge($data, [
            'range' => $range,
            'scopeDept' => $scopeDept,
        ]));
    }
}
