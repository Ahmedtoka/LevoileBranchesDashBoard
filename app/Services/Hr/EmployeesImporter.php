<?php

namespace App\Services\Hr;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Observation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * The HR staff export (users-*.xlsx, owner request 2026-11-03): one row per employee keyed by "الكود".
 * plan() reads the file and works out every change without writing; apply() writes a plan. Re-running
 * updates by code. A record without a code that came from the CCTV sheets (a first name at a branch) takes
 * the code and full name of the one employee of that branch whose name starts with it, else (two words or
 * more) the one branch employee of the company (moved since); a second spelling of the same person is
 * merged into the first, its violations with it. Coded employees missing from the file become inactive.
 */
class EmployeesImporter
{
    /** "مكان العمل" => branches.branch_name; any other place (head office, warehouses) has no branch. */
    public const BRANCHES = [
        'abbas 1' => 'Nasr City - Abbas El Akkad',
        'abbas 2' => 'Nasr City - Abbas El Akkad / Ezzat Salama',
        'city stars' => 'Nasr City - City Stars Mall',
        'genena mall' => 'Nasr City - Gnena Mall',
        'el-nozha' => 'Nasr City - Nozha Street',
        'el-hegaz' => 'Heliopolis - El Hegaz',
        'el-marghany' => 'Heliopolis - El Marghany',
        'gallaria' => '5th Settlement - Galleria Mall',
        'point 90 mall' => '5th Settlement - Point 90 Mall',
        'el-rebat mall' => '5th Settlement - El Rebat Mall',
        'the yard mall' => 'El Rehab - The Yard Mall',
        'madinaty' => 'Madinaty - Open Air Mall',
        'el-mokkatam' => 'El Mokattam - Mokattam 1',
        'el-mokkatam 2' => 'El Mokattam - Mokattam 2',
        'el-maadi 1' => 'Maadi - Maadi 1',
        'el-maadi 2' => 'Maadi - Maadi 2',
        'el-mohandssen' => 'El Mohandessin - Mohandessin Store',
        // The CCTV sheets put "Mall of egypt" on this record; its staff go where their violations are.
        'moe' => '6th of October - Mall of Egypt Store',
        'moa' => '6th of October - Mall of Arabia',
        'zayed' => 'Sheikh Zayed - Saraya Mall',
        'smouha' => 'Alexandria - Smouha',
        'san stefano' => 'Alexandria - San Stefano Mall',
        'al-ekabal' => 'Alexandria - El Ekbal',
        'gamal abdelnaser' => 'Gamal Abd El Nasser Store',
        'el-mansoura' => 'Mansoura - Mansoura Store',
        'el-zagazig' => 'Zagazig - Zagazig Store 1',
    ];

    /** File header => employees column. */
    public const COLUMNS = [
        'الكود' => 'code', 'الاسم' => 'name', 'كود المدير المباشر' => 'manager_code', 'المدير المباشر' => 'manager_name',
        'الوظيفة' => 'job_title', 'القسم' => 'section', 'الإدارة' => 'department', 'القطاع' => 'sector',
        'مكان العمل' => 'work_location', 'الحالة' => 'status', 'رقم الهاتف' => 'phone', 'البريد الإلكتروني' => 'email',
    ];

    /** Fields compared to tell an updated employee from an unchanged one. */
    private const TRACKED = ['name', 'job_title', 'manager_code', 'section', 'department', 'sector', 'work_location', 'branch_id', 'phone', 'active'];

