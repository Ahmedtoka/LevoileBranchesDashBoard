@extends('layouts.app')
@section('title', dt('تقرير الموظفين', 'Employee report'))
@push('head')<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>@endpush

@section('content')
@php
    $selected = fn (string $key, $value) => (string) $filters->get($key) === (string) $value;
    $register = fn (array $extra = []) => route('observations.index', $qs + $extra);
    $rows = $report['rows'];
    $top = array_slice($rows, 0, 10);
@endphp

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-person-badge text-primary me-1"></i> {{ dt('تقرير الموظفين', 'Employee report') }}</h4>
    <span class="badge text-bg-light border">{{ $report['totals']['employees'] }} {{ dt('موظف', 'employees') }}</span>
    <a href="{{ $register() }}" class="btn btn-sm btn-outline-primary ms-auto"><i class="bi bi-list-ul"></i> {{ dt('سجل الملاحظات', 'Observations register') }}</a>
    <button type="button" class="btn btn-sm btn-outline-secondary d-print-none" onclick="window.print()"><i class="bi bi-printer"></i> {{ dt('طباعة', 'Print') }}</button>
</div>

<div class="d-flex flex-wrap gap-2 align-items-center mb-3 d-print-none">
    <div class="btn-group btn-group-sm">
        @foreach(\App\Support\DateRange::$presets as $key => $label)
            @continue($key === 'custom')
            <a href="{{ request()->fullUrlWithQuery(['range' => $key, 'from' => null, 'to' => null]) }}"
               class="btn {{ $range->key === $key ? 'btn-primary' : 'btn-outline-secondary' }}">{{ t('range.'.$key, $label) }}</a>
        @endforeach
        <a href="{{ request()->fullUrlWithQuery(['range' => \App\Support\ObservationFilters::ALL, 'from' => null, 'to' => null]) }}"
           class="btn {{ $range->key === \App\Support\ObservationFilters::ALL ? 'btn-primary' : 'btn-outline-secondary' }}">{{ dt('كل التواريخ', 'All dates') }}</a>
    </div>
    <span class="text-muted small ms-auto">
        @if($range->key === \App\Support\ObservationFilters::ALL) {{ dt('كل التواريخ', 'All dates') }} @else {{ $range->from->format('d M') }} – {{ $range->to->format('d M Y') }} @endif
    </span>
</div>

<form class="card p-3 mb-3 d-print-none" method="GET" action="{{ route('observations.employees') }}">
    <input type="hidden" name="range" id="obsRange" value="{{ $range->key }}">
    <div class="row g-2 align-items-end">
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ t('range.from', 'من') }}</label>
            <input type="date" name="from" value="{{ $range->customFrom }}" class="form-control form-control-sm js-custom-date">
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ t('range.to', 'إلى') }}</label>
            <input type="date" name="to" value="{{ $range->customTo }}" class="form-control form-control-sm js-custom-date">
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ dt('الإدارة المُبلِّغة', 'Reporting dept') }}</label>
            <select name="reporting_department_id" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($reportingDepartments as $d)<option value="{{ $d->id }}" @selected($selected('reporting_department_id', $d->id))>{{ $d->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ t('common.branch', 'الفرع') }}</label>
            <select name="branch_id" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected($selected('branch_id', $b->id))>{{ $b->branch_name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ dt('الموظف', 'Employee') }}</label>
            <input name="employee" value="{{ $filters->get('employee') }}" class="form-control form-control-sm" dir="auto" placeholder="{{ dt('بحث بالاسم', 'Search by name') }}">
        </div>
        <div class="col-md-2 col-12">
            <button class="btn btn-sm btn-primary">{{ t('common.apply', 'تطبيق') }}</button>
            <a href="{{ route('observations.employees') }}" class="btn btn-sm btn-outline-secondary">{{ t('common.reset', 'مسح') }}</a>
        </div>
    </div>
</form>

