@extends('layouts.app')
@section('title', t('visit.title','الزيارات'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="fw-bold mb-0">{{ t('visit.title','الزيارات') }}</h4>
    <a href="{{ route('visits.schedule') }}" class="btn btn-primary"><i class="bi bi-calendar-plus"></i> {{ t('nav.schedule','جدولة') }}</a>
</div>

@include('partials.filterbar', ['range' => $range, 'searchable' => true, 'searchPlaceholder' => t('visit.search','بحث عن فرع…')])

@php
    $rk = ['range' => $range->key, 'from' => $range->customFrom, 'to' => $range->customTo];
    $boxes = [
        [t('common.total','الإجمالي'),$stats['total'],'dark', route('visits.index', $rk)],
        [t('tk.open','مفتوحة'),$stats['open'],'warning', route('visits.index', $rk + ['status'=>'open'])],
        [t('visit.completed','مكتملة'),$stats['completed'],'success', route('visits.index', $rk + ['status'=>'completed'])],
        [t('visit.problems','طلبات'),$stats['problems'],'danger', route('visits.index', $rk)],
    ];
@endphp
<div class="row g-3 mb-3">
    @foreach($boxes as [$l,$v,$c,$link])
        <div class="col-md-3 col-6">
            <a href="{{ $link }}" class="text-decoration-none">
                <div class="card stat-card p-3" style="cursor:pointer"><div class="text-muted small">{{ $l }}</div><div class="value text-{{ $c }}">{{ $v }}</div></div>
            </a>
        </div>
    @endforeach
</div>

<div class="btn-group btn-group-sm mb-3">
    <a href="{{ request()->fullUrlWithQuery(['status'=>null]) }}" class="btn {{ $filter==='all' ? 'btn-primary' : 'btn-outline-secondary' }}">{{ t('common.all','الكل') }}</a>
    <a href="{{ request()->fullUrlWithQuery(['status'=>'open']) }}" class="btn {{ $filter==='open' ? 'btn-primary' : 'btn-outline-secondary' }}">{{ t('tk.open','مفتوحة') }}</a>
    <a href="{{ request()->fullUrlWithQuery(['status'=>'completed']) }}" class="btn {{ $filter==='completed' ? 'btn-primary' : 'btn-outline-secondary' }}">{{ t('visit.completed','مكتملة') }}</a>
</div>

<div class="d-flex mb-2">
    <input id="visitsSearch" class="form-control form-control-sm" style="max-width:280px" placeholder="{{ t('common.filter_rows','فلترة صفوف الصفحة…') }}">
</div>
<div class="card p-0">
    <table class="table table-hover align-middle mb-0 js-table" data-search="visitsSearch">
        <thead><tr><th>{{ t('common.date','التاريخ') }}</th><th>{{ t('visit.template','القالب') }}</th><th>{{ t('common.branch','الفرع') }}</th><th>{{ t('visit.user','المستخدم') }}</th><th class="text-center" data-sum>{{ t('visit.problems','طلبات') }}</th><th class="text-center" data-sum>{{ t('visit.tickets','تذاكر') }}</th><th>{{ t('tk.status','الحالة') }}</th><th data-nosort></th></tr></thead>
        <tbody>
        @forelse($visits as $v)
            <tr>
                <td class="small">{{ optional($v->scheduled_date)->format('d M Y') }}</td>
                <td>{{ optional($v->template)->name }}</td>
                <td>{{ optional($v->branch)->branch_name }}</td>
                <td>{{ optional($v->user)->name }}</td>
                <td class="text-center">{{ $v->problems_count }}</td>
                <td class="text-center">{{ $v->tickets_count }}</td>
                <td>@include('partials.status-badge', ['status' => $v->status])</td>
                <td class="text-end"><a href="{{ route('visits.show', $v) }}" class="btn btn-sm btn-outline-secondary">{{ t('common.view','عرض') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-muted small p-3">{{ t('visit.no_visits','لا توجد زيارات في الفترة.') }}</td></tr>
        @endforelse
        </tbody>
        <tfoot class="table-light fw-semibold"><tr><td colspan="4" class="text-end">{{ t('common.total','الإجمالي') }}</td><td class="text-center">0</td><td class="text-center">0</td><td colspan="2"></td></tr></tfoot>
    </table>
</div>
<div class="mt-3">{{ $visits->links() }}</div>
@endsection
