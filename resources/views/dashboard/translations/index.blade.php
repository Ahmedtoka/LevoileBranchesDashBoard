@extends('layouts.app')
@section('title', t('nav.strings', 'النصوص'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">{{ t('nav.strings', 'النصوص') }} <span class="text-muted fs-6">(كل مصطلحات النظام)</span></h4>
    <form class="d-flex gap-2" method="GET">
        <input name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="بحث في النصوص…" style="width:240px">
        <button class="btn btn-sm btn-primary">بحث</button>
        @if($q)<a href="{{ route('translations.index') }}" class="btn btn-sm btn-outline-secondary">مسح</a>@endif
    </form>
</div>

@if(session('status'))<div class="alert alert-success py-2">{{ session('status') }}</div>@endif

<form method="POST" action="{{ route('translations.update') }}">
    @csrf @method('PUT')

    @forelse($groups as $group => $items)
        <div class="card p-3 mb-3">
            <h6 class="fw-bold text-capitalize mb-3"><i class="bi bi-collection me-1 text-primary"></i>{{ $group }}</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th style="width:24%">المفتاح</th><th>عربي</th><th>English</th></tr></thead>
                    <tbody>
                    @foreach($items as $tr)
                        <tr>
                            <td><code class="small">{{ $tr->key }}</code></td>
                            <td><input name="t[{{ $tr->id }}][ar]" value="{{ $tr->ar }}" class="form-control form-control-sm" dir="rtl"></td>
                            <td><input name="t[{{ $tr->id }}][en]" value="{{ $tr->en }}" class="form-control form-control-sm" dir="ltr"></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card p-4 text-center text-muted">لا توجد نصوص.</div>
    @endforelse

    {{-- add a new string --}}
    <div class="card p-3 mb-3 border-primary">
        <h6 class="fw-bold mb-2"><i class="bi bi-plus-circle me-1 text-primary"></i>إضافة مصطلح جديد</h6>
        <div class="row g-2">
            <div class="col-md-3"><input name="new_group" class="form-control form-control-sm" placeholder="المجموعة (مثال: common)"></div>
            <div class="col-md-3"><input name="new_key" class="form-control form-control-sm" placeholder="المفتاح (common.save)"></div>
            <div class="col-md-3"><input name="new_ar" class="form-control form-control-sm" placeholder="عربي" dir="rtl"></div>
            <div class="col-md-3"><input name="new_en" class="form-control form-control-sm" placeholder="English" dir="ltr"></div>
        </div>
    </div>

    <div class="position-sticky bottom-0 bg-body py-2">
        <button class="btn btn-primary"><i class="bi bi-save me-1"></i>حفظ كل التعديلات</button>
    </div>
</form>
@endsection
