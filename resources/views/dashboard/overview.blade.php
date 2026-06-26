@extends('layouts.app')
@section('title', t('dash.title','نظرة عامة'))

@section('content')
@php
    $ar = app()->getLocale() === 'ar';
    $rk = ['range' => $range->key, 'from' => $range->customFrom, 'to' => $range->customTo];
@endphp

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h4 class="fw-bold mb-0">
        {{ t('dash.title','نظرة عامة') }}
        @if($scopeDept)
            <span class="badge align-middle ms-1" style="background:{{ $scopeDept->color ?? '#9c1e6e' }}">{{ $scopeDept->name }}</span>
        @endif
    </h4>
    @php $isAdmin = optional(auth()->user()->role)->slug === 'super_admin' || optional(auth()->user())->is_admin; @endphp
    @if($isAdmin)
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('demo.generate') }}"
              onsubmit="return confirm('سيتم توليد بيانات ديمو كاملة لكل الأدوار. متابعة؟');">
            @csrf
            <button class="btn btn-sm btn-primary"><i class="bi bi-magic me-1"></i> {{ dt('توليد بيانات ديمو','Generate demo data') }}</button>
        </form>
        <form method="POST" action="{{ route('demo.wipe') }}"
              onsubmit="return confirm('سيتم مسح كل التذاكر والزيارات. متابعة؟');">
            @csrf
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3 me-1"></i> {{ dt('مسح البيانات','Wipe data') }}</button>
        </form>
    </div>
    @endif
</div>

@if(session('status'))
    <div class="alert alert-success py-2 small">{{ session('status') }}</div>
@endif

@include('partials.filterbar', ['range' => $range])

{{-- ===== KPI cards ===== --}}
@php
    $cards = [
        [t('dash.tickets_total','إجمالي التذاكر'), $stats['tickets_total'], 'ticket-detailed', 'dark', route('tickets.index', $rk)],
        [t('dash.tickets_open','تذاكر مفتوحة'), $stats['tickets_open'], 'exclamation-circle', 'warning', route('tickets.index', $rk + ['status' => 'open'])],
        [t('dash.waiting_approval','بانتظار الموافقة'), $stats['waiting_approval'], 'hourglass-split', 'info', route('tickets.index', $rk + ['status' => 'waiting_approval'])],
        [t('tk.closed','مقفولة'), $stats['tickets_closed'], 'check2-circle', 'success', route('tickets.index', $rk + ['status' => 'closed'])],
    ];
    if (!$scopeDept) {
        $cards = array_merge([
            [t('dash.branches','الفروع'), $stats['branches'], 'geo-alt', 'primary', route('branches.index', $rk)],
            [t('dash.visits','الزيارات'), $stats['visits_total'], 'clipboard', 'secondary', route('visits.index', $rk)],
            [t('dash.visits_completed','زيارات مكتملة'), $stats['visits_completed'], 'clipboard-check', 'success', route('visits.index', $rk + ['status' => 'completed'])],
            [t('dash.visits_open','زيارات مفتوحة'), $stats['visits_open'], 'hourglass', 'info', route('visits.index', $rk + ['status' => 'open'])],
        ], $cards);
    }
@endphp
<div class="row g-3 mb-3">
    @foreach($cards as [$label, $value, $icon, $color, $link])
        <div class="col-md-3 col-6">
            <a href="{{ $link }}" class="text-decoration-none">
                <div class="card stat-card p-3 h-100" style="cursor:pointer">
                    <div class="text-muted small"><i class="bi bi-{{ $icon }} text-{{ $color }} me-1"></i>{{ $label }}</div>
                    <div class="value text-dark">{{ $value }}</div>
                </div>
            </a>
        </div>
    @endforeach
</div>

{{-- ===== SLA / aging strip ===== --}}
<div class="row g-3 mb-4">
    @php
        $slaCards = [
            [dt('متوسط زمن الحل','Avg resolution'), $sla['avg_resolution'] !== null ? $sla['avg_resolution'].' '.dt('س','h') : '—', 'stopwatch', '#0ea5e9'],
            [dt('الالتزام بالـ SLA','SLA compliance'), $sla['sla_pct'] !== null ? $sla['sla_pct'].'%' : '—', 'shield-check', ($sla['sla_pct'] !== null && $sla['sla_pct'] >= 80 ? '#16a34a' : '#f59e0b')],
            [t('dash.overdue','متأخرة'), $stats['overdue'], 'exclamation-triangle', '#dc2626'],
            [dt('أقدم تذكرة مفتوحة','Oldest open'), $sla['oldest_hours'] !== null ? $sla['oldest_hours'].' '.dt('س','h') : '—', 'hourglass-top', '#9c1e6e'],
        ];
    @endphp
    @foreach($slaCards as [$label, $val, $icon, $clr])
        <div class="col-md-3 col-6">
            <div class="card p-3 h-100 d-flex flex-row align-items-center">
                <div class="rounded-circle d-flex align-items-center justify-content-center me-3"
                     style="width:42px;height:42px;background:{{ $clr }}1a;color:{{ $clr }}"><i class="bi bi-{{ $icon }} fs-5"></i></div>
                <div><div class="text-muted small">{{ $label }}</div><div class="fw-bold fs-5 text-dark">{{ $val }}</div></div>
            </div>
        </div>
    @endforeach
