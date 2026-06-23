@extends('layouts.app')
@section('title', 'Coverage')

@section('content')
<h4 class="fw-bold mb-1">تغطية الفروع</h4>
<p class="text-muted small mb-3">حدّد فروع كل مدير منطقة، فرع كل مدير فرع، والفروع المسؤول عنها كل فني — الفني بتتسند له تذاكر الصيانة في فروعه تلقائياً.</p>

{{-- Area Managers --}}
<div class="card p-3 mb-3">
    <h6 class="fw-bold mb-3"><i class="bi bi-map me-1"></i> مديرو المناطق <span class="text-muted small">(فروع متعددة)</span></h6>
    @forelse($areaManagers as $u)
        @php $cov = $u->branches->pluck('id')->all(); @endphp
        <form method="POST" action="{{ route('coverage.update', $u) }}" class="border-bottom py-3">
            @csrf
            <input type="hidden" name="mode" value="multi">
            <div class="row g-2 align-items-center">
                <div class="col-md-3"><strong>{{ $u->name }}</strong><div class="small text-muted">{{ $u->email }}</div></div>
                <div class="col-md-7">
                    <select name="branch_ids[]" class="form-select form-select-sm" multiple size="4">
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected(in_array($b->id,$cov))>{{ $b->branch_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2 text-end"><button class="btn btn-sm btn-primary w-100">حفظ</button></div>
            </div>
            @if($cov)<div class="small text-muted mt-1">حالياً: {{ $u->branches->pluck('branch_name')->join('، ') }}</div>@endif
        </form>
    @empty
        <p class="text-muted small mb-0">لا يوجد مديرو مناطق.</p>
    @endforelse
</div>

{{-- Store Managers --}}
<div class="card p-3 mb-3">
    <h6 class="fw-bold mb-3"><i class="bi bi-shop me-1"></i> مديرو الفروع <span class="text-muted small">(فرع واحد)</span></h6>
    @forelse($storeManagers as $u)
        <form method="POST" action="{{ route('coverage.update', $u) }}" class="border-bottom py-2">
            @csrf
            <input type="hidden" name="mode" value="single">
            <div class="row g-2 align-items-center">
                <div class="col-md-3"><strong>{{ $u->name }}</strong><div class="small text-muted">{{ $u->email }}</div></div>
                <div class="col-md-7">
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">— بدون فرع —</option>
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected($u->branch_id===$b->id)>{{ $b->branch_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2 text-end"><button class="btn btn-sm btn-primary w-100">حفظ</button></div>
            </div>
        </form>
    @empty
        <p class="text-muted small mb-0">لا يوجد مديرو فروع.</p>
    @endforelse
</div>

{{-- Technicians --}}
<div class="card p-3">
    <h6 class="fw-bold mb-3"><i class="bi bi-tools me-1"></i> فنيو الصيانة <span class="text-muted small">(فروع متعددة — إسناد تلقائي)</span></h6>
    @forelse($technicians as $u)
        @php $cov = $u->branches->pluck('id')->all(); @endphp
        <form method="POST" action="{{ route('coverage.update', $u) }}" class="border-bottom py-3">
            @csrf
            <input type="hidden" name="mode" value="multi">
            <div class="row g-2 align-items-center">
                <div class="col-md-3"><strong>{{ $u->name }}</strong><div class="small text-muted">{{ $u->email }}</div></div>
                <div class="col-md-7">
                    <select name="branch_ids[]" class="form-select form-select-sm" multiple size="4">
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected(in_array($b->id,$cov))>{{ $b->branch_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2 text-end"><button class="btn btn-sm btn-primary w-100">حفظ</button></div>
            </div>
            @if($cov)<div class="small text-muted mt-1">مسؤول عن: {{ $u->branches->pluck('branch_name')->join('، ') }}</div>@endif
        </form>
    @empty
        <p class="text-muted small mb-0">لا يوجد فنيون في إدارة الصيانة.</p>
    @endforelse
    <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> Ctrl/Cmd-click لاختيار أكتر من فرع. أي تذكرة صيانة تنزل في فرع مغطّى بتتسند للفني تلقائياً، وتقدر تغيّرها بعد كده من التذكرة أو بورد الإدارة.</div>
</div>
@endsection
