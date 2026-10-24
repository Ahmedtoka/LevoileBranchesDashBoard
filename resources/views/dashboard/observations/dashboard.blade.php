@extends('layouts.app')
@section('title', dt('داشبورد الملاحظات', 'Observations dashboard'))
@push('head')<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>@endpush

@section('content')
@php
    $selected = fn (string $key, $value) => (string) $filters->get($key) === (string) $value;
    $register = fn (array $extra = []) => route('observations.index', $qs + $extra);
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    $hours = fn ($v) => $v === null ? '—' : $v.' '.dt('س', 'h');
    // "متأخر الآن" is not bounded by the period: its links carry the non-range filters only.
    $overdueLink = route('observations.index', array_diff_key($qs, array_flip(['range', 'from', 'to'])) + ['kind' => 'issue']);
    $notPeriod = dt('غير مرتبط بالفترة المختارة', 'Not limited to the selected period');

    // The six KPI tiles (spec 12): key => [label, icon, colour, register link].
    $tiles = [
        'visits' => [dt('زيارات الإدارة', 'Department visits'), 'bi-geo-alt', 'primary', $register()],
        'branches_visited' => [dt('فروع تمت زيارتها', 'Branches visited'), 'bi-shop', 'info', $register()],
        'observations_per_visit' => [dt('ملاحظات لكل زيارة', 'Observations per visit'), 'bi-journal-text', 'secondary', $register()],
        'open_issues' => [dt('مشاكل مفتوحة', 'Open issues'), 'bi-exclamation-circle', 'warning', $register(['kind' => 'issue'])],
        'on_time_pct' => [dt('نسبة الحل في المهلة', 'Resolved on time'), 'bi-stopwatch', 'success', $register(['kind' => 'issue'])],
        'overdue_now' => [dt('متأخر الآن', 'Overdue now'), 'bi-alarm', 'danger', $overdueLink],
    ];
    $show = fn (string $key, $value) => $key === 'on_time_pct' ? $pct($value) : $value;

    // Stacked bar: one dataset per risk level, one bar per reporting department.
    $chartLabels = array_column($metrics['risk_by_department'], 'department');
    $chartSets = $riskLevels->map(fn ($r) => [
        'label' => dt($r->name_ar, $r->name_en),
        'data' => array_map(fn ($row) => $row['counts'][$r->slug] ?? 0, $metrics['risk_by_department']),
        'backgroundColor' => $r->color,
    ])->values();
@endphp

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-speedometer2 text-primary me-1"></i> {{ dt('داشبورد الملاحظات', 'Observations dashboard') }}</h4>
    <a href="{{ $register() }}" class="btn btn-sm btn-outline-primary ms-auto">
        <i class="bi bi-list-ul"></i> {{ dt('سجل الملاحظات', 'Observations register') }}
    </a>
    <a href="{{ route('observations.beforeAfter') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-images"></i> {{ dt('كنا وبقينا', 'Before & after') }}
    </a>
</div>

{{-- Date presets keep every other filter (fullUrlWithQuery); the form below keeps the range. --}}
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <div class="btn-group btn-group-sm">
        @foreach(\App\Support\DateRange::$presets as $key => $label)
            @continue($key === 'custom')
            <a href="{{ request()->fullUrlWithQuery(['range' => $key, 'from' => null, 'to' => null]) }}"
               class="btn {{ $range->key === $key ? 'btn-primary' : 'btn-outline-secondary' }}">{{ t('range.'.$key, $label) }}</a>
        @endforeach
    </div>
    <span class="text-muted small ms-auto">{{ $range->from->format('d M') }} – {{ $range->to->format('d M Y') }}</span>
</div>

<form class="card p-3 mb-3" method="GET" action="{{ route('observations.dashboard') }}">
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
            <label class="form-label small mb-1">{{ dt('الإدارة المسؤولة', 'Responsible dept') }}</label>
            <select name="responsible_department_id" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($departments as $d)<option value="{{ $d->id }}" @selected($selected('responsible_department_id', $d->id))>{{ $d->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ dt('المنطقة', 'Area') }}</label>
            <select name="area_id" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($areas as $a)<option value="{{ $a->id }}" @selected($selected('area_id', $a->id))>{{ dt($a->name_ar ?: $a->name, $a->name) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ t('common.branch', 'الفرع') }}</label>
            <select name="branch_id" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected($selected('branch_id', $b->id))>{{ $b->branch_name }}</option>@endforeach
            </select>
        </div>
        <div class="col-12">
            <button class="btn btn-sm btn-primary">{{ t('common.apply', 'تطبيق') }}</button>
            <a href="{{ route('observations.dashboard') }}" class="btn btn-sm btn-outline-secondary">{{ t('common.reset', 'مسح') }}</a>
        </div>
    </div>
