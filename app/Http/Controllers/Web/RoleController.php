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
            'super_admin' => 'تحكّم كامل في النظام: الإدارات، المستخدمين، القوالب، والتقارير.',
            'ops_manager' => 'مدير العمليات: يجدول الزيارات، يتابع الأريا مانجر والفروع والصيانة، ويشوف التذاكر حسب المصدر.',
            'area_manager' => 'مدير منطقة: يستلم زيارات الفروع المجدولة، يعملها، وكل خطأ يفتح تذكرة للإدارة المختصة.',
            'store_manager' => 'مدير فرع: يعمل الشيك ليست اليومية ويطلب الصيانة لفرعه ويتابع طلباته.',
            'branch_director' => 'مدير الفروع: إشراف عام على الفروع والمديرين.',
            'department_manager' => 'مدير إدارة: يستلم تذاكر إدارته، يعيّن للفنيين (الصيانة/العمليات) أو يتابعها (باقي الإدارات).',
            'department_employee' => 'موظف/فني إدارة: ينفّذ التذاكر المعيّنة له (قبول/بدء/إصلاح…).',
            'vm_manager' => 'مدير VM.',
            'ops_manager_dept' => 'إدارة العمليات.',
            'branch_manager' => 'مسؤول الفرع.',
        ];

        $roles = Role::orderBy('id')->get()->map(function ($r) use ($counts, $descriptions) {
            $r->users_count = $counts[$r->id] ?? 0;
            $r->description = $descriptions[$r->slug] ?? '—';

            return $r;
        });

        return view('dashboard.roles.index', compact('roles'));
    }
}
