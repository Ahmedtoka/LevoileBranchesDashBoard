<?php

namespace Database\Seeders;

use App\Models\Translation;
use Illuminate\Database\Seeder;

class TranslationSeeder extends Seeder
{
    public function run(): void
    {
        // [group, key, ar, en]
        $rows = [
            // navigation
            ['nav', 'nav.overview', 'نظرة عامة', 'Overview'],
            ['nav', 'nav.tickets', 'التذاكر', 'Tickets'],
            ['nav', 'nav.visits', 'الزيارات', 'Visits'],
            ['nav', 'nav.schedule', 'جدولة زيارة', 'Schedule Visit'],
            ['nav', 'nav.management', 'الإدارة', 'Management'],
            ['nav', 'nav.departments', 'الإدارات', 'Departments'],
            ['nav', 'nav.users', 'المستخدمون', 'Users'],
            ['nav', 'nav.roles', 'الأدوار', 'Roles'],
            ['nav', 'nav.branches', 'الفروع', 'Branches'],
            ['nav', 'nav.coverage', 'تغطية الفروع', 'Branch Coverage'],
            ['nav', 'nav.builder', 'محرّر الشيك ليست', 'Checklist Builder'],
            ['nav', 'nav.maintenance', 'مركز الصيانة', 'Maintenance Center'],
            ['nav', 'nav.reports', 'التقارير', 'Reports'],
            ['nav', 'nav.strings', 'النصوص', 'Strings'],
            ['nav', 'nav.logout', 'تسجيل الخروج', 'Logout'],
            ['nav', 'nav.notifications', 'الإشعارات', 'Notifications'],
            ['nav', 'nav.mark_all_read', 'تعليم الكل كمقروء', 'Mark all read'],

            // date range
            ['range', 'range.today', 'اليوم', 'Today'],
            ['range', 'range.3d', 'آخر 3 أيام', 'Last 3 days'],
            ['range', 'range.week', 'آخر 7 أيام', 'Last 7 days'],
            ['range', 'range.month', 'آخر 30 يوم', 'Last 30 days'],
            ['range', 'range.custom', 'مخصص', 'Custom'],
            ['range', 'range.from', 'من', 'From'],
            ['range', 'range.to', 'إلى', 'To'],

            // common buttons / labels
            ['common', 'common.apply', 'تطبيق', 'Apply'],
            ['common', 'common.reset', 'مسح', 'Reset'],
            ['common', 'common.search', 'بحث', 'Search'],
            ['common', 'common.save', 'حفظ', 'Save'],
            ['common', 'common.edit', 'تعديل', 'Edit'],
            ['common', 'common.delete', 'حذف', 'Delete'],
            ['common', 'common.open', 'فتح', 'Open'],
            ['common', 'common.view', 'عرض', 'View'],
            ['common', 'common.new', 'جديد', 'New'],
            ['common', 'common.all', 'الكل', 'All'],
            ['common', 'common.total', 'الإجمالي', 'Total'],
            ['common', 'common.actions', 'إجراءات', 'Actions'],
            ['common', 'common.branch', 'الفرع', 'Branch'],
            ['common', 'common.department', 'الإدارة', 'Department'],
            ['common', 'common.date', 'التاريخ', 'Date'],
            ['common', 'common.name', 'الاسم', 'Name'],
            ['common', 'common.email', 'البريد', 'Email'],
            ['common', 'common.role', 'الدور', 'Role'],
            ['common', 'common.manager', 'المدير', 'Manager'],
            ['common', 'common.active', 'نشط', 'Active'],
            ['common', 'common.source', 'المصدر', 'Source'],
            ['common', 'common.filter_rows', 'فلترة صفوف الصفحة…', 'Filter rows on this page…'],
            ['common', 'common.logout', 'تسجيل الخروج', 'Logout'],
            ['common', 'common.no_data', 'لا توجد بيانات.', 'No data.'],
            ['tickets', 'tk.search', 'بحث بالعنوان / الكود…', 'Search title / reference…'],

            // dashboard
            ['dashboard', 'dash.title', 'نظرة عامة', 'Overview'],
            ['dashboard', 'dash.branches', 'الفروع', 'Branches'],
            ['dashboard', 'dash.visits', 'الزيارات', 'Visits'],
            ['dashboard', 'dash.visits_completed', 'زيارات مكتملة', 'Completed visits'],
            ['dashboard', 'dash.visits_open', 'زيارات مفتوحة', 'Open visits'],
            ['dashboard', 'dash.tickets_total', 'إجمالي التذاكر', 'Total tickets'],
            ['dashboard', 'dash.tickets_open', 'تذاكر مفتوحة', 'Open tickets'],
            ['dashboard', 'dash.waiting_approval', 'بانتظار الموافقة', 'Waiting approval'],
            ['dashboard', 'dash.overdue', 'متأخرة', 'Overdue'],
            ['dashboard', 'dash.by_source', 'التذاكر حسب المصدر', 'Tickets by source'],
            ['dashboard', 'dash.by_department', 'التذاكر حسب الإدارة', 'Tickets by department'],
            ['dashboard', 'dash.recent', 'أحدث التذاكر', 'Recent tickets'],
            ['dashboard', 'dash.repeated', 'طلبات متكررة', 'Repeated requests'],
            ['dashboard', 'dash.src_store', 'شيك ليست مدير الفرع', 'Store manager checklist'],
            ['dashboard', 'dash.src_area', 'زيارات الأريا مانجر', 'Area manager visits'],
            ['dashboard', 'dash.src_maintenance', 'طلبات الصيانة', 'Maintenance requests'],
            ['dashboard', 'dash.no_repeated', 'لا توجد طلبات متكررة في الفترة.', 'No repeated requests in this period.'],
            ['dashboard', 'dash.no_tickets', 'لا توجد تذاكر في الفترة.', 'No tickets in this period.'],

            // tickets
            ['tickets', 'tk.title', 'التذاكر', 'Tickets'],
            ['tickets', 'tk.code', 'الكود', 'Ref'],
            ['tickets', 'tk.subject', 'العنوان', 'Title'],
            ['tickets', 'tk.assignee', 'المسؤول', 'Assignee'],
            ['tickets', 'tk.priority', 'الأولوية', 'Priority'],
            ['tickets', 'tk.status', 'الحالة', 'Status'],
            ['tickets', 'tk.closed', 'مقفولة', 'Closed'],
            ['tickets', 'tk.open', 'مفتوحة', 'Open'],

            // statuses
            ['status', 'status.open', 'جديدة', 'New'],
            ['status', 'status.assigned', 'معيّنة', 'Assigned'],
            ['status', 'status.on_the_way', 'مقبولة', 'Accepted'],
            ['status', 'status.in_progress', 'جاري التنفيذ', 'In progress'],
            ['status', 'status.waiting_approval', 'بانتظار الموافقة', 'Awaiting approval'],
            ['status', 'status.postponed', 'مؤجّلة', 'Postponed'],
            ['status', 'status.not_fixed', 'لم يتم التصليح', 'Not fixed'],
            ['status', 'status.rejected', 'مرفوضة', 'Rejected'],
            ['status', 'status.closed', 'مقفولة', 'Closed'],

            // priorities
            ['priority', 'priority.low', 'منخفضة', 'Low'],
            ['priority', 'priority.medium', 'متوسطة', 'Medium'],
            ['priority', 'priority.high', 'عالية', 'High'],
            ['priority', 'priority.critical', 'حرجة', 'Critical'],

            // visits
            ['visits', 'visit.title', 'الزيارات', 'Visits'],
            ['visits', 'visit.template', 'القالب', 'Template'],
            ['visits', 'visit.user', 'المستخدم', 'User'],
            ['visits', 'visit.problems', 'طلبات', 'Problems'],
            ['visits', 'visit.tickets', 'تذاكر', 'Tickets'],
            ['visits', 'visit.completed', 'مكتملة', 'Completed'],
            ['visits', 'visit.search', 'بحث عن فرع…', 'Search branch…'],
            ['visits', 'visit.no_visits', 'لا توجد زيارات في الفترة.', 'No visits in this period.'],

            // users / departments
            ['users', 'users.title', 'المستخدمون', 'Users'],
            ['users', 'users.new', 'مستخدم جديد', 'New user'],
            ['users', 'users.search_ph', 'اسم أو بريد…', 'Name or email…'],
            ['users', 'users.inactive', 'موقوف', 'Off'],
            ['users', 'users.confirm_delete', 'حذف المستخدم؟', 'Delete this user?'],
            ['departments', 'dept.title', 'الإدارات', 'Departments'],
            ['departments', 'dept.new', 'إدارة جديدة', 'New department'],
            ['departments', 'dept.staff', 'موظفين', 'Staff'],
            ['departments', 'dept.board', 'فتح اللوحة', 'Open board'],
            ['departments', 'dept.search', 'بحث عن إدارة…', 'Search department…'],
            ['departments', 'dept.inactive', 'موقوفة', 'Inactive'],
            ['departments', 'dept.no_manager', '— لا يوجد', '— none'],
            ['departments', 'dept.confirm_delete', 'حذف الإدارة؟', 'Delete this department?'],

            // reports
            ['reports', 'rep.title', 'التقارير', 'Reports'],
            ['reports', 'rep.visits_per_branch', 'الزيارات حسب الفرع', 'Visits per branch'],
            ['reports', 'rep.avg_resolution', 'متوسط زمن الحل (ساعات)', 'Avg resolution (hours)'],
            ['reports', 'rep.performance', 'أداء الموظفين', 'Employee performance'],
            ['reports', 'rep.overdue', 'التذاكر المتأخرة', 'Overdue tickets'],

            // roles
            ['roles', 'roles.intro', 'نظرة على أدوار النظام وصلاحية كل دور. عدد المستخدمين قابل للضغط لعرضهم.', 'Overview of system roles and what each can do. Click the user count to view them.'],
            ['roles', 'roles.user', 'مستخدم', 'users'],
        ];

        foreach ($rows as [$group, $key, $ar, $en]) {
            Translation::updateOrCreate(['key' => $key], compact('group', 'ar', 'en'));
        }
    }
}