</div>

{{-- ===== Charts: trend + status ===== --}}
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">{{ dt('اتجاه التذاكر','Tickets trend') }}
                <span class="text-muted small">— {{ dt('تم الإنشاء مقابل الإغلاق','created vs closed') }} ({{ $range->label }})</span></h6>
            <canvas id="trendChart" height="110"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">{{ dt('حسب الحالة','By status') }}</h6>
            <canvas id="statusChart" height="180"></canvas>
        </div>
    </div>
</div>

{{-- ===== By source ===== --}}
<h6 class="fw-bold mb-2">{{ t('dash.by_source','التذاكر حسب المصدر') }}</h6>
<div class="row g-3 mb-4">
    @php
        $sources = [
            [t('dash.src_store','شيك ليست مدير الفرع'), $bySource['store'] ?? 0, 'clipboard-check', '#6366f1', ['source' => 'store']],
            [t('dash.src_area','زيارات الأريا مانجر'), $bySource['area'] ?? 0, 'binoculars', '#0d9488', ['source' => 'area']],
            [t('dash.src_maintenance','طلبات الصيانة'), $bySource['maintenance'] ?? 0, 'tools', '#9C1E6E', ['source' => 'maintenance']],
        ];
    @endphp
    @foreach($sources as [$label, $val, $icon, $clr, $q])
        <div class="col-md-4">
            <a href="{{ route('tickets.index', $rk + $q) }}" class="text-decoration-none">
                <div class="card p-3 h-100 d-flex flex-row align-items-center" style="cursor:pointer">
                    <div class="rounded-circle d-flex align-items-center justify-content-center me-3"
                         style="width:46px;height:46px;background:{{ $clr }}1a;color:{{ $clr }}"><i class="bi bi-{{ $icon }} fs-5"></i></div>
                    <div class="flex-grow-1"><div class="text-muted small">{{ $label }}</div>
                        <div class="fw-bold fs-4 text-dark">{{ $val }}</div></div>
                    <i class="bi bi-chevron-{{ $ar ? 'left' : 'right' }} text-muted"></i>
                </div>
            </a>
        </div>
    @endforeach
</div>