<div class="row g-2 mb-3">
    @foreach([
        ['employees', dt('موظفون عليهم ملاحظات', 'Employees with points'), 'bi-people', 'primary'],
        ['observations', dt('ملاحظات', 'Points'), 'bi-journal-text', 'secondary'],
        ['open_issues', dt('مشاكل مفتوحة', 'Open issues'), 'bi-exclamation-circle', 'warning'],
        ['penalties', dt('جزاءات مسجّلة', 'Penalties recorded'), 'bi-exclamation-octagon', 'danger'],
    ] as [$key, $label, $icon, $colour])
        <div class="col-md-3 col-6">
            <div class="card stat-card p-2 text-center h-100">
                <div class="value text-{{ $colour }}" style="font-size:1.5rem">{{ $report['totals'][$key] }}</div>
                <div class="text-muted" style="font-size:.75rem"><i class="bi {{ $icon }}"></i> {{ $label }}</div>
            </div>
        </div>
    @endforeach
</div>

@if(count($top) > 1)
<div class="card p-3 mb-3">
    <h6 class="fw-bold">{{ dt('أكثر الموظفين ملاحظات', 'Employees with the most points') }}</h6>
    <canvas id="employeeChart" height="{{ 40 + 28 * count($top) }}"></canvas>
</div>
@endif

<div class="card p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr>
            <th>#</th>
            <th>{{ dt('الموظف', 'Employee') }}</th>
            <th class="text-center">{{ dt('الملاحظات', 'Points') }}</th>
            @foreach($report['risk_levels'] as $r)<th class="text-center" style="color:{{ $r->color }}">{{ dt($r->name_ar, $r->name_en) }}</th>@endforeach
            <th class="text-center">{{ dt('مفتوحة', 'Open') }}</th>
            <th class="text-center">{{ dt('جزاءات', 'Penalties') }}</th>
            <th>{{ dt('الفروع', 'Branches') }}</th>
            <th>{{ dt('آخر ملاحظة', 'Last point') }}</th>
        </tr></thead>
        <tbody>
        @forelse($rows as $i => $row)
            <tr>
                <td class="text-muted">{{ $i + 1 }}</td>
                <td dir="auto" class="fw-semibold"><a href="{{ $register(['employee' => $row['employee']]) }}" class="text-decoration-none">{{ $row['employee'] }}</a></td>
                <td class="text-center fw-bold">{{ $row['observations'] }}</td>
                @foreach($report['risk_levels'] as $r)
                    <td class="text-center">@if(($row['risk'][$r->slug] ?? 0) > 0)<span class="badge" style="background:{{ $r->color }}">{{ $row['risk'][$r->slug] }}</span>@else<span class="text-muted">—</span>@endif</td>
                @endforeach
                <td class="text-center">@if($row['open_issues'])<span class="badge text-bg-warning">{{ $row['open_issues'] }}</span>@else<span class="text-muted">0</span>@endif</td>
                <td class="text-center">{{ $row['penalties'] ?: '—' }}</td>
                <td class="small">{{ implode('، ', $row['branch_names']) ?: '—' }}</td>
                <td class="text-nowrap small">{{ $row['last_at'] }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ 7 + $report['risk_levels']->count() }}" class="text-muted small p-3">{{ dt('لا توجد ملاحظات مسجّلة على موظفين في الفترة. اكتب اسم الموظف في النقطة (من الكتالوج أو الكتابة الحرة) ليظهر هنا.', 'No points recorded against employees in this period. Fill the employee on a point (catalog or free text) and it appears here.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('.js-custom-date').forEach(function (el) {
        el.addEventListener('change', function () { document.getElementById('obsRange').value = 'custom'; });
    });
    (function () {
        var canvas = document.getElementById('employeeChart');
        if (!canvas || typeof Chart === 'undefined') return;
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: @json(array_column($top, 'employee')),
                datasets: [
                    { label: @json(dt('مفتوحة', 'Open')), data: @json(array_column($top, 'open_issues')), backgroundColor: '#f59e0b' },
                    { label: @json(dt('مغلقة / أخرى', 'Closed / other')), data: @json(array_map(fn ($r) => $r['observations'] - $r['open_issues'], $top)), backgroundColor: '#94a3b8' }
                ]
            },
            options: { indexAxis: 'y', responsive: true, plugins: { legend: { position: 'bottom' } },
                scales: { x: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }, y: { stacked: true } } }
        });
    })();
</script>
@endpush
