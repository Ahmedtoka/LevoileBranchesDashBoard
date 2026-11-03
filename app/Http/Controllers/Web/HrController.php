<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Hr\EmployeesImporter;
use App\Support\CctvReport;
use App\Support\HrReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The HR menu (owner request 2026-11-03): the staff directory (summary, filters, list), one page per
 * employee (details, manager, team, CCTV violations), and uploading a new HR export with a preview first.
 */
class HrController extends Controller
{
    /** Filters read from the query string => employees column. */
    private const FILTERS = ['sector', 'department', 'section', 'work_location', 'job_title', 'manager_code'];

    public function summary(Request $request)
    {
        abort_unless(HrReport::canView($request->user()), 403);
        $filtered = $this->listQuery($request);
        $perPage = in_array($request->integer('per_page'), [25, 50, 100, 200], true) ? $request->integer('per_page') : 50;
        $active = Employee::where('active', true);

        return view('dashboard.hr.summary', [
            'employees' => $this->sorted($request, (clone $filtered)->with('branch')->withCount('observations'))->paginate($perPage)->withQueryString(),
            'perPage' => $perPage,
            'total' => (clone $filtered)->count(),
            'stats' => [
                'active' => (clone $active)->count(),
                'branches' => (clone $active)->whereNotNull('branch_id')->count(),
                'branch_count' => (clone $active)->whereNotNull('branch_id')->distinct()->count('branch_id'),
                'managers' => (clone $active)->whereIn('code', Employee::where('active', true)->whereNotNull('manager_code')->select('manager_code'))->count(),
                'with_violations' => (clone $active)->has('observations')->count(),
                'inactive' => Employee::where('active', false)->count(),
                'no_code' => Employee::whereNull('code')->count(),
                'updated' => Employee::max('updated_at'),
            ],
            'bySector' => (clone $active)->selectRaw("COALESCE(sector, '—') s, COUNT(*) n")->groupBy('s')->orderByDesc('n')->pluck('n', 's')->all(),
            'byPlace' => (clone $active)->selectRaw("COALESCE(work_location, '—') p, COUNT(*) n")->groupBy('p')->orderByDesc('n')->pluck('n', 'p')->all(),
            'options' => collect(self::FILTERS)->reject(fn ($f) => $f === 'manager_code')
                ->mapWithKeys(fn ($f) => [$f => Employee::whereNotNull($f)->distinct()->orderBy($f)->pluck($f)->all()])->all(),
            'manager' => $request->query('manager_code') ? Employee::where('code', (string) $request->query('manager_code'))->first() : null,
            'canManage' => HrReport::canManage($request->user()),
        ]);
    }

    public function show(Request $request, Employee $employee)
    {
        abort_unless(HrReport::canView($request->user()), 403);
        $employee->load('branch');

        return view('dashboard.hr.show', [
            'e' => $employee,
            'manager' => $employee->manager_code ? Employee::where('code', $employee->manager_code)->first() : null,
            'team' => $employee->code ? Employee::where('manager_code', $employee->code)->where('active', true)->with('branch')->orderBy('name')->get() : collect(),
            'violations' => $employee->observations()->with(['branch', 'category', 'catalogItem', 'reportingDepartment'])
                ->orderByDesc('event_date')->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(HrReport::canView($request->user()), 403);
        $query = $this->sorted($request, $this->listQuery($request)->with('branch')->withCount('observations'));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['الكود', 'الاسم', 'الوظيفة', 'القسم', 'الإدارة', 'القطاع', 'مكان العمل', 'الفرع', 'كود المدير', 'المدير المباشر', 'الهاتف', 'الحالة', 'المخالفات']);
            foreach ($query->cursor() as $e) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v, [
                    $e->code, $e->name, $e->job_title, $e->section, $e->department, $e->sector, $e->work_location,
                    $e->branch ? CctvReport::storeName($e->branch->branch_name) : '', $e->manager_code, $e->manager_name, $e->phone,
                    $e->active ? 'نشط' : 'غير نشط', $e->observations_count,
                ]));
            }
            fclose($out);
        }, 'employees-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** GET /hr/import: the upload form. */
    public function importForm(Request $request)
    {
        abort_unless(HrReport::canManage($request->user()), 403);

        return view('dashboard.hr.import', ['plan' => null, 'token' => null]);
    }

    /** POST /hr/import: keep the file and show what it would change; nothing is written yet. */
    public function preview(Request $request, EmployeesImporter $importer)
    {
        abort_unless(HrReport::canManage($request->user()), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240']], [], ['file' => 'ملف الموظفين']);

        $token = (string) Str::uuid();
        $request->file('file')->storeAs('hr-imports', $token.'.xlsx', 'local');
        $plan = $importer->plan(Storage::disk('local')->path('hr-imports/'.$token.'.xlsx'));

        return view('dashboard.hr.import', [
            'plan' => $plan, 'token' => $token, 'fileName' => $request->file('file')->getClientOriginalName(),
            'existing' => Employee::whereIn('code', array_merge($plan['changed'], $plan['gone']))->get()->keyBy('code'),
            'branchNames' => Branch::pluck('branch_name', 'id')->map(fn ($n) => CctvReport::storeName($n)),
        ]);
    }

    /** POST /hr/import/apply: write the previewed file. */
    public function apply(Request $request, EmployeesImporter $importer): RedirectResponse
    {
        abort_unless(HrReport::canManage($request->user()), 403);
        $token = (string) $request->input('token');
        $path = 'hr-imports/'.$token.'.xlsx';
        abort_unless(Str::isUuid($token) && Storage::disk('local')->exists($path), 404);

        $plan = $importer->plan(Storage::disk('local')->path($path));
        $importer->apply($plan);
        Storage::disk('local')->delete($path);

        return redirect()->route('hr.summary')->with('status', dt(
            sprintf('اتحدّث دليل الموظفين: %d في الملف، %d جديد، %d اتعدّل، %d بقوا غير نشطين.', count($plan['people']), count($plan['new']), count($plan['changed']), count($plan['gone'])),
            'The staff directory was updated.'
        ));
    }

    private function listQuery(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status', 'active');

        return Employee::query()
            ->when($status === 'active', fn ($w) => $w->where('active', true))
            ->when($status === 'inactive', fn ($w) => $w->where('active', false))
            ->when($request->query('place') === 'branches', fn ($w) => $w->whereNotNull('branch_id'))
            ->when($request->query('place') === 'office', fn ($w) => $w->whereNull('branch_id'))
            ->when($request->integer('branch_id'), fn ($w, $id) => $w->where('branch_id', $id))
            ->when($request->query('violations') === 'yes', fn ($w) => $w->has('observations'))
            ->when(true, function ($w) use ($request) {
                foreach (self::FILTERS as $f) {
                    if (($v = trim((string) $request->query($f, ''))) !== '') {
                        $w->where($f, $v);
                    }
                }
            })
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")->orWhere('job_title', 'like', "%{$q}%")));
    }

    private function sorted(Request $request, $query)
    {
        return match ($request->query('sort')) {
            'code' => $query->orderByRaw('code IS NULL, CAST(code AS UNSIGNED)'),
            'place' => $query->orderBy('work_location')->orderBy('name'),
            'department' => $query->orderBy('department')->orderBy('section')->orderBy('name'),
            'violations' => $query->orderByDesc('observations_count')->orderBy('name'),
            default => $query->orderBy('name'),
        };
    }
}
