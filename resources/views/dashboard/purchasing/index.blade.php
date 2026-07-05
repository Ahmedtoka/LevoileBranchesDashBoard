@extends('layouts.app')
@section('title', 'Purchase requests')

@php
    $STA = \App\Models\PurchaseRequest::STATUS_AR;
    $STC = \App\Models\PurchaseRequest::STATUS_COLOR;
    $PRA = \App\Models\PurchaseRequest::PRIORITY_AR;
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="fw-bold mb-0"><i class="bi bi-cart3 text-primary me-2"></i>{{ dt('طلبات الشراء','Purchase requests') }}</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newReq"><i class="bi bi-plus-lg me-1"></i>{{ dt('طلب شراء جديد','New request') }}</button>
</div>

@include('partials.filterbar', ['range' => $range, 'searchable' => true, 'searchPlaceholder' => dt('بحث بالصنف / رقم الطلب…','Search item / reference…')])

{{-- Stats --}}
<div class="row g-2 mb-3">
    @php $boxes = [
        [dt('الإجمالي','Total'), $stats['total'], 'dark', null],
        [dt('قيد التنفيذ','In pipeline'), $stats['open'], 'info', 'open'],
        [dt('جاري التسعير','Pricing'), $stats['pricing'], 'info', 'pricing'],
        [dt('بانتظار المالية','Finance'), $stats['finance'], 'warning', 'finance_review'],
        [dt('تنفيذ/PO','Executing'), $stats['executing'], 'primary', null],
        [dt('تم الشراء','Purchased'), $stats['purchased'], 'success', 'purchased'],
    ]; @endphp
    @foreach($boxes as [$l,$v,$c,$st])
        <div class="col">
            <a href="{{ $st ? request()->fullUrlWithQuery(['status'=>$st==='open'?null:$st,'open'=>$st==='open'?1:null]) : request()->fullUrlWithQuery(['status'=>null,'open'=>null]) }}" class="text-decoration-none">
                <div class="card stat-card p-2 text-center h-100"><div class="value text-{{ $c }}" style="font-size:1.4rem">{{ $v }}</div><div class="text-muted" style="font-size:.72rem">{{ $l }}</div></div>
            </a>
        </div>
    @endforeach
</div>

{{-- Filters --}}
<form class="d-flex flex-wrap gap-2 mb-3" method="GET">
    <input type="hidden" name="range" value="{{ $range->key }}">
    @if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
    <select name="status" class="form-select form-select-sm" style="max-width:170px" onchange="this.form.submit()">
        <option value="">{{ dt('كل الحالات','All statuses') }}</option>
        @foreach($STA as $k=>$v)<option value="{{ $k }}" @selected(request('status')===$k)>{{ $v }}</option>@endforeach
    </select>
    <select name="priority" class="form-select form-select-sm" style="max-width:150px" onchange="this.form.submit()">
        <option value="">{{ dt('كل الأولويات','All priorities') }}</option>
        @foreach($PRA as $k=>$v)<option value="{{ $k }}" @selected(request('priority')===$k)>{{ $v }}</option>@endforeach
    </select>
    <select name="category" class="form-select form-select-sm" style="max-width:170px" onchange="this.form.submit()">
        <option value="">{{ dt('كل التصنيفات','All categories') }}</option>
        @foreach($categories as $c)<option value="{{ $c }}" @selected(request('category')===$c)>{{ $c }}</option>@endforeach
    </select>
    <select name="buyer_id" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()">
        <option value="">{{ dt('كل القائمين بالشراء','All buyers') }}</option>
        @foreach($buyers as $b)<option value="{{ $b->id }}" @selected(request('buyer_id')==$b->id)>{{ $b->name }}</option>@endforeach
    </select>
    @if(request()->hasAny(['status','priority','category','buyer_id','open']))
        <a href="{{ route('purchasing.index', ['range'=>$range->key]) }}" class="btn btn-sm btn-outline-secondary">{{ dt('مسح','Clear') }}</a>
    @endif
</form>

