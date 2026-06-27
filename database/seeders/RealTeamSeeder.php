<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Loads the REAL store/sales team from database/data/sales_team.json, plus the
 * maintenance team, operations manager and area managers. Wipes the old demo
 * users first. Each branch person is linked to their branch with their title;
 * one store manager is chosen per branch (Store Manager → senior → assistant).
 */
class RealTeamSeeder extends Seeder
{
    /** sheet branch name => DB branch_name */
    private array $branchMap = [
        'Abbas 1' => 'Nasr City - Abbas El Akkad / Ezzat Salama',
        'Abbas 2' => 'Nasr City - Abbas El Akkad',
        'Al-Ekbal' => 'Alexandria - El Ekbal',
        'City Stars' => 'Nasr City - City Stars Mall',
        'ELMokatem 2' => 'El Mokattam - Mokattam 2',
        'Gallaria' => '5th Settlement - Galleria Mall',
        'Gamal Abdelnaser' => 'Alexandria - Miami',
        'Genena Mall' => 'Nasr City - Gnena Mall',
        'Hegaz' => 'Heliopolis - El Hegaz',
        'MOA' => '6th of October - Mall of Arabia',
        'MOE' => '6th of October - Mall of Egypt Store',
        'MOE ( 2 ) Store' => '6th of October - Mall of Egypt 2',
        'Maadi' => 'Maadi - Maadi 1',
        'Maadi 2' => 'Maadi - Maadi 2',
        'Madinty' => 'Madinaty - Open Air Mall',
        'Mansoura' => 'Mansoura - Mansoura Store',
        'Marghany' => 'Heliopolis - El Marghany',
        'Mohandssen' => 'El Mohandessin - Mohandessin Store',
        'Mokattam' => 'El Mokattam - Mokattam 1',
        'Nozha' => 'Nasr City - Nozha Street',
        'Point 90' => '5th Settlement - Point 90 Mall',
        'Rebat Mall' => '5th Settlement - El Rebat Mall',
        'San Stefano' => 'Alexandria - San Stefano Mall',
        'Smouha' => 'Alexandria - Smouha',
        'The yard' => 'El Rehab - The Yard Mall',
        'Zagazig' => 'Zagazig - Zagazig Store 1',
        'Zayed' => 'Sheikh Zayed - Saraya Mall',
    ];

    public function run(): void
    {
        $roles = Role::pluck('id', 'slug');
        $this->ensureRole('sales', 'Sales');

        $salesDept = Department::firstOrCreate(['slug' => 'sales'],
            ['name' => 'Sales', 'ticket_prefix' => 'SAL', 'color' => '#0ea5e9', 'active' => true]);
        $maintDept = Department::where('slug', 'maintenance')->first();
        $opsDept = Department::where('slug', 'operation')->first();

        // ensure the one branch the sheet has that the seeder didn't: MOE 2
        Branch::firstOrCreate(['branch_name' => '6th of October - Mall of Egypt 2'],
            ['area' => '6th of October', 'city' => 'Giza', 'active' => true, 'checkin_radius' => 150]);

        // ---- wipe old demo users + demo data (keep super_admin) ----
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['ticket_updates', 'ticket_evidence', 'visit_answer_evidence', 'visit_answer_selected_employees', 'visit_answers', 'tickets', 'visits', 'notifications'] as $t) {
            if (DB::getSchemaBuilder()->hasTable($t)) {
                DB::table($t)->delete();
            }
        }
        if (DB::getSchemaBuilder()->hasTable('branch_user')) {
            DB::table('branch_user')->delete();
        }
        Branch::query()->update(['manager_id' => null]);
        User::where('email', '!=', 'admin@levoile.test')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $roles = Role::pluck('id', 'slug'); // refresh after ensureRole

        // keep / (re)create the admin account for the dashboard
        $this->make('Super Admin', 'admin@levoile.test', $roles['super_admin'] ?? null, ['job_title' => 'مدير النظام']);

