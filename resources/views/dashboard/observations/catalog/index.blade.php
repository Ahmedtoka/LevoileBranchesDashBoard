@extends('layouts.app')
@section('title', t('obs.catalog.title','كتالوج الملاحظات'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">{{ t('obs.catalog.title','كتالوج الملاحظات') }} <span class="text-muted fs-6">({{ $items->count() }})</span></h4>
    <div class="d-flex gap-2">
        <a href="{{ route('obs.categories.index', $departmentId ? ['department_id' => $departmentId] : []) }}" class="btn btn-outline-secondary"><i class="bi bi-tags me-1"></i>{{ t('obs.cat.title','فئات الملاحظات') }}</a>
        <button class="btn btn-primary" onclick="prepItem()" {{ $categories->isEmpty() ? 'disabled' : '' }}><i class="bi bi-plus-lg"></i> {{ t('obs.catalog.new','بند جديد') }}</button>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<form class="card p-2 mb-3" method="GET">
    <div class="row g-2 align-items-end">
        @unless($locked)
        <div class="col-md-3"><label class="form-label small mb-1">{{ t('obs.reporting_department','الإدارة المُبلِّغة') }}</label>
            <select name="department_id" class="form-select form-select-sm" onchange="this.form.category_id.value='';this.form.submit()">
                <option value="">{{ t('common.all','الكل') }}</option>
                @foreach($departments as $d)<option value="{{ $d->id }}" @selected($departmentId == $d->id)>{{ $d->name }}</option>@endforeach
            </select></div>
        @endunless
        <div class="col-md-3"><label class="form-label small mb-1">{{ t('obs.cat.name','الفئة') }}</label>
            <select name="category_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">{{ t('common.all','الكل') }}</option>
                @foreach($categories as $c)<option value="{{ $c->id }}" @selected($categoryId == $c->id)>{{ $locked ? '' : $c->department->name.' · ' }}{{ $c->name_ar }}</option>@endforeach
            </select></div>
        <div class="col-md-4"><label class="form-label small mb-1">{{ t('common.search','بحث') }}</label>
            <input name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="{{ t('obs.catalog.search_ph','كود أو نص البند…') }}"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary">{{ t('common.search','بحث') }}</button>
            <a href="{{ route('obs.catalog.index') }}" class="btn btn-sm btn-outline-secondary">{{ t('common.reset','مسح') }}</a></div>
    </div>
</form>

@forelse($grouped as $catId => $rows)
    @php $cat = $rows->first()->category; @endphp
    <div class="card p-0 mb-3">
        <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0">{{ $cat->department->name }} <i class="bi bi-chevron-left small text-muted mx-1"></i> {{ $cat->name_ar }} <span class="text-muted small">· {{ $cat->name_en }} ({{ $rows->count() }})</span></h6>
            <button class="btn btn-sm btn-outline-primary" onclick="prepItem({{ $cat->id }})"><i class="bi bi-plus"></i> {{ t('obs.catalog.new','بند جديد') }}</button>
        </div>
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th>{{ t('obs.catalog.code','الكود') }}</th>
                <th>{{ t('obs.catalog.label','البند') }}</th>
                <th>{{ t('obs.kind','النوع') }}</th>
                <th>{{ t('obs.risk_level','درجة الخطورة') }}</th>
                <th>{{ t('obs.responsible','الإدارة المسؤولة') }}</th>
                <th>{{ t('obs.sla','المهلة') }}</th>
                <th class="text-center" title="extra fields / rules"><i class="bi bi-input-cursor-text"></i> / <i class="bi bi-lightning"></i></th>
                <th class="text-center">{{ t('obs.catalog.usage','استخدام') }}</th>
                <th class="text-center">{{ t('common.active','نشط') }}</th>
                <th></th>
            </tr></thead>
            <tbody>
            @foreach($rows as $i)
                @php $json = $i->only(['id','category_id','code','label_ar','label_en','keywords','kind','risk_level_id','responsible_department_id','cc_department_ids','sla_hours','extra_fields','escalation_rules','sort_order','active']); @endphp
                <tr class="{{ $i->active ? '' : 'opacity-50' }}">
                    <td><code>{{ $i->code }}</code></td>
                    <td class="fw-semibold">{{ $i->label_ar }}<div class="small text-muted fw-normal">{{ $i->label_en }}</div></td>
                    <td><span class="badge text-bg-light">{{ t('obs.kind.'.$i->kind, $i->kind) }}</span></td>
                    <td>@if($i->riskLevel)<span class="badge" style="background:{{ $i->riskLevel->color }}">{{ app()->getLocale()==='en' ? $i->riskLevel->name_en : $i->riskLevel->name_ar }}</span>@else<span class="text-muted small">{{ t('obs.from_category','من الفئة') }}</span>@endif</td>
                    <td class="small">{{ optional($i->responsibleDepartment)->name ?? t('obs.from_category','من الفئة') }}</td>
                    <td class="small">{{ $i->sla_hours ? $i->sla_hours.' '.t('obs.hours','س') : '—' }}</td>
                    <td class="text-center small text-muted">{{ count($i->extra_fields ?? []) }} / {{ count($i->escalation_rules ?? []) }}</td>
                    <td class="text-center"><span class="badge text-bg-light" title="usage_count / observations">{{ $i->usage_count }}@if($i->observations_count) · {{ $i->observations_count }}@endif</span></td>
                    <td class="text-center">
                        <form method="POST" action="{{ route('obs.catalog.toggle', $i) }}" class="d-inline">@csrf
                            <button class="btn btn-sm btn-link p-0" title="{{ t('obs.catalog.toggle','تفعيل / تعطيل') }}">{!! $i->active ? '<i class="bi bi-toggle-on text-success fs-5"></i>' : '<i class="bi bi-toggle-off text-secondary fs-5"></i>' !!}</button>
                        </form>
                    </td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-link p-0 me-2 edit-item" data-i='@json($json)'>{{ t('common.edit','تعديل') }}</button>
                        <form method="POST" action="{{ route('obs.catalog.destroy', $i) }}" class="d-inline" onsubmit="return confirm('{{ t('obs.catalog.confirm_delete','حذف البند؟') }}')">
                            @csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger p-0" {{ ($i->usage_count || $i->observations_count) ? 'disabled title="'.t('obs.catalog.used','مستخدم: عطّله بدلاً من الحذف').'"' : '' }}>{{ t('common.delete','حذف') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@empty
    <div class="card p-4 text-center text-muted">{{ t('obs.catalog.empty','لا توجد بنود مطابقة.') }}</div>
@endforelse

<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form class="modal-content" method="POST" id="itemForm">
            @csrf
            <input type="hidden" name="_method" id="itMethod" value="POST">
            <div class="modal-header"><h6 class="modal-title" id="itTitle">{{ t('obs.catalog.new','بند جديد') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                @include('dashboard.observations.catalog._form')
            </div>
            <div class="modal-footer"><button class="btn btn-primary">{{ t('obs.catalog.save','حفظ البند') }}</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const FIELD_TYPES = @json(\App\Http\Requests\Observations\CatalogItemRequest::FIELD_TYPES);
const RULE_OPS = {gte: '≥', lte: '≤', eq: '='};
const RISKS = @json($riskLevels->map(fn ($r) => ['slug' => $r->slug, 'name' => app()->getLocale()==='en' ? $r->name_en : $r->name_ar])->values());
let fieldIdx = 0, ruleIdx = 0;

function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
function addFieldRow(f = {}) {
    const i = fieldIdx++;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input name="extra_fields[${i}][key]" class="form-control form-control-sm" value="${esc(f.key)}" placeholder="minutes_late"></td>
        <td><input name="extra_fields[${i}][label_ar]" class="form-control form-control-sm" value="${esc(f.label_ar)}"></td>
        <td><input name="extra_fields[${i}][label_en]" class="form-control form-control-sm" value="${esc(f.label_en)}"></td>
        <td><select name="extra_fields[${i}][type]" class="form-select form-select-sm">${FIELD_TYPES.map(t => `<option value="${t}" ${f.type===t?'selected':''}>${t}</option>`).join('')}</select></td>
        <td><input name="extra_fields[${i}][options]" class="form-control form-control-sm" value="${esc(Array.isArray(f.options) ? f.options.join(', ') : (f.options ?? ''))}" placeholder="a, b, c"></td>
        <td class="text-center"><input type="checkbox" name="extra_fields[${i}][required]" value="1" class="form-check-input" ${f.required ? 'checked' : ''}></td>
        <td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove()"><i class="bi bi-x-lg"></i></button></td>`;
    document.querySelector('#fieldsTable tbody').appendChild(tr);
}
function addRuleRow(r = {}) {
    const i = ruleIdx++;
    const op = Object.keys(RULE_OPS).find(o => o in r) || 'gte';
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input name="escalation_rules[${i}][field]" class="form-control form-control-sm" value="${esc(r.field)}" placeholder="minutes_late"></td>
        <td><select name="escalation_rules[${i}][op]" class="form-select form-select-sm">${Object.entries(RULE_OPS).map(([k,v]) => `<option value="${k}" ${op===k?'selected':''}>${v}</option>`).join('')}</select></td>
        <td><input name="escalation_rules[${i}][value]" type="number" step="any" class="form-control form-control-sm" value="${esc(r[op])}"></td>
        <td><select name="escalation_rules[${i}][risk]" class="form-select form-select-sm">${RISKS.map(x => `<option value="${x.slug}" ${r.risk===x.slug?'selected':''}>${x.name}</option>`).join('')}</select></td>
        <td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove()"><i class="bi bi-x-lg"></i></button></td>`;
    document.querySelector('#rulesTable tbody').appendChild(tr);
}
function resetItemForm() {
    ['itCode','itLabelAr','itLabelEn','itKeywords','itSla','itSort'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('itKind').value = 'issue';
    document.getElementById('itRisk').value = '';
    document.getElementById('itResp').value = '';
    Array.from(document.getElementById('itCc').options).forEach(o => o.selected = false);
    document.getElementById('itActive').checked = true;
    document.querySelector('#fieldsTable tbody').innerHTML = '';
    document.querySelector('#rulesTable tbody').innerHTML = '';
}
function prepItem(categoryId) {
    const f = document.getElementById('itemForm');
    f.action = '{{ route('obs.catalog.store') }}';
    document.getElementById('itMethod').value = 'POST';
    document.getElementById('itTitle').textContent = @json(t('obs.catalog.new','بند جديد'));
    resetItemForm();
    if (categoryId) document.getElementById('itCategory').value = categoryId;
    @if($categoryId) else document.getElementById('itCategory').value = {{ $categoryId }}; @endif
    new bootstrap.Modal(document.getElementById('itemModal')).show();
}
document.querySelectorAll('.edit-item').forEach(btn => btn.addEventListener('click', () => {
    const it = JSON.parse(btn.dataset.i);
    const f = document.getElementById('itemForm');
    f.action = '{{ url('/admin/observation-catalog') }}/' + it.id;
    document.getElementById('itMethod').value = 'PUT';
    document.getElementById('itTitle').textContent = @json(t('obs.catalog.edit','تعديل بند')) + ' · ' + it.code;
    resetItemForm();
    document.getElementById('itCategory').value = it.category_id;
    document.getElementById('itCode').value = it.code || '';
    document.getElementById('itKind').value = it.kind || 'issue';
    document.getElementById('itSort').value = it.sort_order ?? '';
    document.getElementById('itLabelAr').value = it.label_ar || '';
    document.getElementById('itLabelEn').value = it.label_en || '';
    document.getElementById('itKeywords').value = (it.keywords || []).join(', ');
    document.getElementById('itRisk').value = it.risk_level_id || '';
    document.getElementById('itResp').value = it.responsible_department_id || '';
    document.getElementById('itSla').value = it.sla_hours ?? '';
    const cc = (it.cc_department_ids || []).map(String);
    Array.from(document.getElementById('itCc').options).forEach(o => o.selected = cc.includes(o.value));
    document.getElementById('itActive').checked = !!it.active;
    (it.extra_fields || []).forEach(addFieldRow);
    let rules = it.escalation_rules || [];
    if (rules && !Array.isArray(rules)) rules = [rules];
    rules.forEach(addRuleRow);
    new bootstrap.Modal(document.getElementById('itemModal')).show();
}));
</script>
@endpush