{{-- ===== Department breakdown (admin only) ===== --}}
@unless($scopeDept)
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">{{ t('dash.by_department','التذاكر حسب الإدارة') }} <span class="text-muted small">({{ $range->label }})</span></h6>
            <canvas id="deptChart" height="120"></canvas>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">{{ t('common.department','الإدارة') }}</h6>
            <div style="max-height:300px;overflow:auto">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>{{ t('common.department','الإدارة') }}</th><th class="text-center">{{ t('common.total','الإجمالي') }}</th><th class="text-center">{{ t('tk.open','مفتوحة') }}</th><th></th></tr></thead>
                <tbody>
                @foreach($byDept as $d)
                    <tr>
                        <td><span class="badge rounded-circle p-0" style="width:9px;height:9px;background:{{ $d->color }}"></span> {{ $d->name }}</td>
                        <td class="text-center">{{ $d->total_count }}</td>
                        <td class="text-center"><span class="text-warning fw-semibold">{{ $d->open_count }}</span></td>
                        <td class="text-end"><a href="{{ route('departments.board', $d) }}" class="btn btn-sm btn-outline-secondary py-0">{{ t('dept.board','اللوحة') }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
@endunless

{{-- ===== Team performance + Top branches ===== --}}
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">{{ dt('أداء الفريق','Team performance') }}</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ t('common.name','الاسم') }}</th>
                    @unless($scopeDept)<th>{{ t('common.department','الإدارة') }}</th>@endunless
                    <th class="text-center">{{ dt('مُعيّنة','Assigned') }}</th>
                    <th class="text-center">{{ t('tk.closed','مقفولة') }}</th>
                    <th class="text-center">{{ dt('قيد العمل','Pending') }}</th>
                    <th class="text-center">{{ dt('متوسط (س)','Avg (h)') }}</th>
                    <th style="width:120px">{{ dt('الإنجاز','Done') }}</th>
                </tr></thead>
                <tbody>
                @forelse($team as $u)
                    <tr>
                        <td class="fw-semibold">{{ $u->name }}</td>
                        @unless($scopeDept)<td class="small text-muted">{{ optional($u->department)->name }}</td>@endunless
                        <td class="text-center">{{ $u->assigned }}</td>
                        <td class="text-center text-success">{{ $u->closed }}</td>
                        <td class="text-center text-warning">{{ $u->pending }}</td>
                        <td class="text-center">{{ $u->avg_hours ?? '—' }}</td>
                        <td>
                            <div class="progress" style="height:16px">
                                <div class="progress-bar {{ $u->rate >= 80 ? 'bg-success' : ($u->rate >= 50 ? 'bg-warning' : 'bg-danger') }}"
                                     style="width:{{ $u->rate }}%">{{ $u->rate }}%</div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted small">{{ t('common.no_data','لا توجد بيانات.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">{{ dt('أكثر الفروع طلبات (مفتوحة)','Top branches (open)') }}</h6>
            @php $maxB = optional($topBranches->first())->total ?: 1; @endphp
            @forelse($topBranches as $b)
                <div class="mb-2">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>{{ optional($b->branch)->branch_name ?? '—' }}</span><strong>{{ $b->total }}</strong>
                    </div>
                    <div class="progress" style="height:7px">
                        <div class="progress-bar" style="width:{{ round($b->total / $maxB * 100) }}%;background:#9c1e6e"></div>
                    </div>
                </div>
            @empty
                <p class="text-muted small mb-0">{{ t('common.no_data','لا توجد بيانات.') }}</p>
            @endforelse
        </div>
    </div>
</div>

{{-- ===== Repeated + Recent ===== --}}
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">{{ t('dash.repeated','طلبات متكررة') }}</h6>
            @forelse($repeated as $row)
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span><span class="badge text-bg-light text-capitalize">{{ $row->category }}</span> {{ optional($row->branch)->branch_name }}</span>
                    <strong>{{ $row->total }}×</strong>
                </div>
            @empty
                <p class="text-muted small mb-0">{{ t('dash.no_repeated','لا توجد طلبات متكررة في الفترة.') }}</p>
            @endforelse
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">{{ t('dash.recent','أحدث التذاكر') }}</h6>
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>{{ t('tk.code','الكود') }}</th><th>{{ t('tk.subject','العنوان') }}</th><th>{{ t('common.branch','الفرع') }}</th><th>{{ t('tk.status','الحالة') }}</th></tr></thead>
                <tbody>
                @forelse($recentTickets as $t)
                    <tr onclick="window.location='{{ route('tickets.show', $t) }}'" style="cursor:pointer">
                        <td class="fw-semibold">{{ $t->reference }}</td>
                        <td>{{ Str::limit($t->title, 40) }}</td>
                        <td class="small">{{ optional($t->branch)->branch_name }}</td>
                        <td>@include('partials.status-badge', ['status' => $t->status])</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted small">{{ t('dash.no_tickets','لا توجد تذاكر في الفترة.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@php
    $statusMeta = [
        'open' => '#f59e0b', 'assigned' => '#6366f1', 'on_the_way' => '#0ea5e9', 'in_progress' => '#3b82f6',
        'waiting_approval' => '#a855f7', 'postponed' => '#f97316', 'not_fixed' => '#ef4444', 'rejected' => '#dc2626', 'closed' => '#16a34a',
    ];
    $stLabels = []; $stValues = []; $stColors = [];
    foreach ($statusMeta as $k => $clr) {
        if (($byStatus[$k] ?? 0) > 0) { $stLabels[] = t('status.'.$k, $k); $stValues[] = $byStatus[$k]; $stColors[] = $clr; }
    }
@endphp
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;
    Chart.defaults.font.family = 'inherit';

    // Trend
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: @json($trend['labels']),
            datasets: [
                { label: @json(dt('تم الإنشاء','Created')), data: @json($trend['created']),
                  borderColor: '#9c1e6e', backgroundColor: 'rgba(156,30,110,.12)', fill: true, tension: .35, borderWidth: 2, pointRadius: 2 },
                { label: @json(dt('تم الإغلاق','Closed')), data: @json($trend['closed']),
                  borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.10)', fill: true, tension: .35, borderWidth: 2, pointRadius: 2 },
            ],
        },
        options: { responsive: true, maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
    });

    // Status doughnut
    const stVals = @json($stValues);
    if (stVals.length) {
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: { labels: @json($stLabels), datasets: [{ data: stVals, backgroundColor: @json($stColors), borderWidth: 1 }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } },
        });
    }

    @unless($scopeDept)
    // Department bar
    const dEl = document.getElementById('deptChart');
    if (dEl) new Chart(dEl, {
        type: 'bar',
        data: {
            labels: @json($byDept->pluck('name')),
            datasets: [
                { label: @json(t('tk.open','مفتوحة')), data: @json($byDept->pluck('open_count')), backgroundColor: '#f59e0b' },
                { label: @json(t('tk.closed','مقفولة')), data: @json($byDept->pluck('closed_count')), backgroundColor: '#16a34a' },
            ],
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } },
            scales: { x: { stacked: false, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, ticks: { precision: 0 } } } },
    });
    @endunless
});
</script>
@endpush