        // ---- maintenance team (English accounts, Arabic names) ----
        if ($maintDept) {
            $this->make('Ali Hosny Younis', 'ali.younis@levoile.com', $roles['department_manager'] ?? null,
                ['department_id' => $maintDept->id, 'is_department_manager' => true, 'job_title' => 'مدير الصيانة']);
            $this->make('Mohamed Osama', 'mohamed.osama@levoile.com', $roles['department_manager'] ?? null,
                ['department_id' => $maintDept->id, 'is_department_manager' => true, 'job_title' => 'مدير الصيانة']);

            $techs = [
                ['Ahmed Hamdy Saad', 'ahmed.hamdy@levoile.com'],
                ['Belal Ahmed Ibrahim', 'belal.ahmed@levoile.com'],
                ['Ahmed Hassan Mohamed', 'ahmed.hassan@levoile.com'],
                ['Moustafa Abbas', 'moustafa.abbas@levoile.com'],
            ];
            foreach ($techs as [$n, $e]) {
                $this->make($n, $e, $roles['department_employee'] ?? null,
                    ['department_id' => $maintDept->id, 'is_department_manager' => false, 'job_title' => 'فني صيانة']);
            }
        }

        // ---- operations manager ----
        $this->make('Abdel Sabour', 'abdAlSabour@levoile.com', $roles['ops_manager'] ?? null,
            ['job_title' => 'مدير العمليات']);

        // ---- area managers ----
        $area = [
            ['Karim Mounir Mamoun', 'karim.mounir@levoile.com'],
            ['Ahmed Mohamed Mahmoud Ali', 'ahmed.mahmoud.ali@levoile.com'],
            ['Mohamed Yousif Sayed', 'mohamed.yousif@levoile.com'],
        ];
        $areaUsers = [];
        foreach ($area as [$n, $e]) {
            $areaUsers[] = $this->make($n, $e, $roles['area_manager'] ?? null, ['job_title' => 'مدير منطقة']);
        }

        // ---- sales team + store managers from the sheet ----
        $path = database_path('data/sales_team.json');
        $data = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        $coveredBranchIds = [];
        $a = 0; // round-robin area manager assignment
        foreach ($data as $sheetBranch => $people) {
            $dbName = $this->branchMap[$sheetBranch] ?? null;
            $branch = $dbName ? Branch::where('branch_name', $dbName)->first() : null;
            if (! $branch) {
                continue;
            }
            $coveredBranchIds[$branch->id] = $a;
            $a = ($a + 1) % max(1, count($areaUsers));

            // pick the store manager for this branch
            $smIndex = $this->pickStoreManager($people);

            foreach ($people as $i => $p) {
                $isSm = $i === $smIndex;
                $code = $p['code'] ?: Str::random(5);
                $email = 'emp'.$code.'@levoile.com';

                $this->make($p['name'], $email, $isSm ? ($roles['store_manager'] ?? null) : ($roles['sales'] ?? null), [
                    'department_id' => $salesDept->id,
                    'branch_id' => $branch->id,
                    'is_department_manager' => false,
                    'job_title' => $isSm ? 'مدير فرع' : $p['job'],
                    'staff_code' => $code,
                ]);

                if ($isSm) {
                    $sm = User::where('email', $email)->first();
                    $branch->update(['manager_id' => $sm->id]);
                }
            }
        }

        // ---- area manager coverage: split branches across the 3 area managers ----
        foreach ($coveredBranchIds as $branchId => $idx) {
            $u = $areaUsers[$idx] ?? null;
            if ($u) {
                $u->branches()->syncWithoutDetaching([$branchId]);
            }
        }
    }

    /** Store Manager → senior sales → assistant; null if only plain sales. */
    private function pickStoreManager(array $people): ?int
    {
        foreach ($people as $i => $p) {
            if (strtolower($p['job']) === 'store manager') {
                return $i;
            }
        }
        foreach ($people as $i => $p) {
            if (strtolower($p['job']) === 'senior sales') {
                return $i;
            }
        }
        foreach ($people as $i => $p) {
            $j = strtolower($p['job']);
            if ($j === 'assistant store manager' || $j === 'a.s. m') {
                return $i;
            }
        }

        return null; // no store manager for this branch
    }

    private function make(string $name, string $email, ?int $roleId, array $extra = []): User
    {
        return User::updateOrCreate(['email' => $email], array_merge([
            'name' => $name,
            'password' => Hash::make('password'),
            'role_id' => $roleId,
            'active' => true,
        ], $extra));
    }

    private function ensureRole(string $slug, string $name): void
    {
        Role::firstOrCreate(['slug' => $slug], ['name' => $name]);
    }
}
