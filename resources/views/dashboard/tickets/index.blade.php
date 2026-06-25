@extends('layouts.app')
@section('title', 'التذاكر')

@section('content')
<h4 class="fw-bold mb-3">التذاكر</h4>

@include('partials.filterbar', ['range' => $range, 'searchable' => true, 'searchPlaceholder' => 'بحث بالعنوان / الكود…'])

@php
    $rk = ['range' => $range->key, 'from' => $range->customFrom, 'to' => $range->customTo];
    $boxes = [
        ['الإجمالي',$stats['total'],'dark', route('tickets.index', $rk)],
        ['مفتوحة',$stats['open'],'warning', route('tickets.index', $rk + ['status'=>'open'])],
        ['بانتظار الموافقة',$stats['waiting_approval'],'info', route('tickets.index', $rk + ['status'=>'waiting_approval'])],
        ['مقفولة',$stats['closed'],'success', route('tickets.index', $rk + ['status'=>'closed'])],
    ];
    $statusAr = \App\Models\Ticket::STATUS_AR;
    $prioAr = ['low'=>'منخفضة','medium'=>'متوسطة','high'=>'عالية','critical'=>'حرجة'];
    $srcAr = ['store'=>'شيك ليست الفرع','area'=>'زيارات الأريا','maintenance'=>'طلبات الصيانة'];
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
            <label class="form-label small mb-1">الإدارة</label>
            <select name="department" class="form-select form-select-sm">
                <option value="">الكل</option>
                @foreach($departments as $d)<option value="{{ $d->slug }}" @selected(request('department')===$d->slug)>{{ $d->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">المصدر</label>
            <select name="source" class="form-select form-select-sm">
                <option value="">الكل</option>
                @foreach($srcAr as $k=>$v)<option value="{{ $k }}" @selected(request('source')===$k)>{{ $v }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">الحالة</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">الكل</option>
                @foreach(\App\Models\Ticket::STATUSES as $s)<option value="{{ $s }}" @selected(request('status')===$s)>{{ $statusAr[$s] ?? $s }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1">الأولوية</label>
            <select name="priority" class="form-select form-select-sm">
                <option value="">الكل</option>
                @foreach(\App\Models\Ticket::PRIORITIES as $p)<option value="{{ $p }}" @selected(request('priority')===$p)>{{ $prioAr[$p] ?? $p }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-sm btn-primary">تطبيق</button>
            <a href="{{ route('tickets.index') }}" class="btn btn-sm btn-outline-secondary">مسح</a>
        </div>
    </div>
</form>

<div class="d-flex mb-2">
    <input id="ticketsSearch" class="form-control form-control-sm" style="max-width:280px" placeholder="فلترة صفوف الصفحة…">
</div>
<div class="card p-0">
    <table class="table table-hover align-middle mb-0 js-table" data-search="ticketsSearch">
        <thead><tr><th>الكود</th><th>العنوان</th><th>الفرع</th><th>الإدارة</th><th>المسؤول</th><th>الأولوية</th><th>الحالة</th><th data-nosort></th></tr></thead>
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
                <td class="text-end"><a href="{{ route('tickets.show', $t) }}" class="btn btn-sm btn-outline-secondary">فتح</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-muted small p-3">لا توجد تذاكر في الفترة.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{ $tickets->links() }}</div>
@endsection
