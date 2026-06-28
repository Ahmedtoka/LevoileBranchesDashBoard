@extends('layouts.app')
@section('title', 'Visit performance')
@push('head')<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>@endpush

@section('content')
<a href="{{ route('kpis.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-{{ app()->getLocale()==='ar' ? 'right' : 'left' }}"></i> KPIs</a>
<h4 class="fw-bold mt-1 mb-1"><i class="bi bi-clock-history text-info me-2"></i>{{ dt('أداء الزيارات — الالتزام بالمواعيد','Visit performance — punctuality') }}</h4>
<p class="text-muted small">{{ dt('تُعتبر الزيارة «في الميعاد» إذا حضر خلال 30 دقيقة من الموعد المجدول.','A visit is "on time" if check-in is within 30 minutes of the scheduled time.') }}</p>

@include('partials.filterbar', ['range' => $range])

<div class="row g-2 mb-3">
    @php $boxes = [
        [dt('عدد الزيارات','Visits'), $summary['visits'], 'secondary'],
        [dt('في الميعاد','On time'), $summary['ontime_pct'] !== null ? $summary['ontime_pct'].'%' : '—', ($summary['ontime_pct'] !== null && $summary['ontime_pct'] >= 80) ? 'success' : 'warning'],
        [dt('متوسط التأخير','Avg delay'), $summary['avg_delay'] !== null ? $summary['avg_delay'].' '.dt('دقيقة','min') : '—', 'danger'],
        [dt('متوسط مدة الزيارة','Avg duration'), $summary['avg_duration'] !== null ? $summary['avg_duration'].' '.dt('دقيقة','min') : '—', 'info'],
    ]; @endphp
    @foreach($boxes as [$l,$v,$c])
        <div class="col"><div class="card stat-card p-2 text-center h-100"><div class="value text-{{ $c }}" style="font-size:1.4rem">{{ $v }}</div><div class="text-muted" style="font-size:.72rem">{{ $l }}</div></div></div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-2">{{ dt('في الميعاد مقابل متأخر','On time vs late') }}</h6>
            <canvas id="otChart" height="180"></canvas>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-2">{{ dt('الأداء حسب الأريا مانجر','By area manager') }}</h6>
            <table class="table table-sm table-hover align-middle mb-0 js-table">
                <thead><tr><th>{{ dt('الأريا مانجر','Area manager') }}</th><th class="text-center">{{ dt('زيارات','Visits') }}</th><th class="text-center">{{ dt('في الميعاد','On time') }}</th><th class="text-center">{{ dt('متوسط التأخير','Avg delay') }}</th><th class="text-center">{{ dt('متوسط المدة','Avg dur.') }}</th></tr></thead>
                <tbody>
                @forelse($byManager as $m)
                    <tr>
                        <td class="small fw-semibold">{{ $m['name'] }}</td>
                        <td class="text-center">{{ $m['visits'] }}</td>
                        <td class="text-center"><span class="badge text-bg-{{ $m['ontime_pct'] >= 80 ? 'success' : ($m['ontime_pct'] >= 50 ? 'warning' : 'danger') }}">{{ $m['ontime_pct'] }}%</span></td>
                        <td class="text-center small">{{ $m['avg_delay'] }} {{ dt('د','m') }}</td>
                        <td class="text-center small">{{ $m['avg_duration'] }} {{ dt('د','m') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted small p-3">{{ dt('لا توجد زيارات في هذه الفترة.','No visits in this period.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card p-3 mt-3">
    <h6 class="fw-bold mb-2"><i class="bi bi-exclamation-triangle text-danger me-1"></i>{{ dt('أكثر الزيارات تأخيرًا','Most delayed visits') }}</h6>
    <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>{{ dt('الأريا مانجر','Area manager') }}</th><th>{{ dt('الفرع','Branch') }}</th><th>{{ dt('التاريخ','Date') }}</th><th class="text-center">{{ dt('الموعد','Scheduled') }}</th><th class="text-center">{{ dt('الحضور','Check-in') }}</th><th class="text-center">{{ dt('التأخير','Delay') }}</th></tr></thead>
        <tbody>
        @forelse($late as $v)
            <tr>
                <td class="small fw-semibold">{{ $v['user_name'] }}</td>
                <td class="small">{{ $v['branch'] }}</td>
                <td class="small">{{ $v['date'] }}</td>
                <td class="text-center small">{{ $v['scheduled'] }}</td>
                <td class="text-center small">{{ $v['checkin'] }}</td>
                <td class="text-center"><span class="badge text-bg-danger">+{{ $v['delay_min'] }} {{ dt('د','m') }}</span></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-muted small p-3">{{ dt('كل الزيارات في مواعيدها 👏','All visits were on time 👏') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@push('scripts')
<script>
new Chart(document.getElementById('otChart'), {
    type: 'doughnut',
    data: { labels: ['{{ dt('في الميعاد','On time') }}', '{{ dt('متأخر','Late') }}'],
        datasets: [{ data: [{{ $summary['ontime'] }}, {{ $summary['visits'] - $summary['ontime'] }}],
            backgroundColor: ['#198754', '#dc3545'] }] },
    options: { plugins: { legend: { position: 'bottom' } } }
});
</script>
@endpush
@endsection
