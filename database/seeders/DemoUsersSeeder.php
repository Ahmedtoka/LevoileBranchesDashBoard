<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo users for the quick-login card and the Flutter demo picker.
 * Idempotent (by email); never deletes; never touches users outside users(). Works on an empty database.
 * The password is set ONLY when a demo user is created: a later run never resets it. On an existing demo
 * row only name / role / department / branch / is_department_manager / active are kept in line. A branch
 * manager or an area manager that is not a demo user is never replaced, and a branch already in an area
 * is never moved: the demo area manager is then given the branches through branch_user instead.
 */
class DemoUsersSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** Login-page sections, in display order: key => Arabic label. */
    public const GROUPS = [
        'management' => 'الإدارة العليا',
        'branches' => 'المناطق والفروع',
        'managers' => 'مديرو الإدارات (يفحصون ويعتمدون الزيارات)',
        'employees' => 'موظفو الإدارات (يسجلون الزيارات وينفذون التيكتات)',
    ];

    /** Reporting departments that get a demo manager and a demo employee: slug => [email prefix, Arabic department name]. */
    private const DEPARTMENTS = [
        'cctv' => ['cctv', 'الكاميرات'],
        'stock_control' => ['stock', 'مراقبة المخزون'],
        'operation' => ['operation', 'التشغيل'],
        'maintenance' => ['maintenance', 'الصيانة'],
        'merchandise' => ['merch', 'المرشندايز'],
        'finance' => ['finance', 'المالية'],
        'lp' => ['lp', 'منع الخسائر'],
        'it' => ['it', 'IT'],
        'hr' => ['hr', 'الموارد البشرية'],
        'vm' => ['vm', 'العرض المرئي VM'],
    ];

    /** The single source of truth shared by the seeder, the web login and the API. Every row has a `group` (see GROUPS). */
    public static function users(): array
    {
        $rows = [
            ['email' => 'admin@levoile.test', 'name' => 'المدير العام', 'role' => 'super_admin', 'department' => null, 'group' => 'management',
                'hint' => 'كل الشاشات: الداشبورد، تقرير الأسبوع، تقرير الموظفين، الإعدادات، وزرارا مسح/توليد البيانات التجريبية'],
            ['email' => 'ops@levoile.test', 'name' => 'مدير العمليات', 'role' => 'ops_manager', 'department' => null, 'group' => 'management',
                'hint' => 'يرى ملاحظات كل الإدارات على كل الفروع، يعيد التوجيه ويرفع درجة الخطورة'],
            ['email' => 'area.cairo@levoile.test', 'name' => 'مدير منطقة القاهرة', 'role' => 'area_manager', 'department' => null, 'group' => 'branches',
                'hint' => 'ملاحظات فرعي زايد وسيتي ستارز + التحقق الميداني من الحل (موبايل)'],
            ['email' => 'store.zayed@levoile.test', 'name' => 'مدير فرع زايد', 'role' => 'store_manager', 'department' => null, 'group' => 'branches',
                'hint' => 'ملاحظات فرعه فقط + اعتماد الحل (إقفال التيكت)'],
        ];

        $managerHints = [
            'cctv' => 'افتح "اعتماد الزيارات" → فحص → اعتماد الزيارة فتتحول النقاط لتيكتات',
            'stock_control' => 'اعتمد زيارات مراقبة المخزون وشاهد تقرير الأسبوع لإدارتك',
            'operation' => 'إدارتك تستلم أغلب التيكتات: تابع لوحة التيكتات ووزّعها على الفريق',
            'maintenance' => 'تيكتات الصيانة الناتجة عن الملاحظات + اعتماد زيارات الصيانة',
        ];
        $employeeHints = [
            'cctv' => 'سجّل زيارة مكتبية أو ميدانية: كتالوج مخالفات الكاميرات + كتابة حرة',
            'stock_control' => 'سجّل زيارة تقييم المخزون (7 نقاط بتقييم جيد/متوسط/ضعيف)',
            'operation' => 'ينفذ التيكت: "جاري العمل عليها" ثم "تم الحل" مع صورة',
            'maintenance' => 'نفّذ تيكتات الصيانة على فرعي زايد وسيتي ستارز',
            'merchandise' => 'سجّل ملاحظات العرض والجودة والتوافر على الفرع',
            'finance' => 'سجّل مراجعة مالية مكتبية: مرتجعات بدون فواتير، عجز، تجميدات',
            'lp' => 'سجّل تدريب موظفي الفرع ومخالفات منع الخسائر',
            'it' => 'سجّل زيارة أجهزة الفرع (POS، كاميرات، إنترنت)',
            'hr' => 'سجّل ملاحظات الحضور والبصمة ودور مدير الفرع',
            'vm' => 'سجّل ملاحظات العرض المرئي والفاترينة',
        ];

        foreach (self::DEPARTMENTS as $slug => [$prefix, $arabic]) {
            $rows[] = [
                'email' => "{$prefix}.manager@levoile.test", 'name' => "مدير إدارة {$arabic}", 'role' => 'department_manager',
                'department' => $slug, 'group' => 'managers',
                'hint' => $managerHints[$slug] ?? "اعتمد زيارات إدارة {$arabic} وتابع تيكتاتها وتقرير أسبوعها",
            ];
        }
        foreach (self::DEPARTMENTS as $slug => [$prefix, $arabic]) {
            $rows[] = [
                'email' => $slug === 'maintenance' ? 'maintenance.tech@levoile.test' : "{$prefix}.emp@levoile.test",
                'name' => $slug === 'maintenance' ? 'فني صيانة' : "موظف {$arabic}", 'role' => 'department_employee',
                'department' => $slug, 'group' => 'employees',
                'hint' => $employeeHints[$slug],
            ];
        }

        return $rows;
    }

    /** users() grouped for the login page: group label => rows. */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::GROUPS as $key => $label) {
            $out[$label] = array_values(array_filter(self::users(), fn (array $u) => $u['group'] === $key));
        }

        return $out;
    }

    public function run(): void
    {
        $this->upsert(array_column(self::users(), 'email'));
    }

    /** Upsert only the given demo emails (plus what they depend on). */
    public function upsert(array $emails): void
    {
        DB::transaction(function () use ($emails) {
            $zayed = $this->branch('Sheikh Zayed - Saraya Mall', '%Saraya Mall', 'Sheikh Zayed', 'Giza');
            $stars = $this->branch('Nasr City - City Stars Mall', '%City Stars Mall', 'Nasr City', 'Cairo');

            $done = [];
            foreach (self::users() as $row) {
                if (! in_array($row['email'], $emails, true)) {
                    continue;
                }

                $role = Role::firstOrCreate(['slug' => $row['role']], ['name' => ucwords(str_replace('_', ' ', $row['role']))]);
                $department = $row['department'] ? $this->department($row['department']) : null;

                $user = User::firstOrNew(['email' => $row['email']]);
                if (! $user->exists) {
                    $user->password = self::PASSWORD; // hashed by the model cast; never reset on a later run
                }
                $user->fill([
                    'name' => $row['name'],
                    'role_id' => $role->id,
                    'department_id' => $department?->id,
                    'is_department_manager' => $row['role'] === 'department_manager',
                    'active' => true,
                ])->save();
                $done[$row['email']] = $user;
            }

            if (isset($done['store.zayed@levoile.test'])) {
                $store = $done['store.zayed@levoile.test'];
                $store->update(['branch_id' => $zayed->id]);
                // The store manager sees the branch through branch_id anyway; a real manager is never replaced.
                if ($zayed->manager_id === null || $this->isDemoUser((int) $zayed->manager_id)) {
                    $zayed->update(['manager_id' => $store->id]);
                }
            }

            foreach (['operation.emp@levoile.test', 'maintenance.tech@levoile.test'] as $email) {
                if (isset($done[$email])) {
                    $done[$email]->branches()->syncWithoutDetaching([$zayed->id, $stars->id]);
                }
            }

            if (isset($done['area.cairo@levoile.test'])) {
                $this->shareArea($zayed, $stars, $done['area.cairo@levoile.test']);
            }
        });
    }

    private function department(string $slug): Department
    {
        $defs = [
            'cctv' => ['name' => 'CCTV', 'ticket_prefix' => 'CCT', 'color' => '#64748b'],
            'stock_control' => ['name' => 'Stock Control', 'ticket_prefix' => 'STK', 'color' => '#f59e0b'],
            'operation' => ['name' => 'Operation', 'ticket_prefix' => 'OPR', 'color' => '#10b981'],
            'maintenance' => ['name' => 'Maintenance', 'ticket_prefix' => 'MTN', 'color' => '#ef4444'],
            'merchandise' => ['name' => 'Merchandise', 'ticket_prefix' => 'MRC', 'color' => '#8b5cf6'],
            'finance' => ['name' => 'Finance', 'ticket_prefix' => 'FIN', 'color' => '#0ea5e9'],
            'lp' => ['name' => 'Loss Prevention', 'ticket_prefix' => 'LSP', 'color' => '#dc2626'],
            'it' => ['name' => 'IT', 'ticket_prefix' => 'ITD', 'color' => '#2563eb'],
            'hr' => ['name' => 'HR', 'ticket_prefix' => 'HRD', 'color' => '#db2777'],
            'vm' => ['name' => 'VM', 'ticket_prefix' => 'VMR', 'color' => '#ca8a04'],
        ];

        $department = Department::firstOrCreate(['slug' => $slug], $defs[$slug] + ['is_reporting' => true]);
        if (! $department->is_reporting) {
            // Every demo department reports observations (the live rows may predate the flag).
            $department->update(['is_reporting' => true]);
        }

        return $department;
    }

    private function branch(string $fullName, string $like, string $area, string $city): Branch
    {
        return Branch::where('branch_name', 'like', $like)->first()
            ?? Branch::create(['branch_name' => $fullName, 'area' => $area, 'city' => $city, 'active' => true, 'checkin_radius' => 150]);
    }

    /**
     * Give the demo area manager both branches. Through their shared area when it has no manager or a demo one;
     * otherwise branches without an area join the demo area and the others are covered through branch_user
     * (User::scopedBranchIds merges it), so a real area's manager and a real branch's area are never changed.
     */
    private function shareArea(Branch $a, Branch $b, User $manager): void
    {
        $a->refresh();
        $b->refresh();

        if ($a->area_id && $a->area_id === $b->area_id) {
            $area = Area::find($a->area_id);
            if ($area->manager_id === null || $this->isDemoUser((int) $area->manager_id)) {
                $area->update(['manager_id' => $manager->id]);
            } else {
                $manager->branches()->syncWithoutDetaching([$a->id, $b->id]);
            }

            return;
        }

        $area = Area::updateOrCreate(['slug' => 'demo-cairo'], [
            'name' => 'Cairo (demo)',
            'name_ar' => 'القاهرة (تجريبي)',
            'manager_id' => $manager->id,
        ]);
        foreach ([$a, $b] as $branch) {
            if ($branch->area_id === null || (int) $branch->area_id === (int) $area->id) {
                $branch->update(['area_id' => $area->id]);
            } else {
                $manager->branches()->syncWithoutDetaching([$branch->id]);
            }
        }
    }

    private function isDemoUser(int $id): bool
    {
        return User::whereKey($id)->whereIn('email', array_column(self::users(), 'email'))->exists();
    }
}
