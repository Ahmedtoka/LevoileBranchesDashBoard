@extends('layouts.app')
@section('title', 'Maintenance Center')

@php
    use App\Models\Ticket;
    $rk = ['department' => 'maintenance', 'range' => $range->key, 'from' => $range->customFrom, 'to' => $range->customTo];
    $link = fn ($extra = []) => route('tickets.index', array_merge($rk, $extra));
    $statusColors = ['open'=>'secondary','assigned'=>'info','on_the_way'=>'primary','in_progress'=>'warning','waiting_approval'=>'purple','postponed'=>'warning','not_fixed'=>'danger','closed'=>'success'];
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">مركز الصيانة <span class="text-muted fs-6">Maintenance Center</span></h4>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('maintenance.generate') }}" onsubmit="return confirm('سيتم مسح كل التذاكر/الزيارات وتوليد داتا الديمو من الشيت. متابعة؟')">
            @csrf <button class="btn btn-success btn-sm"><i class="bi bi-magic"></i> توليد داتا ديمو</button>
        </form>
        <form method="POST" action="{{ route('maintenance.wipe') }}" onsubmit="return confirm('سيتم مسح كل التذاكر والزيارات والإشعارات نهائياً. متابعة؟')">
            @csrf <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> مسح كل الداتا</button>
        </form>
    </div>
</div>

@include('partials.filterbar', ['range' => $range, 'searchable' => false])

{{-- KPI boxes (clickable) --}}
<div class="row g-3 mb-3">
    @php $boxes = [
        ['إجمالي', $stats['total'], 'dark', $link()],
        ['مفتوحة', $stats['open'], 'warning', $link(['status'=>'open_group'])],
        ['جديدة', $stats['new'], 'info', $link(['status'=>'open'])],
        ['جاري العمل', $stats['in_progress'], 'primary', $link(['status'=>'in_progress'])],
        ['مؤجّلة', $stats['postponed'], 'secondary', $link(['status'=>'postponed'])],
        ['لم تُحل', $stats['not_fixed'], 'danger', $link(['status'=>'not_fixed'])],
        ['مقفولة', $stats['closed'], 'success', $link(['status'=>'closed'])],
        ['بقالها +يوم', $stats['over_1_day'], 'danger', $link(['status'=>'over_1_day'])],
    ]; @endphp
    @foreach($boxes as [$l,$v,$c,$href])
        <div class="col-md-3 col-6">
            <a href="{{ $href }}" target="_blank" class="text-decoration-none">
                <div class="card stat-card p-3" style="cursor:pointer"><div class="text-muted small">{{ $l }} <i class="bi bi-box-arrow-up-right" style="font-size:.7rem"></i></div><div class="value text-{{ $c }}">{{ $v }}</div></div>
            </a>
        </div>
    @endforeach
</div>

{{-- Cycle funnel --}}
<div class="card p-3 mb-3">
    <h6 class="fw-bold mb-3">دورة الحالة (Cycle)</h6>
    <div class="d-flex flex-wrap gap-2">
        @foreach($cycle as $st => $cnt)
            <a href="{{ $link(['status'=>$st]) }}" target="_blank" class="text-decoration-none">
                <div class="border rounded px-3 py-2 text-center" style="min-width:110px">
                    <div class="small text-muted">{{ Ticket::STATUS_AR[$st] ?? $st }}</div>
                    <div class="fw-bold fs-5 text-{{ $statusColors[$st] === 'purple' ? 'dark' : $statusColors[$st] }}">{{ $cnt }}</div>
                </div>
            </a>
            @if(!$loop->last)<div class="align-self-center text-muted">→</div>@endif
        @endforeach
    </div>
</div>

