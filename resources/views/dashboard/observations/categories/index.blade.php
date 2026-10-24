@extends('layouts.app')
@section('title', t('obs.cat.title','فئات الملاحظات'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">{{ t('obs.cat.title','فئات الملاحظات') }} <span class="text-muted fs-6">({{ $categories->count() }})</span></h4>
    <div class="d-flex gap-2">
        @if(Route::has("obs.catalog.index"))<a href="{{ route("obs.catalog.index", $departmentId ? ["department_id" => $departmentId] : []) }}" class="btn btn-outline-secondary"><i class="bi bi-journal-text me-1"></i>{{ t("obs.catalog.title","كتالوج الملاحظات") }}</a>@endif
        <button class="btn btn-primary" onclick="prepCat()"><i class="bi bi-plus-lg"></i> {{ t('obs.cat.new','فئة جديدة') }}</button>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

@unless($locked)
<form class="card p-2 mb-3" method="GET">
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-1">{{ t('obs.reporting_department','الإدارة المُبلِّغة') }}</label>
            <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">{{ t('common.all','الكل') }}</option>
                @foreach($departments as $d)<option value="{{ $d->id }}" @selected($departmentId == $d->id)>{{ $d->name }}</option>@endforeach
            </select></div>
    </div>
</form>
@endunless

@forelse($grouped as $deptId => $rows)
    @php $dept = $rows->first()->department; @endphp
    <div class="card p-0 mb-3">
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <h6 class="fw-bold mb-0"><span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:{{ $dept->color ?? '#64748b' }}"></span>{{ $dept->name }} <span class="text-muted small">({{ $rows->count() }})</span></h6>
            <form method="POST" action="{{ route('obs.categories.reorder') }}" class="d-flex align-items-center gap-2 reorder-form" data-table="cat-{{ $deptId }}">
                @csrf
                <span class="small text-muted">{{ t('obs.cat.reorder_hint','استخدم الأسهم ثم احفظ الترتيب') }}</span>
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-sort-numeric-down"></i> {{ t('obs.cat.save_order','حفظ الترتيب') }}</button>
            </form>
        </div>
        <table class="table table-hover align-middle mb-0" id="cat-{{ $deptId }}">
            <thead><tr>
                <th style="width:90px">{{ t('obs.sort','الترتيب') }}</th>
                <th>{{ t('obs.cat.name','الفئة') }}</th>
                <th>{{ t('obs.kind','النوع') }}</th>
                <th>{{ t('obs.risk_level','درجة الخطورة') }}</th>
                <th>{{ t('obs.responsible','الإدارة المسؤولة') }}</th>
                <th>{{ t('obs.sla','المهلة') }}</th>
                <th class="text-center">{{ t('obs.cat.items','بنود') }}</th>
                <th class="text-center">{{ t('obs.cat.observations','ملاحظات') }}</th>
                <th class="text-center">{{ t('common.active','نشط') }}</th>
                <th></th>
            </tr></thead>
            <tbody>
            @foreach($rows as $c)
                @php $json = $c->only(['id','department_id','name_ar','name_en','default_kind','default_risk_level_id','default_responsible_department_id','default_cc_department_ids','default_sla_hours','sort_order','active']); @endphp
                <tr data-id="{{ $c->id }}" class="{{ $c->active ? '' : 'opacity-50' }}">
                    <td class="text-nowrap">
                        <button type="button" class="btn btn-sm btn-light move-up" title="up"><i class="bi bi-arrow-up"></i></button>
                        <button type="button" class="btn btn-sm btn-light move-down" title="down"><i class="bi bi-arrow-down"></i></button>
                    </td>
                    <td class="fw-semibold">{{ $c->name_ar }} <div class="small text-muted">{{ $c->name_en }} · <code>{{ $c->slug }}</code></div></td>
                    <td><span class="badge text-bg-light">{{ t('obs.kind.'.$c->default_kind, $c->default_kind) }}</span></td>
                    <td>@if($c->riskLevel)<span class="badge" style="background:{{ $c->riskLevel->color }}">{{ app()->getLocale()==='en' ? $c->riskLevel->name_en : $c->riskLevel->name_ar }}</span>@else <span class="text-muted">—</span> @endif</td>
                    <td>{{ optional($c->responsibleDepartment)->name ?? '—' }}</td>
                    <td class="small">{{ $c->default_sla_hours ? $c->default_sla_hours.' '.t('obs.hours','س') : '—' }}</td>
                    <td class="text-center"><span class="badge text-bg-light">{{ $c->items_count }}</span></td>
                    <td class="text-center"><span class="badge text-bg-light">{{ $c->observations_count }}</span></td>
                    <td class="text-center">{!! $c->active ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-secondary"></i>' !!}</td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-link p-0 me-2 edit-cat" data-c='@json($json)'>{{ t('common.edit','تعديل') }}</button>
                        <form method="POST" action="{{ route('obs.categories.destroy', $c) }}" class="d-inline" onsubmit="return confirm('{{ t('obs.cat.confirm_delete','حذف الفئة؟') }}')">
                            @csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger p-0" {{ ($c->items_count || $c->observations_count) ? 'disabled' : '' }}>{{ t('common.delete','حذف') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@empty
    <div class="card p-4 text-center text-muted">{{ t('obs.cat.empty','لا توجد فئات. أضف واحدة أو شغّل ObservationSetupSeeder.') }}</div>
@endforelse

{{-- Category modal (add / edit) --}}
<div class="modal fade" id="catModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" id="catForm">
            @csrf
            <input type="hidden" name="_method" id="catMethod" value="POST">
            <div class="modal-header"><h6 class="modal-title" id="catTitle">{{ t('obs.cat.new','فئة جديدة') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label small">{{ t('obs.reporting_department','الإدارة المُبلِّغة') }}</label>
                        <select name="department_id" id="catDept" class="form-select" required {{ $locked ? 'readonly' : '' }}>
                            @foreach($departments as $d)<option value="{{ $d->id }}" @selected(($locked ?? $departmentId) == $d->id)>{{ $d->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label small">{{ t('obs.name_ar','الاسم بالعربي') }}</label><input name="name_ar" id="catNameAr" class="form-control" required></div>
                    <div class="col-md-4"><label class="form-label small">{{ t('obs.name_en','الاسم بالإنجليزي') }}</label><input name="name_en" id="catNameEn" class="form-control" required></div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-3">
                        <label class="form-label small">{{ t('obs.kind','النوع') }}</label>
                        <select name="default_kind" id="catKind" class="form-select">
                            @foreach(\App\Models\ObservationCategory::KINDS as $k)<option value="{{ $k }}">{{ t('obs.kind.'.$k, $k) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ t('obs.risk_level','درجة الخطورة') }}</label>
                        <select name="default_risk_level_id" id="catRisk" class="form-select">
                            <option value="">—</option>
                            @foreach($riskLevels as $r)<option value="{{ $r->id }}">{{ app()->getLocale()==='en' ? $r->name_en : $r->name_ar }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ t('obs.responsible','الإدارة المسؤولة') }}</label>
                        <select name="default_responsible_department_id" id="catResp" class="form-select">
                            <option value="">—</option>
                            @foreach($allDepartments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label small">{{ t('obs.sla_hours','المهلة (ساعات)') }}</label><input name="default_sla_hours" id="catSla" type="number" min="1" class="form-control" placeholder="{{ t('obs.sla_from_risk','من درجة الخطورة') }}"></div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-9">
                        <label class="form-label small">{{ t('obs.cc','إدارات تُبلَّغ بدون تيكت (CC)') }}</label>
                        <select name="default_cc_department_ids[]" id="catCc" class="form-select" multiple size="4">
                            @foreach($allDepartments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ t('obs.sort','الترتيب') }}</label><input name="sort_order" id="catSort" type="number" min="0" class="form-control">
                        <div class="form-check mt-2"><input type="checkbox" name="active" value="1" class="form-check-input" id="catActive" checked><label class="form-check-label small" for="catActive">{{ t('common.active','نشط') }}</label></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">{{ t('obs.cat.save','حفظ الفئة') }}</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function prepCat() {
    const f = document.getElementById('catForm');
    f.action = '{{ route('obs.categories.store') }}';
    document.getElementById('catMethod').value = 'POST';
    document.getElementById('catTitle').textContent = @json(t('obs.cat.new','فئة جديدة'));
    ['catNameAr','catNameEn','catSla','catSort'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('catKind').value = 'issue';
    document.getElementById('catRisk').value = '';
    document.getElementById('catResp').value = '';
    Array.from(document.getElementById('catCc').options).forEach(o => o.selected = false);
    document.getElementById('catActive').checked = true;
    document.getElementById('catDept').disabled = false;
    new bootstrap.Modal(document.getElementById('catModal')).show();
}
document.querySelectorAll('.edit-cat').forEach(btn => btn.addEventListener('click', () => {
    const c = JSON.parse(btn.dataset.c);
    const f = document.getElementById('catForm');
    f.action = '{{ url('/admin/observation-categories') }}/' + c.id;
    document.getElementById('catMethod').value = 'PUT';
    document.getElementById('catTitle').textContent = @json(t('obs.cat.edit','تعديل فئة'));
    document.getElementById('catDept').value = c.department_id;
    document.getElementById('catNameAr').value = c.name_ar || '';
    document.getElementById('catNameEn').value = c.name_en || '';
    document.getElementById('catKind').value = c.default_kind || 'issue';
    document.getElementById('catRisk').value = c.default_risk_level_id || '';
    document.getElementById('catResp').value = c.default_responsible_department_id || '';
    document.getElementById('catSla').value = c.default_sla_hours ?? '';
    document.getElementById('catSort').value = c.sort_order ?? '';
    const cc = (c.default_cc_department_ids || []).map(String);
    Array.from(document.getElementById('catCc').options).forEach(o => o.selected = cc.includes(o.value));
    document.getElementById('catActive').checked = !!c.active;
    new bootstrap.Modal(document.getElementById('catModal')).show();
}));
// Up/down reorder, then POST ids[] in display order.
document.querySelectorAll('.move-up, .move-down').forEach(b => b.addEventListener('click', () => {
    const tr = b.closest('tr');
    if (b.classList.contains('move-up') && tr.previousElementSibling) tr.parentNode.insertBefore(tr, tr.previousElementSibling);
    if (b.classList.contains('move-down') && tr.nextElementSibling) tr.parentNode.insertBefore(tr.nextElementSibling, tr);
}));
document.querySelectorAll('.reorder-form').forEach(f => f.addEventListener('submit', () => {
    f.querySelectorAll('input[name="ids[]"]').forEach(i => i.remove());
    document.querySelectorAll('#' + f.dataset.table + ' tbody tr').forEach(tr => {
        const i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = tr.dataset.id; f.appendChild(i);
    });
}));
</script>
@endpush