</form>

{{-- KPI tiles: each opens the register with the same range and filters. --}}
<div class="row g-2 mb-3">
    @foreach($tiles as $key => [$label, $icon, $colour, $href])
        <div class="col-lg-2 col-md-4 col-6">
            <a href="{{ $href }}" class="card stat-card p-2 text-center h-100 text-decoration-none">
                <div class="value text-{{ $colour }}" style="font-size:1.5rem">{{ $show($key, $metrics[$key]) }}</div>
                <div class="text-muted" style="font-size:.75rem"><i class="bi {{ $icon }}"></i> {{ $label }}</div>
                @if($key === 'overdue_now')<div class="text-muted" style="font-size:.65rem">{{ $notPeriod }}</div>@endif
            </a>
        </div>
    @endforeach
</div>

{{-- Week comparison (spec 12): the 7 days ending at the range end vs the 7 days before. --}}
<div class="card p-3 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <h6 class="fw-bold mb-0">{{ dt('مقارنة الأسابيع', 'Week comparison') }}</h6>
        <span class="text-muted small">
            {{ $weeks['current']->from->format('d M') }} – {{ $weeks['current']->to->format('d M') }}
            {{ dt('مقابل', 'vs') }}
            {{ $weeks['previous']->from->format('d M') }} – {{ $weeks['previous']->to->format('d M') }}
        </span>
        <span class="badge text-bg-light border ms-auto"><i class="bi bi-info-circle"></i> {{ dt('الفرق حجم توثيق مش بالضرورة أداء', 'The difference is documentation volume, not necessarily performance') }}</span>
    </div>
    <div class="row g-2">
        @foreach($tiles as $key => [$label])
            @php
                $cur = $metrics['week_compare']['current'][$key];
                $prev = $metrics['week_compare']['previous'][$key];
                $delta = ($cur !== null && $prev !== null) ? round($cur - $prev, 2) : null;
            @endphp
            <div class="col-lg-2 col-md-4 col-6">
                <div class="border rounded p-2 h-100">
                    <div class="text-muted" style="font-size:.72rem">{{ $label }}</div>
                    <div class="fw-bold">{{ $show($key, $cur) }} <span class="text-muted fw-normal small">/ {{ $show($key, $prev) }}</span></div>
                    @if($key === 'overdue_now')
                        <div class="text-muted" style="font-size:.65rem">{{ $notPeriod }}</div>
                    @elseif($delta !== null && $delta != 0)
                        <div class="small text-muted">{{ $delta > 0 ? '+' : '' }}{{ $key === 'on_time_pct' ? $delta.'%' : $delta }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>

{{-- Departments table: one row per reporting department. --}}
<div class="card p-0 mb-3">
    <div class="p-3 pb-0"><h6 class="fw-bold mb-2">{{ dt('الإدارات', 'Departments') }}</h6></div>
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr>
            <th>{{ t('common.department', 'الإدارة') }}</th>
            <th>{{ dt('زيارات', 'Visits') }}</th>
            <th>{{ dt('فروع', 'Branches') }}</th>
            <th>{{ dt('ملاحظات', 'Observations') }}</th>
            <th>{{ dt('ملاحظات / زيارة', 'Obs / visit') }}</th>
            <th>{{ dt('مشاكل مفتوحة', 'Open issues') }}</th>
            <th>{{ dt('خارج الكتالوج', 'Outside catalog') }}</th>
            <th>{{ dt('في المهلة', 'On time') }}</th>
            <th>{{ dt('متوسط الحل', 'Avg resolution') }}</th>
        </tr></thead>
        <tbody>
        @forelse($metrics['departments'] as $row)
            <tr>
                <td class="fw-semibold">
                    @if($row['department_id'])
                        <a href="{{ $register(['reporting_department_id' => $row['department_id']]) }}" class="text-decoration-none">{{ $row['department'] }}</a>
                    @else
                        {{ $row['department'] }}
                    @endif
                </td>
                <td>{{ $row['visits'] }}</td>
                <td>{{ $row['branches_visited'] }}</td>
                <td>{{ $row['observations'] }}</td>
                <td>{{ $row['observations_per_visit'] }}</td>
                <td>{{ $row['open_issues'] }}</td>
                <td>{{ $pct($row['outside_catalog_pct']) }}</td>
                <td>{{ $pct($row['on_time_pct']) }}</td>
                <td>{{ $hours($row['avg_hours']) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-muted small p-3">{{ dt('لا توجد زيارات أو ملاحظات في الفترة.', 'No visits or observations in this period.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-1">{{ dt('توزيع الريسك حسب الإدارة', 'Risk by department') }}</h6>
            @if($metrics['risk_by_department'])
                <canvas id="riskChart" height="170"></canvas>
            @else
                <p class="text-muted small mb-0">{{ dt('لا توجد ملاحظات بدرجة خطورة في الفترة.', 'No observations with a risk level in this period.') }}</p>
            @endif
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-2">{{ dt('أداء الإدارة المسؤولة', 'Responsible department performance') }}</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ t('common.department', 'الإدارة') }}</th>
                    <th>{{ dt('مقفولة', 'Closed') }}</th>
                    <th>{{ dt('في المهلة', 'On time') }}</th>
                    <th>{{ dt('متوسط الحل', 'Avg resolution') }}</th>
                </tr></thead>
                <tbody>
                @forelse($metrics['responsible_performance'] as $row)
                    <tr>
                        <td>{{ $row['department'] }}</td>
                        <td>{{ $row['closed'] }}</td>
                        <td>{{ $pct($row['on_time_pct']) }}</td>
                        <td>{{ $hours($row['avg_hours']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted small">{{ dt('لا توجد تيكتات مقفولة في الفترة.', 'No closed tickets in this period.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-2">{{ dt('الفروع الأكثر تكراراً', 'Most repeated branches') }}</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ t('common.branch', 'الفرع') }}</th>
                    <th>{{ dt('البند', 'Item') }}</th>
                    <th>{{ dt('العدد', 'Count') }}</th>
                    <th>{{ dt('آخر مرة', 'Last seen') }}</th>
                </tr></thead>
                <tbody>
                @forelse($metrics['repeated_branches'] as $row)
                    <tr>
                        <td>{{ $row['branch'] }}</td>
                        <td>{{ $row['item'] }}</td>
                        <td><span class="badge text-bg-warning">{{ $row['count'] }}</span></td>
                        <td class="text-nowrap">{{ $row['last_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted small">{{ dt('لا يوجد بند تكرر في نفس الفرع.', 'No item repeated at the same branch.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <div class="d-flex align-items-center mb-2">
                <h6 class="fw-bold mb-0">{{ dt('متأخر الآن', 'Overdue now') }}</h6>
                <span class="text-muted small ms-2">{{ $notPeriod }}</span>
                @if($metrics['overdue_now'] > $overdue->count())
                    <a href="{{ $overdueLink }}" class="small ms-auto">{{ dt('عرض الكل', 'See all') }} ({{ $metrics['overdue_now'] }})</a>
                @endif
            </div>
            <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ dt('المرجع', 'Reference') }}</th>
                    <th>{{ t('common.branch', 'الفرع') }}</th>
                    <th>{{ dt('المسؤولة', 'Responsible') }}</th>
                    <th>{{ dt('الموعد', 'Due') }}</th>
                    <th>{{ dt('أيام التأخير', 'Days overdue') }}</th>
                </tr></thead>
                <tbody>
                @forelse($overdue as $ticket)
                    <tr>
                        <td class="text-nowrap">
                            @if($ticket->observation)
                                <a href="{{ route('observations.show', $ticket->observation) }}" class="fw-semibold text-decoration-none">{{ $ticket->observation->reference }}</a>
                            @endif
                            <a href="{{ route('tickets.show', $ticket) }}" class="small text-muted text-decoration-none">{{ $ticket->reference }}</a>
                        </td>
                        <td>{{ $ticket->observation?->branch?->branch_name ?? '—' }}</td>
                        <td>{{ $ticket->department?->name ?? '—' }}</td>
                        <td class="text-nowrap">{{ $ticket->due_at->format('Y-m-d H:i') }}</td>
                        <td><span class="badge text-bg-danger">{{ max(0, (int) $ticket->due_at->diffInDays(now())) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted small">{{ dt('لا يوجد متأخر.', 'Nothing overdue.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Picking a date by hand switches the preset to a custom range.
    document.querySelectorAll('.js-custom-date').forEach(function (el) {
        el.addEventListener('change', function () { document.getElementById('obsRange').value = 'custom'; });
    });

    (function () {
        var canvas = document.getElementById('riskChart');
        if (!canvas || typeof Chart === 'undefined') return;
        new Chart(canvas, {
            type: 'bar',
            data: { labels: @json($chartLabels), datasets: @json($chartSets) },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } },
                scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    })();
</script>
@endpush