    /**
     * @return array{people: array<string, array>, links: array<int, string>, merges: array<int, int>, ambiguous: list<array>,
     *               new: list<string>, changed: list<string>, gone: list<string>, places: array<string, int>}
     */
    public function plan(string $path): array
    {
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        $head = array_map(fn ($h) => trim((string) $h), array_shift($rows) ?? []);
        $at = [];
        foreach (self::COLUMNS as $label => $field) {
            $i = array_search($label, $head, true);
            if ($i === false && in_array($field, ['code', 'name'], true)) {
                throw ValidationException::withMessages(['file' => dt("العمود «{$label}» مش موجود في الملف.", "Column «{$label}» is missing.")]);
            }
            $at[$field] = $i === false ? null : $i;
        }

        $branchIds = Branch::pluck('id', 'branch_name');
        $people = [];
        foreach ($rows as $r) {
            $v = fn (string $f) => $at[$f] === null ? null : (trim((string) ($r[$at[$f]] ?? '')) ?: null);
            if (! $v('code') || ! $v('name')) {
                continue;
            }
            $place = $v('work_location');
            $person = [
                'code' => $v('code'), 'name' => preg_replace('/\s+/u', ' ', $v('name')),
                'manager_code' => $v('manager_code'), 'manager_name' => $v('manager_name'), 'job_title' => mb_substr((string) $v('job_title'), 0, 100) ?: null,
                'section' => $v('section'), 'department' => $v('department'), 'sector' => $v('sector'), 'work_location' => $place,
                'phone' => $v('phone'), 'email' => $v('email'),
                'branch_id' => $branchIds[self::BRANCHES[mb_strtolower((string) $place)] ?? ''] ?? null,
                'active' => $v('status') === null || $v('status') === 'نشط',
            ];
            // A column the file does not have leaves what the system holds untouched.
            $people[$v('code')] = array_filter($person, fn ($f) => self::inFile($f, $at), ARRAY_FILTER_USE_KEY);
        }
        if ($people === []) {
            throw ValidationException::withMessages(['file' => dt('مفيش موظفين في الملف.', 'The file has no employees.')]);
        }

        $links = [];
        $merges = [];
        $ambiguous = [];
        $coded = Employee::whereNotNull('code')->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [(string) $code => (int) $id]);
        $staff = collect($people)->whereNotNull('branch_id');
        $byBranch = $staff->groupBy('branch_id');
        foreach (Employee::whereNull('code')->with('branch')->orderBy('id')->get() as $old) {
            $hits = ($byBranch[$old->branch_id] ?? collect())->filter(fn ($p) => self::startsWithName($p['name'], $old->name));
            if ($hits->count() !== 1 && count(preg_split('/\s+/u', trim($old->name))) >= 2) {
                $hits = $staff->filter(fn ($p) => self::startsWithName($p['name'], $old->name));
            }
            if ($hits->count() !== 1) {
                $ambiguous[] = ['id' => $old->id, 'name' => $old->name, 'branch' => $old->branch?->branch_name, 'candidates' => $hits->count()];

                continue;
            }
            $code = (string) $hits->first()['code'];
            $first = array_search($code, array_map('strval', $links), true);
            if (isset($coded[$code])) {
                // The employee is already on file (loaded before these CCTV names came in): merge into them.
                $merges[$old->id] = $coded[$code];
            } elseif ($first === false) {
                $links[$old->id] = $code;
            } else {
                $merges[$old->id] = $first;
            }
        }

        $existing = Employee::whereNotNull('code')->get()->keyBy('code');
        $new = $changed = [];
        // Codes are text: PHP turns numeric array keys into integers.
        foreach ($people as $code => $p) {
            $e = $existing[$code] ?? null;
            if ($e === null) {
                $new[] = (string) $code;
            } elseif (collect(self::TRACKED)->contains(fn ($f) => array_key_exists($f, $p) && (string) $e->{$f} !== (string) $p[$f])) {
                $changed[] = (string) $code;
            }
        }
        $gone = $existing->where('active', true)->keys()->map(fn ($c) => (string) $c)
            ->diff(array_map('strval', array_keys($people)))->values()->all();

        return [
            'people' => $people, 'links' => $links, 'merges' => $merges, 'ambiguous' => $ambiguous,
            'new' => array_values(array_diff($new, array_values($links))), 'changed' => $changed, 'gone' => $gone,
            'places' => collect($people)->whereNull('branch_id')->pluck('work_location')->countBy()->sortDesc()->all(),
        ];
    }

    public function apply(array $plan): void
    {
        ['people' => $people, 'links' => $links, 'merges' => $merges] = $plan;

        DB::transaction(function () use ($people, $links, $merges) {
            foreach ($links as $id => $code) {
                Employee::whereKey($id)->update($people[$code] + ['updated_at' => now()]);
            }
            foreach ($merges as $id => $into) {
                Observation::where('employee_id', $id)->update(['employee_id' => $into]);
                Employee::whereKey($id)->delete();
            }
            $linked = array_flip($links);
            foreach ($people as $code => $p) {
                if (! isset($linked[$code])) {
                    Employee::updateOrCreate(['code' => (string) $code], $p);
                }
            }
            Employee::whereNotNull('code')->whereNotIn('code', array_map('strval', array_keys($people)))->update(['active' => false]);
        });
    }

    /** Whether a person field comes from a column the file has (the branch from the place of work, active from the status). */
    private static function inFile(string $field, array $at): bool
    {
        return match ($field) {
            'branch_id' => $at['work_location'] !== null,
            'active' => true,
            default => ($at[$field] ?? null) !== null,
        };
    }

    /** "منه الله تامر" starts with "منه تامر"? No. With "منه الله"? Yes. Whole words, Arabic spellings folded. */
    public static function startsWithName(string $full, string $short): bool
    {
        $short = Employee::normalizeName($short);
        if ($short === '') {
            return false;
        }
        $prefix = '';
        foreach (preg_split('/\s+/u', trim($full)) as $word) {
            $prefix .= Employee::normalizeName($word);
            if ($prefix === $short) {
                return true;
            }
            if (mb_strlen($prefix) >= mb_strlen($short)) {
                return false;
            }
        }

        return false;
    }
}
