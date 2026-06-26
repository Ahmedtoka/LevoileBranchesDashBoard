@extends('layouts.app')
@section('title', t('tk.title','التذاكر'))

@section('content')
<h4 class="fw-bold mb-3">{{ t('tk.title','التذاكر') }}</h4>

@include('partials.filterbar', ['range' => $range, 'searchable' => true, 'searchPlaceholder' => t('tk.search','بحث بالعنوان / الكود…')])

@php
    $rk = ['range' => $range->key, 'from' => $range->customFrom, 'to' => $range->customTo];
    $boxes = [
        [t('common.total','الإجمالي'),$stats['total'],'dark', route('tickets.index', $rk)],
        [t('tk.open','مفتوحة'),$stats['open'],'warning', route('tickets.index', $rk + ['status'=>'open'])],
        [t('dash.waiting_approval','بانتظار الموافقة'),$stats['waiting_approval'],'info', route('tickets.index', $rk + ['status'=>'waiting_approval'])],
        [t('tk.closed','مقفولة'),$stats['closed'],'success', route('tickets.index', $rk + ['status'=>'closed'])],
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

<form class="card p-3 mb-3" method="GET">
    <input type="hidden" name="range" value="{{ $range->key }}">
    @if($range->key==='custom')<input type="hidden" name="from" value="{{ $range->customFrom }}"><input type="hidden" name="to" value="{{ $range->customTo }}">@endif
    @if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">{{ t('common.department','الإدارة') }}</label>
            <select name="department" class="form-select form-select-sm">
                <option value="">{{ t('common.all','الكل') }}</option>
                @foreach($departments as $d)<option value="{{ $d->slug }}" @selected(request('department')===$d->slug)>{{ $d->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">{{ t('common.source','المصدر') }}</label>
            <select name="source" class="form-select form-select-sm">
                <option value="">{{ t('common.all','الكل') }}</option>
                <option value="store" @selected(request('source')==='store')>{{ t('dash.src_store','شيك ليست الفرع') }}</option>
                <option value="area" @selected(request('source')==='area')>{{ t('dash.src_area','زيارات الأريا') }}</option>
                <option value="maintenance" @selected(request('source')==='maintenance')>{{ t('dash.src_maintenance','طلبات الصيانة') }}</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">{{ t('tk.status','الحالة') }}</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">{{ t('common.all','الكل') }}</option>
                @foreach(\App\Models\Ticket::STATUSES as $s)<option value="{{ $s }}" @selected(request('status')===$s)>{{ t('status.'.$s, $s) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">{{ t('tk.priority','الأولوية') }}</label>
            <select name="priority" class="form-select form-select-sm">
                <option value="">{{ t('common.all','الكل') }}</option>
                @foreach(\App\Models\Ticket::PRIORITIES as $p)<option value="{{ $p }}" @selected(request('priority')===$p)>{{ t('priority.'.$p, $p) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-primary">{{ t('common.apply','تطبيق') }}</button>
            <a href="{{ route('tickets.index') }}" class="btn btn-sm btn-outline-secondary">{{ t('common.reset','مسح') }}</a>
        </div>
    </div>
</form>

<div class="d-flex mb-2">
    <input id="ticketsSearch" class="form-control form-control-sm" style="max-width:280px" placeholder="{{ t('common.filter_rows','فلترة صفوف الصفحة…') }}">
</div>
<div class="card p-0">
    <table class="table table-hover align-middle mb-0 js-table" data-search="ticketsSearch">
        <thead><tr><th>{{ t('tk.code','الكود') }}</th><th>{{ t('tk.subject','العنوان') }}</th><th>{{ t('common.branch','الفرع') }}</th><th>{{ t('common.department','الإدارة') }}</th><th>{{ t('tk.assignee','المسؤول') }}</th><th>{{ t('tk.priority','الأولوية') }}</th><th>{{ t('tk.status','الحالة') }}</th><th data-nosort></th></tr></thead>
        <tbody>
        @forelse($tickets as $t)
            <tr class="{{ $t->isOverdue() ? 'table-danger' : '' }}">
                <td class="fw-semibold">{{ $t->reference }}</td>
                <td>{{ Str::limit($t->title, 45) }}</td>
                <td>{{ optional($t->branch)->branch_name }}</td>
                <td>{{ optional($t->department)->name ?? '—' }}</td>
                <td>{{ optional($t->assignee)->name ?? '—' }}</td>
                <td>@include('partials.priority-badge', ['priority' => $t->priority])</td>
                <td>@include('partials.status-badge', ['status' => $t->status])</td>
                <td class="text-end"><a href="{{ route('tickets.show', $t) }}" class="btn btn-sm btn-outline-secondary">{{ t('common.open','فتح') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-muted small p-3">{{ t('dash.no_tickets','لا توجد تذاكر في الفترة.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{ $tickets->links() }}</div>
@endsection
