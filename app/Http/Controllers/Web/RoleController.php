<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;

class RoleController extends Controller
{
    /** Reference / overview of the system roles and what each one does. */
    public function index()
    {
        $counts = User::selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');

        $descriptions = [
            'super_admin' => dt('تحكّم كامل في النظام: الإدارات، المستخدمين، القوالب، والتقارير.', 'Full system control: departments, users, templates, and reports.'),
            'ops_manager' => dt('مدير العمليات: يجدول الزيارات، يتابع الأريا مانجر والفروع والصيانة، ويشوف التذاكر حسب المصدر.', 'Ops manager: schedules visits, follows area managers, branches and maintenance, and views tickets by source.'),
            'area_manager' => dt('مدير منطقة: يستلم زيارات الفروع المجدولة، يعملها، وكل خطأ يفتح تذكرة للإدارة المختصة.', 'Area manager: receives scheduled branch visits, runs them, and each issue opens a ticket to the right department.'),
            'store_manager' => dt('مدير فرع: يعمل الشيك ليست اليومية ويطلب الصيانة لفرعه ويتابع طلباته.', 'Store manager: completes the daily checklist, requests maintenance for the branch, and tracks requests.'),
            'branch_director' => dt('مدير الفروع: إشراف عام على الفروع والمديرين.', 'Branch director: overall oversight of branches and managers.'),
            'department_manager' => dt('مدير إدارة: يستلم تذاكر إدارته، يعيّن للفنيين (الصيانة/العمليات) أو يتابعها (باقي الإدارات).', 'Department manager: receives the department tickets, assigns to technicians (maintenance/ops) or follows them (other departments).'),
            'department_employee' => dt('موظف/فني إدارة: ينفّذ التذاكر المعيّنة له (قبول/بدء/إصلاح…).', 'Department employee/technician: executes assigned tickets (accept/start/fix…).'),
            'vm_manager' => dt('مدير VM.', 'VM manager.'),
            'ops_manager_dept' => dt('إدارة العمليات.', 'Operations department.'),
            'branch_manager' => dt('مسؤول الفرع.', 'Branch officer.'),
        ];

        $roles = Role::orderBy('id')->get()->map(function ($r) use ($counts, $descriptions) {
            $r->users_count = $counts[$r->id] ?? 0;
            $r->description = $descriptions[$r->slug] ?? '—';

            return $r;
        });

        return view('dashboard.roles.index', compact('roles'));
    }
}