<div class="row g-3">
    {{-- By branch --}}
    <div class="col-lg-6">
        <div class="card p-3">
            <h6 class="fw-bold">حسب الفرع</h6>
            <table class="table table-sm table-hover align-middle mb-0 js-table">
                <thead><tr><th>الفرع</th><th class="text-center" data-sum>الكل</th><th class="text-center" data-sum>مفتوح</th><th class="text-center" data-sum>مقفول</th></tr></thead>
                <tbody>
                @foreach($byBranch as $b)
                    <tr onclick="window.open('{{ $link(['branch_id'=>$b->branch_id]) }}','_blank')" style="cursor:pointer">
                        <td>{{ optional($b->branch)->branch_name }}</td>
                        <td class="text-center">{{ $b->total }}</td>
                        <td class="text-center text-warning">{{ $b->open }}</td>
                        <td class="text-center text-success">{{ $b->closed }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold"><tr><td>الإجمالي</td><td class="text-center">0</td><td class="text-center">0</td><td class="text-center">0</td></tr></tfoot>
            </table>
        </div>
    </div>

    {{-- By category --}}
    <div class="col-lg-6">
        <div class="card p-3 mb-3">
            <h6 class="fw-bold">حسب النوع</h6>
            <table class="table table-sm table-hover align-middle mb-0 js-table">
                <thead><tr><th>النوع</th><th class="text-center" data-sum>الكل</th><th class="text-center" data-sum>مفتوح</th></tr></thead>
                <tbody>
                @foreach($byCategory as $c)
                    <tr onclick="window.open('{{ $link(['category'=>$c->category]) }}','_blank')" style="cursor:pointer">
                        <td>{{ $c->category }}</td><td class="text-center">{{ $c->total }}</td><td class="text-center text-warning">{{ $c->open }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold"><tr><td>الإجمالي</td><td class="text-center">0</td><td class="text-center">0</td></tr></tfoot>
            </table>
        </div>

        {{-- By technician --}}
        <div class="card p-3">
            <h6 class="fw-bold">حسب الفني</h6>
            <table class="table table-sm table-hover align-middle mb-0 js-table">
                <thead><tr><th>الفني</th><th class="text-center" data-sum>الكل</th><th class="text-center" data-sum>مفتوح</th><th class="text-center" data-sum>جاري</th><th class="text-center" data-sum>أنجز</th></tr></thead>
                <tbody>
                @forelse($byEmployee as $e)
                    <tr onclick="window.open('{{ $link(['assigned_to'=>$e->id]) }}','_blank')" style="cursor:pointer">
                        <td>{{ $e->name }} @if($e->open == 0)<span class="badge text-bg-light text-muted">فاضي</span>@endif</td>
                        <td class="text-center">{{ $e->total }}</td><td class="text-center text-warning">{{ $e->open }}</td>
                        <td class="text-center text-primary">{{ $e->working }}</td><td class="text-center text-success">{{ $e->done }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted small">لا يوجد فنيين.</td></tr>
                @endforelse
                </tbody>
                <tfoot class="table-light fw-semibold"><tr><td>الإجمالي</td><td class="text-center">0</td><td class="text-center">0</td><td class="text-center">0</td><td class="text-center">0</td></tr></tfoot>
            </table>
        </div>
    </div>

    {{-- Latest tickets --}}
    <div class="col-12">
        <div class="card p-0">
            <div class="d-flex justify-content-between align-items-center p-3 pb-2">
                <h6 class="fw-bold mb-0">أحدث التذاكر</h6>
                <a href="{{ $link() }}" target="_blank" class="btn btn-sm btn-outline-primary">عرض الكل <i class="bi bi-arrow-left"></i></a>
            </div>
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>الرقم</th><th>المشكلة</th><th>الفرع</th><th>النوع</th><th>الفني</th><th>التاريخ</th><th>الحالة</th></tr></thead>
                <tbody>
                @forelse($latest as $t)
                    <tr onclick="window.location='{{ route('tickets.show', $t) }}'" style="cursor:pointer" class="{{ $t->isOverdue() ? 'table-danger' : '' }}">
                        <td class="small fw-semibold">{{ $t->reference }}</td>
                        <td>{{ Str::limit($t->title, 45) }}</td>
                        <td class="small">{{ optional($t->branch)->branch_name }}</td>
                        <td class="small">{{ $t->category }}</td>
                        <td class="small">{{ optional($t->assignee)->name ?? '—' }}</td>
                        <td class="small">{{ $t->created_at->format('d M') }}</td>
                        <td>@include('partials.status-badge', ['status' => $t->status])</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted small p-3">لا توجد تذاكر. اضغط "توليد داتا ديمو".</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