<div class="card p-0">
    <table class="table table-hover align-middle mb-0">
        <thead><tr>
            <th>{{ dt('رقم','Ref') }}</th><th>{{ dt('الصنف','Item') }}</th><th>{{ dt('الجهة الطالبة','Requester') }}</th>
            <th class="text-center">{{ dt('الكمية','Qty') }}</th><th>{{ dt('التصنيف','Category') }}</th><th>{{ dt('الأولوية','Priority') }}</th>
            <th>{{ dt('القائم بالشراء','Buyer') }}</th><th>{{ dt('تاريخ الطلب','Requested') }}</th><th>{{ dt('الحالة','Status') }}</th><th></th>
        </tr></thead>
        <tbody>
        @forelse($requests as $r)
            <tr class="{{ $r->isDelayed() ? 'table-warning' : '' }}">
                <td class="fw-semibold small">{{ $r->reference }}</td>
                <td class="small">{{ Str::limit($r->item_name, 34) }}</td>
                <td class="small">{{ $r->requesting_dept_name ?? optional($r->branch)->branch_name ?? '—' }}</td>
                <td class="text-center small">{{ $r->quantity ? rtrim(rtrim(number_format($r->quantity,2),'0'),'.') : '—' }} {{ $r->unit }}</td>
                <td class="small">{{ $r->category ?? '—' }}</td>
                <td><span class="badge text-bg-{{ $r->priority==='urgent'?'danger':($r->priority==='monthly'?'info':'secondary') }}">{{ $r->priorityAr() }}</span></td>
                <td class="small">{{ optional($r->buyer)->name ?? $r->buyer_name ?? '—' }}</td>
                <td class="small">{{ optional($r->requested_at)->format('d M Y') }}</td>
                <td><span class="badge text-bg-{{ $STC[$r->status] ?? 'secondary' }}">{{ $STA[$r->status] ?? $r->status }}</span>
                    @if($r->isDelayed())<i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="{{ dt('متأخر','Delayed') }}"></i>@endif</td>
                <td class="text-end"><a href="{{ route('purchasing.show', $r) }}" class="btn btn-sm btn-outline-secondary py-0">{{ dt('فتح','Open') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="10" class="text-muted small p-3">{{ dt('لا توجد طلبات في هذه الفترة.','No requests in this period.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{ $requests->links() }}</div>

{{-- New request modal --}}
<div class="modal fade" id="newReq" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="{{ route('purchasing.store') }}">
            @csrf
            <div class="modal-header"><h6 class="modal-title">{{ dt('طلب شراء جديد','New purchase request') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label small">{{ dt('الصنف / البيان','Item') }}</label><input name="item_name" class="form-control" required></div>
                <div class="row g-2">
                    <div class="col"><label class="form-label small">{{ dt('الكمية','Qty') }}</label><input name="quantity" type="number" step="any" class="form-control"></div>
                    <div class="col"><label class="form-label small">{{ dt('الوحدة','Unit') }}</label><input name="unit" class="form-control" placeholder="{{ dt('قطعة / متر / كيلو','pcs / m / kg') }}"></div>
                </div>
                <div class="row g-2 mt-0">
                    <div class="col"><label class="form-label small">{{ dt('التصنيف','Category') }}</label><input name="category" class="form-control" list="catList"><datalist id="catList">@foreach($categories as $c)<option value="{{ $c }}">@endforeach</datalist></div>
                    <div class="col"><label class="form-label small">{{ dt('الأولوية','Priority') }}</label>
                        <select name="priority" class="form-select">
                            @foreach($PRA as $k=>$v)<option value="{{ $k }}" @selected($k==='normal')>{{ $v }}</option>@endforeach
                        </select></div>
                </div>
                <div class="mb-2 mt-2"><label class="form-label small">{{ dt('الفرع (اختياري)','Branch (optional)') }}</label>
                    <select name="branch_id" class="form-select"><option value="">—</option>@foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->branch_name }}</option>@endforeach</select></div>
                <div class="mb-1"><label class="form-label small">{{ dt('ملاحظات','Notes') }}</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">{{ dt('إرسال الطلب','Submit') }}</button></div>
        </form>
    </div>
</div>
@endsection
