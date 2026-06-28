@extends('layouts.app')
@section('title', 'Ticket lifecycle')
@push('head')<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>@endpush

@section('content')
<a href="{{ route('kpis.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-{{ app()->getLocale()==='ar' ? 'right' : 'left' }}"></i> KPIs</a>
<h4 class="fw-bold mt-1 mb-1"><i class="bi bi-hourglass-split text-primary me-2"></i>{{ dt('دورة حياة التذاكر / SLA','Ticket lifecycle / SLA') }}
    @if($scopeDept)<span class="badge text-bg-light text-primary">{{ $scopeDept->name }}</span>@endif</h4>

@include('partials.filterbar', ['range' => $range])

{{-- SLA summary --}}
<div class="row g-2 mb-3">
    @php $boxes = [
        [dt('متوسط زمن الحل الكلي','Avg total resolution'), $avgTotal !== null ? $avgTotal.' h' : '—', 'primary'],
        [dt('التزام SLA','SLA compliance'), $sla['sla_pct'] !== null ? $sla['sla_pct'].'%' : '—', ($sla['sla_pct'] !== null && $sla['sla_pct'] >= 80) ? 'success' : 'warning'],
        [dt('تذاكر مُغلقة','Closed'), $sla['closed'], 'secondary'],
        [dt('خرق SLA','SLA breached'), $sla['breached'], 'danger'],
        [dt('مفتوحة حاليًا','Open now'), $sla['open'], 'info'],
    ]; @endphp
    @foreach($boxes as [$l,$v,$c])
        <div class="col"><div class="card stat-card p-2 text-center h-100"><div class="value text-{{ $c }}" style="font-size:1.4rem">{{ $v }}</div><div class="text-muted" style="font-size:.72rem">{{ $l }}</div></div></div>
    @endforeach
</div>

<div class="row g-3">
    {{-- Stage durations chart --}}
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-1">{{ dt('متوسط الوقت بين مراحل التذكرة (ساعات)','Avg hours between ticket stages') }}</h6>
            <p class="text-muted small mb-2">{{ dt('وين بالظبط بيضيع الوقت في رحلة التذكرة.','Where exactly time is lost across the ticket journey.') }}</p>
            <canvas id="stageChart" height="150"></canvas>
        </div>
    </div>

    {{-- Stage table --}}
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-2">{{ dt('تفاصيل المراحل','Stage detail') }}</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>{{ dt('المرحلة','Stage') }}</th><th class="text-center">{{ dt('ساعات','Hours') }}</th><th class="text-center">{{ dt('عدد','n') }}</th></tr></thead>
                <tbody>
                @foreach($stageAvg as $s)
                    <tr>
                        <td class="small">{{ $s['label'] }}</td>
                        <td class="text-center fw-semibold">{{ $s['hours'] ?? '—' }}</td>
                        <td class="text-center text-muted small">{{ $s['n'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@if($byDept->isNotEmpty())
<div class="card p-3 mt-3">
    <h6 class="fw-bold mb-2">{{ dt('الأداء حسب الإدارة','By department') }}</h6>
    <table class="table table-sm table-hover align-middle mb-0 js-table">
        <thead><tr><th>{{ dt('الإدارة','Department') }}</th><th class="text-center">{{ dt('مُغلقة','Closed') }}</th><th class="text-center">{{ dt('متوسط الحل (ساعة)','Avg resolution (h)') }}</th><th class="text-center">{{ dt('التزام SLA','SLA %') }}</th></tr></thead>
        <tbody>
        @foreach($byDept as $d)
            <tr>
                <td>{{ $d->name }}</td>
                <td class="text-center">{{ $d->closed_n }}</td>
                <td class="text-center">{{ $d->avg_h ?? '—' }}</td>
                <td class="text-center">
                    @if($d->sla_pct !== null)
                        <span class="badge text-bg-{{ $d->sla_pct >= 80 ? 'success' : ($d->sla_pct >= 50 ? 'warning' : 'danger') }}">{{ $d->sla_pct }}%</span>
                    @else — @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- Slowest open tickets --}}
<div class="card p-3 mt-3">
    <h6 class="fw-bold mb-2"><i class="bi bi-exclamation-triangle text-danger me-1"></i>{{ dt('أقدم التذاكر المفتوحة','Oldest open tickets') }}</h6>
    <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>{{ dt('المرجع','Ref') }}</th><th>{{ dt('العنوان','Title') }}</th><th>{{ dt('الفرع','Branch') }}</th><th>{{ dt('الإدارة','Dept') }}</th><th>{{ dt('المسؤول','Assignee') }}</th><th class="text-center">{{ dt('مفتوحة منذ','Open for') }}</th><th>{{ dt('الحالة','Status') }}</th></tr></thead>
        <tbody>
        @forelse($slow as $t)
            <tr>
                <td class="fw-semibold small">{{ $t->reference }}</td>
                <td class="small">{{ Str::limit($t->title, 34) }}</td>
                <td class="small">{{ optional($t->branch)->branch_name }}</td>
                <td class="small">{{ optional($t->department)->name ?? '—' }}</td>
                <td class="small">{{ optional($t->assignee)->name ?? '—' }}</td>
                <td class="text-center small text-danger fw-semibold">{{ (int) round($t->created_at->diffInHours(now())) }}h</td>
                <td>@include('partials.status-badge', ['status' => $t->status])</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-muted small p-3">{{ dt('لا توجد تذاكر مفتوحة في هذه الفترة.','No open tickets in this period.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@push('scripts')
<script>
const sa = @json($stageAvg);
new Chart(document.getElementById('stageChart'), {
    type: 'bar',
    data: {
        labels: sa.map(s => s.label),
        datasets: [{ label: '{{ dt('ساعات','Hours') }}', data: sa.map(s => s.hours ?? 0),
            backgroundColor: '#9c1e6e', borderRadius: 5 }]
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } },
        scales: { x: { title: { display: true, text: '{{ dt('ساعات','Hours') }}' } } } }
});
</script>
@endpush
@endsection
