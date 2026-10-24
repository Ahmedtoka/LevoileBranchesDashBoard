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

    /** The single source of truth shared by the seeder, the web login and the API. */
    public static function users(): array
    {
        return [
            ['email' => 'admin@levoile.test', 'name' => 'المدير العام', 'role' => 'super_admin', 'department' => null,
                'hint' => 'شاهد لوحة التحكم الكاملة وولّد أو امسح البيانات التجريبية'],
            ['email' => 'ops@levoile.test', 'name' => 'مدير العمليات', 'role' => 'ops_manager', 'department' => null,
                'hint' => 'راجع الملاحظات والمخاطر على كل الفروع'],
            ['email' => 'area.cairo@levoile.test', 'name' => 'مدير منطقة القاهرة', 'role' => 'area_manager', 'department' => null,
                'hint' => 'تابع ملاحظات فرعي زايد وسيتي ستارز'],
            ['email' => 'store.zayed@levoile.test', 'name' => 'مدير فرع زايد', 'role' => 'store_manager', 'department' => null,
                'hint' => 'أكّد إصلاح الملاحظات المنتظرة في فرعك'],
            ['email' => 'cctv.manager@levoile.test', 'name' => 'مدير إدارة الكاميرات', 'role' => 'department_manager', 'department' => 'cctv',
                'hint' => 'راجع زيارات فريق الكاميرات وتصنيفات الذكاء الاصطناعي'],
            ['email' => 'cctv.emp@levoile.test', 'name' => 'موظف كاميرات', 'role' => 'department_employee', 'department' => 'cctv',
                'hint' => 'سجّل زيارة CCTV على فرع زايد'],
            ['email' => 'stock.emp@levoile.test', 'name' => 'موظف مراقبة مخزون', 'role' => 'department_employee', 'department' => 'stock_control',
                'hint' => 'سجّل زيارة مراقبة مخزون وقيّم الوضع'],
            ['email' => 'operation.manager@levoile.test', 'name' => 'مدير التشغيل (المسؤول عن الحل)', 'role' => 'department_manager', 'department' => 'operation',
                'hint' => 'وزّع التيكتات الناتجة عن الملاحظات على فريقك'],
            ['email' => 'operation.emp@levoile.test', 'name' => 'موظف تشغيل (ينفذ التيكت)', 'role' => 'department_employee', 'department' => 'operation',
                'hint' => 'نفّذ التيكت وارفع صورة ما بعد الإصلاح'],
            ['email' => 'maintenance.tech@levoile.test', 'name' => 'فني صيانة', 'role' => 'department_employee', 'department' => 'maintenance',
                'hint' => 'نفّذ تيكتات الصيانة على فرعي زايد وسيتي ستارز'],
        ];
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
            'cctv' => ['name' => 'CCTV', 'ticket_prefix' => 'CCT', 'color' => '#64748b', 'is_reporting' => true],
            'stock_control' => ['name' => 'Stock Control', 'ticket_prefix' => 'STK', 'color' => '#f59e0b', 'is_reporting' => true],
            'operation' => ['name' => 'Operation', 'ticket_prefix' => 'OPR', 'color' => '#10b981'],
            'maintenance' => ['name' => 'Maintenance', 'ticket_prefix' => 'MTN', 'color' => '#ef4444'],
        ];

        return Department::firstOrCreate(['slug' => $slug], $defs[$slug]);
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
