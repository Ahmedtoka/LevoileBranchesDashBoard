@extends('layouts.app')
@section('title', dt('دليل الموظفين', 'Staff directory'))
@include('dashboard.cctv.partials.style')
@include('dashboard.cctv.partials.list-style')

@section('content')
@php
    use App\Support\CctvReport;

    $q = request()->query();
    $with = fn (array $c) => route('hr.summary', array_filter(array_merge($q, $c, ['page' => null]), fn ($v) => $v !== null && $v !== ''));
    $labels = ['sector' => dt('القطاع', 'Sector'), 'department' => dt('الإدارة', 'Department'), 'section' => dt('القسم', 'Section'),
        'work_location' => dt('مكان العمل', 'Place of work'), 'job_title' => dt('الوظيفة', 'Job')];
    $branches = \App\Models\Branch::where('active', true)->get()->sortBy(fn ($b) => CctvReport::storeName($b->branch_name), SORT_NATURAL)->values();

    $chips = [];
    if (request('q')) { $chips[] = ['«'.request('q').'»', ['q' => null]]; }
    if (request('place')) { $chips[] = [request('place') === 'branches' ? dt('في الفروع', 'In branches') : dt('خارج الفروع', 'Outside branches'), ['place' => null]]; }
    if (request('branch_id')) { $chips[] = [CctvReport::storeName($branches->firstWhere('id', (int) request('branch_id'))?->branch_name), ['branch_id' => null]]; }
    foreach ($labels as $f => $l) { if (request($f)) { $chips[] = [$l.': '.request($f), [$f => null]]; } }
    if (request('manager_code')) { $chips[] = [dt('فريق ', 'Team of ').($manager?->name ?? request('manager_code')), ['manager_code' => null]]; }
    if (request('status') && request('status') !== 'active') { $chips[] = [request('status') === 'inactive' ? dt('غير نشط', 'Inactive') : dt('الكل', 'All'), ['status' => null]]; }
    if (request('violations')) { $chips[] = [dt('عليهم مخالفات', 'With violations'), ['violations' => null]]; }
    $maxSector = max($bySector ?: [1]);
    $maxPlace = max($byPlace ?: [1]);
@endphp

<div class="cv hr">
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold mb-0">{{ dt('دليل الموظفين', 'Staff directory') }}</h4>
        @if($stats['updated'])<small class="text-muted">{{ dt('آخر تحديث', 'Last updated') }} {{ \Illuminate\Support\Carbon::parse($stats['updated'])->format('j/n/Y') }}</small>@endif
    </div>
    <div class="ms-auto d-flex gap-2 d-print-none">
        @if($canManage)<a href="{{ route('hr.import') }}" class="btn btn-sm btn-primary"><i class="bi bi-cloud-arrow-up"></i> {{ dt('تحديث ملف الموظفين', 'Update the staff file') }}</a>@endif
        <a href="{{ route('hr.export', $q) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> {{ dt('تنزيل Excel', 'Download Excel') }} ({{ $total }})</a>
    </div>
</div>

<section class="cv-sum">
    <div class="cv-sum-stats">
        <a class="cv-stat cv-stat-main" href="{{ route('hr.summary') }}"><span>{{ dt('موظف نشط', 'Active staff') }}</span><b>{{ number_format($stats['active']) }}</b></a>
        <a class="cv-stat" href="{{ $with(['place' => 'branches']) }}"><span>{{ dt('في الفروع', 'In branches') }}</span><b>{{ $stats['branches'] }} <small>{{ $stats['branch_count'] }} {{ dt('فرع', 'branches') }}</small></b><i class="bi bi-chevron-left"></i></a>
        <a class="cv-stat" href="{{ $with(['place' => 'office']) }}"><span>{{ dt('خارج الفروع', 'Outside branches') }}</span><b>{{ $stats['active'] - $stats['branches'] }}</b><i class="bi bi-chevron-left"></i></a>
        <div class="cv-stat"><span>{{ dt('مديرين مباشرين', 'Line managers') }}</span><b>{{ $stats['managers'] }}</b></div>
        <a class="cv-stat" href="{{ $with(['violations' => 'yes', 'sort' => 'violations']) }}"><span>{{ dt('عليهم مخالفات CCTV', 'With CCTV violations') }}</span><b>{{ $stats['with_violations'] }}</b><i class="bi bi-chevron-left"></i></a>
        <a class="cv-stat" href="{{ $with(['status' => 'inactive']) }}"><span>{{ dt('غير نشطين', 'Inactive') }}</span><b>{{ $stats['inactive'] }}</b><i class="bi bi-chevron-left"></i></a>
        <div class="cv-stat" title="{{ dt('أسماء من شيتات الكاميرات ماتربطتش بموظف في ملف HR', 'Names from the CCTV sheets not matched to the HR file') }}"><span>{{ dt('أسماء من غير كود', 'Names without a code') }}</span><b>{{ $stats['no_code'] }}</b></div>
    </div>
