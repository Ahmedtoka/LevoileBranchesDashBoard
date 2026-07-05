@extends('layouts.app')
@section('title', 'Purchasing KPIs')
@push('head')<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>@endpush

@php $STA = \App\Models\PurchaseRequest::STATUS_AR; @endphp

@section('content')
<h4 class="fw-bold mb-1"><i class="bi bi-graph-up-arrow text-primary me-2"></i>{{ dt('مؤشرات المشتريات','Purchasing KPIs') }}</h4>
<p class="text-muted small">{{ dt('قياس مدة التسعير والتنفيذ، فرق الأيام من الطلب، ونسبة التأخير في دورة الشراء.','Pricing & execution durations, days-from-request, and delay rate across the buying cycle.') }}</p>

@include('partials.filterbar', ['range' => $range])

<div class="row g-2 mb-3">
    @php $boxes = [
        [dt('متوسط مدة التسعير','Avg pricing'), $kpi['avg_pricing']!==null ? $kpi['avg_pricing'].' '.dt('يوم','d') : '—', 'info'],
        [dt('متوسط مدة التنفيذ','Avg execution'), $kpi['avg_execution']!==null ? $kpi['avg_execution'].' '.dt('يوم','d') : '—', 'primary'],
        [dt('متوسط من الطلب للشراء','Avg request→buy'), $kpi['avg_total']!==null ? $kpi['avg_total'].' '.dt('يوم','d') : '—', 'success'],
        [dt('قيد التنفيذ','Open'), $kpi['open'], 'secondary'],
        [dt('متأخرة','Delayed'), $kpi['delayed'].($kpi['delayed_pct']!==null ? ' ('.$kpi['delayed_pct'].'%)' : ''), ($kpi['delayed']>0?'danger':'success')],
    ]; @endphp
    @foreach($boxes as [$l,$v,$c])
        <div class="col"><div class="card stat-card p-2 text-center h-100"><div class="value text-{{ $c }}" style="font-size:1.3rem">{{ $v }}</div><div class="text-muted" style="font-size:.72rem">{{ $l }}</div></div></div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-2">{{ dt('توزيع الحالات','Status distribution') }}</h6>
            <canvas id="stChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-2">{{ dt('أداء القائمين بالشراء','Buyer performance') }}</h6>
            <table class="table table-sm table-hover align-middle mb-0 js-table">
                <thead><tr><th>{{ dt('القائم بالشراء','Buyer') }}</th><th class="text-center">{{ dt('طلبات','Requests') }}</th><th class="text-center">{{ dt('تم الشراء','Purchased') }}</th><th class="text-center">{{ dt('متوسط التسعير','Avg pricing') }}</th></tr></thead>
                <tbody>
                @forelse($byBuyer as $b)
                    <tr><td class="small fw-semibold">{{ $b->buyer_name }}</td><td class="text-center">{{ $b->n }}</td><td class="text-center">{{ $b->done }}</td><td class="text-center small">{{ $b->avg_pricing!==null ? $b->avg_pricing.' '.dt('ي','d') : '—' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-muted small p-3">{{ dt('لا توجد بيانات.','No data.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card p-3 mt-3">
    <h6 class="fw-bold mb-2">{{ dt('حسب التصنيف (العدد والإنفاق)','By category (count & spend)') }}</h6>
    <table class="table table-sm table-hover align-middle mb-0 js-table">
        <thead><tr><th>{{ dt('التصنيف','Category') }}</th><th class="text-center" data-sum>{{ dt('عدد الطلبات','Requests') }}</th><th class="text-center">{{ dt('إجمالي الإنفاق','Total spend') }}</th></tr></thead>
        <tbody>
        @foreach($byCategory as $c)
            <tr><td class="small">{{ $c->category }}</td><td class="text-center">{{ $c->n }}</td><td class="text-center small">{{ $c->spend ? number_format($c->spend,0) : '—' }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><th>{{ dt('الإجمالي','Total') }}</th><th class="text-center">0</th><th></th></tr></tfoot>
    </table>
</div>

<div class="card p-3 mt-3">
    <h6 class="fw-bold mb-2"><i class="bi bi-exclamation-triangle text-danger me-1"></i>{{ dt('أقدم الطلبات المفتوحة','Oldest open requests') }}</h6>
    <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>{{ dt('رقم','Ref') }}</th><th>{{ dt('الصنف','Item') }}</th><th>{{ dt('الجهة','Requester') }}</th><th>{{ dt('القائم بالشراء','Buyer') }}</th><th class="text-center">{{ dt('منذ','Age') }}</th><th>{{ dt('الحالة','Status') }}</th></tr></thead>
        <tbody>
        @forelse($slow as $r)
            <tr>
                <td class="small fw-semibold"><a href="{{ route('purchasing.show', $r) }}">{{ $r->reference }}</a></td>
                <td class="small">{{ Str::limit($r->item_name, 30) }}</td>
                <td class="small">{{ $r->requesting_dept_name ?? optional($r->branch)->branch_name ?? '—' }}</td>
                <td class="small">{{ optional($r->buyer)->name ?? $r->buyer_name ?? '—' }}</td>
                <td class="text-center small text-danger">{{ $r->daysSinceRequest() }} {{ dt('يوم','d') }}</td>
                <td><span class="badge text-bg-{{ $r->statusColor() }}">{{ $r->statusAr() }}</span></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-muted small p-3">{{ dt('لا توجد طلبات مفتوحة.','No open requests.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@push('scripts')
<script>
const st = @json(collect($byStatus)->mapWithKeys(fn($v,$k)=>[($STA[$k] ?? $k)=>$v]));
new Chart(document.getElementById('stChart'), {
    type: 'bar',
    data: { labels: Object.keys(st), datasets: [{ data: Object.values(st), backgroundColor: '#9c1e6e', borderRadius: 4 }] },
    options: { indexAxis: 'y', plugins: { legend: { display: false } } }
});
</script>
@endpush
@endsection
