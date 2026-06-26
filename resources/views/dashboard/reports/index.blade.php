@extends('layouts.app')
@section('title', t('rep.title','التقارير'))

@section('content')
<h4 class="fw-bold mb-3">
    {{ t('rep.title','التقارير') }}
    @if($scopeDept)<span class="badge align-middle ms-1" style="background:{{ $scopeDept->color ?? '#9c1e6e' }}">{{ $scopeDept->name }}</span>@endif
</h4>

@include('partials.filterbar', ['range' => $range])

{{-- SLA summary --}}
<div class="row g-3 mb-3">
    @php
        $slaCards = [
            [dt('متوسط زمن الحل','Avg resolution'), $sla['avg_resolution'] !== null ? $sla['avg_resolution'].' '.dt('س','h') : '—', 'stopwatch', '#0ea5e9'],
            [dt('الالتزام بالـ SLA','SLA compliance'), $sla['sla_pct'] !== null ? $sla['sla_pct'].'%' : '—', 'shield-check', ($sla['sla_pct'] !== null && $sla['sla_pct'] >= 80 ? '#16a34a' : '#f59e0b')],
            [t('tk.closed','مقفولة'), $sla['closed'], 'check2-circle', '#16a34a'],
            [t('rep.overdue','التذاكر المتأخرة'), $overdue->count(), 'exclamation-triangle', '#dc2626'],
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

{{-- tickets by status chart --}}
<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">{{ dt('التذاكر حسب الحالة','Tickets by status') }} <span class="text-muted small">({{ $range->label }})</span></h6>
            <canvas id="repStatusChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">{{ t('dash.by_source','التذاكر حسب المصدر') }}</h6>
            <canvas id="repSourceChart" height="200"></canvas>
        </div>
    </div>
</div>

{{-- tickets by source --}}
<div class="row g-3 mb-3">
    @php
        $src = [
            [t('dash.src_store','شيك ليست مدير الفرع'), $bySource['store'] ?? 0, 'clipboard-check', '#6366f1', 'store'],
            [t('dash.src_area','زيارات الأريا مانجر'), $bySource['area'] ?? 0, 'binoculars', '#0d9488', 'area'],
            [t('dash.src_maintenance','طلبات الصيانة'), $bySource['maintenance'] ?? 0, 'tools', '#9C1E6E', 'maintenance'],
        ];
    @endphp
    @foreach($src as [$l,$v,$i,$c,$key])
        <div class="col-md-4">
            <a href="{{ route('tickets.index', ['source' => $key]) }}" class="text-decoration-none">
                <div class="card p-3 d-flex flex-row align-items-center" style="cursor:pointer">
                    <div class="rounded-circle d-flex align-items-center justify-content-center me-3"
                         style="width:46px;height:46px;background:{{ $c }}1a;color:{{ $c }}"><i class="bi bi-{{ $i }} fs-5"></i></div>
                    <div><div class="text-muted small">{{ $l }}</div><div class="fw-bold fs-4 text-dark">{{ $v }}</div></div>
                </div>
            </a>
        </div>
    @endforeach
</div>

<div class="row g-3">
    @unless($scopeDept)
    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">{{ t('rep.visits_per_branch','الزيارات حسب الفرع') }}</h6>
            <table class="table table-sm mb-0 js-table">
                <thead><tr><th>الفرع</th><th class="text-end" data-sum>الزيارات</th></tr></thead>
                <tbody>
                @foreach($visitsPerBranch as $row)
                    <tr><td>{{ optional($row->branch)->branch_name }}</td><td class="text-end fw-semibold">{{ $row->total }}</td></tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold"><tr><td class="text-end">الإجمالي</td><td class="text-end">0</td></tr></tfoot>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">{{ t('dash.by_department','التذاكر حسب الإدارة') }}</h6>
            <table class="table table-sm mb-0 js-table">
                <thead><tr><th>الإدارة</th><th class="text-center" data-sum>مفتوحة</th><th class="text-center" data-sum>مقفولة</th></tr></thead>
                <tbody>
                @foreach($byDept as $d)
                    <tr><td>{{ $d->name }}</td><td class="text-center">{{ $d->open }}</td><td class="text-center">{{ $d->closed }}</td></tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold"><tr><td class="text-end">الإجمالي</td><td class="text-center">0</td><td class="text-center">0</td></tr></tfoot>
            </table>
        </div>
    </div>
    @endunless

    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">{{ t('rep.avg_resolution','متوسط زمن الحل (ساعات)') }}</h6>
            <table class="table table-sm mb-0"><tbody>
                @forelse($resolution as $row)
                    <tr><td>{{ optional($row->department)->name ?? '—' }}</td><td class="text-end fw-semibold">{{ round($row->avg_hours ?? 0, 1) }} س</td></tr>
                @empty
                    <tr><td class="text-muted small">لا توجد تذاكر مقفولة بعد.</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">{{ t('dash.repeated','طلبات متكررة') }}</h6>
            <table class="table table-sm mb-0"><tbody>
                @forelse($repeated as $row)
                    <tr><td><span class="badge text-bg-light text-capitalize">{{ $row->category }}</span> {{ optional($row->branch)->branch_name }}</td><td class="text-end fw-semibold">{{ $row->total }}×</td></tr>
                @empty
                    <tr><td class="text-muted small">لا يوجد.</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>

    <div class="col-12">
        <div class="card p-3">
            <h6 class="fw-bold">{{ t('rep.performance','أداء الموظفين') }}</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>الموظف</th><th>الإدارة</th><th class="text-center">مُسندة</th><th class="text-center">مقفولة</th><th class="text-center">معلّقة</th><th class="text-center">معاد فتحها</th><th class="text-center">م. الساعات</th><th>التقييم</th></tr></thead>
                <tbody>
                @forelse($performance as $u)
                    <tr>
                        <td>{{ $u->name }}</td>
                        <td>{{ optional($u->department)->name }}</td>
                        <td class="text-center">{{ $u->assigned }}</td>
                        <td class="text-center">{{ $u->closed }}</td>
                        <td class="text-center">{{ $u->pending }}</td>
                        <td class="text-center">{{ $u->reopened }}</td>
                        <td class="text-center">{{ $u->avg_hours ?? '—' }}</td>
                        <td><span class="badge {{ $u->performance === 'ممتاز' ? 'text-bg-success' : ($u->performance === 'جيد' ? 'text-bg-info' : 'text-bg-warning') }}">{{ $u->performance }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-muted small">لا توجد إسنادات بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-12">
        <div class="card p-3">
            <h6 class="fw-bold">{{ t('rep.overdue','التذاكر المتأخرة') }}</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>الكود</th><th>العنوان</th><th>الفرع</th><th>الإدارة</th><th>الاستحقاق</th></tr></thead>
                <tbody>
                @forelse($overdue as $t)
                    <tr class="table-danger" onclick="window.location='{{ route('tickets.show', $t) }}'" style="cursor:pointer">
                        <td>{{ $t->reference }}</td><td>{{ Str::limit($t->title, 40) }}</td>
                        <td>{{ optional($t->branch)->branch_name }}</td><td>{{ optional($t->department)->name }}</td>
                        <td>{{ optional($t->due_at)->format('d M H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted small">لا توجد تذاكر متأخرة.</td></tr>
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
    const stVals = @json($stValues);
    if (stVals.length) new Chart(document.getElementById('repStatusChart'), {
        type: 'doughnut',
        data: { labels: @json($stLabels), datasets: [{ data: stVals, backgroundColor: @json($stColors), borderWidth: 1 }] },
        options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } },
    });

    new Chart(document.getElementById('repSourceChart'), {
        type: 'bar',
        data: {
            labels: [@json(t('dash.src_store','شيك ليست مدير الفرع')), @json(t('dash.src_area','زيارات الأريا مانجر')), @json(t('dash.src_maintenance','طلبات الصيانة'))],
            datasets: [{ label: @json(t('nav.tickets','التذاكر')), data: [{{ $bySource['store'] ?? 0 }}, {{ $bySource['area'] ?? 0 }}, {{ $bySource['maintenance'] ?? 0 }}],
                backgroundColor: ['#6366f1', '#0d9488', '#9C1E6E'] }],
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
    });
});
</script>
@endpush