</section>

<div class="hr-split">
    <section class="cv-panel">
        <h2 class="cv-h">{{ dt('حسب القطاع', 'By sector') }}</h2>
        <ul class="hr-bars">
            @foreach($bySector as $name => $n)
                <li><a href="{{ $with(['sector' => $name === '—' ? null : $name]) }}" @class(['on' => request('sector') === $name])>
                    <span class="hr-bar-label">{{ $name }}</span>
                    <span class="hr-bar"><i style="inline-size: {{ round($n * 100 / $maxSector) }}%"></i></span>
                    <b>{{ $n }}</b></a></li>
            @endforeach
        </ul>
    </section>
    <section class="cv-panel">
        <h2 class="cv-h">{{ dt('حسب مكان العمل', 'By place of work') }}</h2>
        <ul class="hr-bars hr-bars-scroll">
            @foreach($byPlace as $name => $n)
                <li><a href="{{ $with(['work_location' => $name === '—' ? null : $name]) }}" @class(['on' => request('work_location') === $name])>
                    <span class="hr-bar-label">{{ trim($name) }}</span>
                    <span class="hr-bar"><i style="inline-size: {{ round($n * 100 / $maxPlace) }}%"></i></span>
                    <b>{{ $n }}</b></a></li>
            @endforeach
        </ul>
    </section>
</div>

<form method="GET" action="{{ route('hr.summary') }}" class="cv-bar cv-filters" id="hrFilters">
    <div class="cv-bar-row">
        <span class="cv-search hr-q"><i class="bi bi-search"></i><input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="{{ dt('الاسم، الكود، التليفون أو الوظيفة', 'Name, code, phone or job') }}" autofocus></span>
        <button class="btn btn-primary btn-sm">{{ dt('بحث', 'Search') }}</button>
    </div>
    <div class="cv-fields">
        <label>{{ dt('الفرع', 'Branch') }}
            <select name="branch_id" class="form-select form-select-sm js-auto">
                <option value="">{{ dt('كل الفروع', 'All branches') }}</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) request('branch_id') === (string) $b->id)>{{ CctvReport::storeName($b->branch_name) }}</option>@endforeach
            </select>
        </label>
        @foreach($labels as $f => $l)
            <label>{{ $l }}
                <select name="{{ $f }}" class="form-select form-select-sm js-auto">
                    <option value="">{{ dt('الكل', 'All') }}</option>
                    @foreach($options[$f] as $v)<option value="{{ $v }}" @selected(request($f) === $v)>{{ $v }}</option>@endforeach
                </select>
            </label>
        @endforeach
        <label>{{ dt('الحالة', 'Status') }}
            <select name="status" class="form-select form-select-sm js-auto">
                <option value="active" @selected(request('status', 'active') === 'active')>{{ dt('نشط', 'Active') }}</option>
                <option value="inactive" @selected(request('status') === 'inactive')>{{ dt('غير نشط', 'Inactive') }}</option>
                <option value="all" @selected(request('status') === 'all')>{{ dt('الكل', 'All') }}</option>
            </select>
        </label>
        <label>{{ dt('الترتيب', 'Sort') }}
            <select name="sort" class="form-select form-select-sm js-auto">
                @foreach(['name' => dt('الاسم', 'Name'), 'code' => dt('الكود', 'Code'), 'department' => dt('الإدارة', 'Department'), 'place' => dt('مكان العمل', 'Place'), 'violations' => dt('المخالفات', 'Violations')] as $k => $l)
                    <option value="{{ $k }}" @selected(request('sort', 'name') === $k)>{{ $l }}</option>
                @endforeach
            </select>
        </label>
        @foreach(['place', 'manager_code', 'violations'] as $keep)@if(request($keep))<input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">@endif @endforeach
        <input type="hidden" name="per_page" value="{{ $perPage }}">
    </div>
    @if($chips)
        <div class="cv-chips-row">
            @foreach($chips as [$label, $clear])<a href="{{ $with($clear) }}" class="cv-chip" title="{{ dt('شيل الفلتر', 'Remove filter') }}">{{ $label }} <i class="bi bi-x"></i></a>@endforeach
            <a href="{{ route('hr.summary') }}" class="cv-chip-clear">{{ dt('شيل كل الفلاتر', 'Clear all') }}</a>
        </div>
    @endif
