@extends('layouts.app')
@section('title', 'Vendors')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-truck text-primary me-2"></i>{{ dt('الموردين','Vendors') }}</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#vModal" onclick="vendorForm()"><i class="bi bi-plus-lg me-1"></i>{{ dt('مورد جديد','New vendor') }}</button>
</div>

<form class="mb-3" method="GET">
    <div class="input-group input-group-sm" style="max-width:320px">
        <input name="q" value="{{ request('q') }}" class="form-control" placeholder="{{ dt('بحث بالاسم / التخصص…','Search name / specialty…') }}">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </div>
</form>

<div class="card p-0">
    <table class="table table-hover align-middle mb-0">
        <thead><tr>
            <th>{{ dt('المورد','Vendor') }}</th><th>{{ dt('التخصص','Specialty') }}</th><th>{{ dt('الهاتف','Phone') }}</th>
            <th>{{ dt('مدة التوريد','Supply time') }}</th><th class="text-center">{{ dt('التقييم','Rating') }}</th>
            <th class="text-center">{{ dt('عروض','Quotes') }}</th><th class="text-center">{{ dt('أوامر','Orders') }}</th><th></th>
        </tr></thead>
        <tbody>
        @forelse($vendors as $v)
            <tr class="{{ $v->active ? '' : 'text-muted' }}">
                <td class="fw-semibold small">{{ $v->name }} @unless($v->active)<span class="badge text-bg-light">{{ dt('غير نشط','inactive') }}</span>@endunless</td>
                <td class="small">{{ $v->specialty ?? '—' }}</td>
                <td class="small">{{ $v->phone ?? '—' }}</td>
                <td class="small">{{ $v->supply_duration ?? '—' }}</td>
                <td class="text-center small">@if($v->rating)@for($i=0;$i<$v->rating;$i++)<i class="bi bi-star-fill text-warning"></i>@endfor @else — @endif</td>
                <td class="text-center small">{{ $v->quotations_count }}</td>
                <td class="text-center small">{{ $v->orders_count }}</td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="modal" data-bs-target="#vModal"
                        onclick='vendorForm(@json($v))'>{{ dt('تعديل','Edit') }}</button>
                    <form method="POST" action="{{ route('vendors.destroy', $v) }}" class="d-inline" onsubmit="return confirm('{{ dt('حذف المورد؟','Delete vendor?') }}')">
                        @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger py-0">×</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-muted small p-3">{{ dt('لا يوجد موردون.','No vendors.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{ $vendors->links() }}</div>

<div class="modal fade" id="vModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" id="vForm" action="{{ route('vendors.store') }}">
            @csrf
            <input type="hidden" name="_method" id="vMethod" value="POST">
            <div class="modal-header"><h6 class="modal-title" id="vTitle">{{ dt('مورد جديد','New vendor') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label small">{{ dt('الاسم','Name') }}</label><input name="name" id="vName" class="form-control" required></div>
                <div class="row g-2">
                    <div class="col"><label class="form-label small">{{ dt('التخصص','Specialty') }}</label><input name="specialty" id="vSpec" class="form-control"></div>
                    <div class="col"><label class="form-label small">{{ dt('الهاتف','Phone') }}</label><input name="phone" id="vPhone" class="form-control"></div>
                </div>
                <div class="mb-2 mt-2"><label class="form-label small">{{ dt('العنوان','Address') }}</label><input name="address" id="vAddr" class="form-control"></div>
                <div class="row g-2">
                    <div class="col"><label class="form-label small">{{ dt('مدة التوريد','Supply time') }}</label><input name="supply_duration" id="vDur" class="form-control"></div>
                    <div class="col"><label class="form-label small">{{ dt('التقييم (1-5)','Rating') }}</label><input name="rating" id="vRate" type="number" min="1" max="5" class="form-control"></div>
                </div>
                <div class="form-check mt-2"><input type="checkbox" name="active" id="vActive" value="1" class="form-check-input" checked><label class="form-check-label small">{{ dt('نشط','Active') }}</label></div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">{{ dt('حفظ','Save') }}</button></div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function vendorForm(v) {
    const f = document.getElementById('vForm');
    if (v) {
        f.action = '/purchasing/vendors/' + v.id;
        document.getElementById('vMethod').value = 'PUT';
        document.getElementById('vTitle').textContent = '{{ dt('تعديل المورد','Edit vendor') }}';
        vName.value=v.name||''; vSpec.value=v.specialty||''; vPhone.value=v.phone||'';
        vAddr.value=v.address||''; vDur.value=v.supply_duration||''; vRate.value=v.rating||''; vActive.checked=!!v.active;
    } else {
        f.action = '{{ route('vendors.store') }}';
        document.getElementById('vMethod').value = 'POST';
        document.getElementById('vTitle').textContent = '{{ dt('مورد جديد','New vendor') }}';
        f.reset();
    }
}
</script>
@endpush
@endsection