</form>

<div class="cv-table-wrap">
    <table class="cv-table">
        <thead><tr>
            <th>{{ dt('الكود', 'Code') }}</th>
            <th>{{ dt('الموظف', 'Employee') }}</th>
            <th>{{ dt('الإدارة / القسم', 'Department / section') }}</th>
            <th>{{ dt('مكان العمل', 'Place') }}</th>
            <th>{{ dt('المدير المباشر', 'Line manager') }}</th>
            <th>{{ dt('التليفون', 'Phone') }}</th>
            <th class="text-center">{{ dt('مخالفات', 'Violations') }}</th>
        </tr></thead>
        <tbody>
        @forelse($employees as $e)
            <tr @class(['hr-off' => ! $e->active])>
                <td class="hr-code">{{ $e->code ?? '—' }}</td>
                <td class="cv-td-emp"><a href="{{ route('hr.show', $e) }}">{{ $e->name }}</a><small>{{ $e->job_title }}@if(! $e->active) · {{ dt('غير نشط', 'inactive') }}@endif</small></td>
                <td class="small">{{ $e->department }}<small class="d-block text-muted">{{ $e->section }}</small></td>
                <td class="text-nowrap small">
                    @if($e->branch)<a href="{{ $with(['branch_id' => $e->branch_id]) }}" class="text-reset fw-semibold">{{ CctvReport::storeName($e->branch->branch_name) }}</a>
                    @else{{ trim((string) $e->work_location) }}@endif
                </td>
                <td class="small">@if($e->manager_code)<a href="{{ $with(['manager_code' => $e->manager_code]) }}" class="text-reset" title="{{ dt('فريقه', 'Their team') }}">{{ $e->manager_name }}</a>@endif</td>
                <td class="small text-nowrap" dir="ltr">{{ $e->phone }}</td>
                <td class="text-center">@if($e->observations_count)<a href="{{ route('hr.show', $e) }}#violations" class="hr-vio">{{ $e->observations_count }}</a>@endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="cv-td-empty">
                <i class="bi bi-people"></i>
                <p class="mb-1 fw-semibold">{{ dt('مفيش موظفين بالفلاتر دي.', 'No one matches these filters.') }}</p>
                @if($chips)<a href="{{ route('hr.summary') }}" class="small">{{ dt('شيل كل الفلاتر', 'Clear all filters') }}</a>@endif
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="cv-pager">
    {{ $employees->links('pagination::bootstrap-5') }}
    <span class="small text-muted ms-auto">{{ $total }} {{ dt('موظف', 'employees') }} · {{ dt('في الصفحة', 'per page') }}</span>
    <select class="form-select form-select-sm" onchange="location.href='{{ $with(['per_page' => '__']) }}'.replace('__', this.value)">
        @foreach([25, 50, 100, 200] as $n)<option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>@endforeach
    </select>
</div>
</div>
@endsection

@push('head')
<style>
    .hr-split { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
    .hr-split .cv-panel { margin: 0; }
    .hr-bars { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .15rem; }
    .hr-bars-scroll { max-block-size: 15.5rem; overflow: auto; scrollbar-width: thin; }
    .hr-bars a { display: grid; grid-template-columns: minmax(7rem, 11rem) 1fr 2.6rem; align-items: center; gap: .6rem; padding: .28rem .45rem; border-radius: 8px; color: var(--ink-2); font-size: .82rem; }
    .hr-bars a:hover, .hr-bars a.on { background: #f8f4f7; color: var(--ink); }
    .hr-bar-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hr-bar { block-size: 8px; background: #f1edf0; border-radius: 4px; overflow: hidden; }
    .hr-bar i { display: block; block-size: 100%; background: var(--brand); border-radius: 4px; min-inline-size: 3px; }
    .hr-bars b { text-align: end; color: var(--ink); }
    .hr-q { flex: 1; min-inline-size: 16rem; }
    .hr-q input { padding-block: .45rem; }
    .hr-code { font-variant-numeric: tabular-nums; color: var(--ink-2); font-weight: 700; white-space: nowrap; }
    .hr-off td { opacity: .55; }
    .hr-vio { display: inline-block; min-inline-size: 1.6rem; padding: 0 .4rem; border-radius: 7px; background: var(--up-bg); color: var(--up); font-weight: 800; font-size: .8rem; }
    @media (max-width: 991.98px) { .hr-split { grid-template-columns: 1fr; } }
</style>
@endpush

@push('scripts')
<script>
document.querySelectorAll('#hrFilters .js-auto').forEach(s => s.addEventListener('change', () => s.form.submit()));
</script>
@endpush
