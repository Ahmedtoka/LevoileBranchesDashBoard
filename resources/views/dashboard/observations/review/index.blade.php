@extends('layouts.app')
@section('title', t('obs.review.title','اعتماد التصنيفات'))

@section('content')
@php $en = app()->getLocale() === 'en'; @endphp
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">{{ t('obs.review.title','اعتماد التصنيفات') }} <span class="text-muted fs-6">({{ $observations->count() }})</span></h4>
    <a href="{{ route('obs.catalog.index', $departmentId ? ['department_id' => $departmentId] : []) }}" class="btn btn-outline-secondary"><i class="bi bi-journal-text me-1"></i>{{ t('obs.catalog.title','كتالوج الملاحظات') }}</a>
</div>
<p class="text-muted small">{{ t('obs.review.intro','ملاحظات حرة صنّفها الإيجينت وأكدها الموظف. اعتمد التصنيف، أو عدّله، أو ادمجه في بند كتالوج، أو أنشئ بنداً جديداً منه. كل قرار يُعلّم الإيجينت.') }}</p>

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

@if($similar->isNotEmpty())
<div class="card p-3 mb-3 border-start border-4 border-primary">
    <h6 class="fw-bold"><i class="bi bi-collection me-1"></i>{{ t('obs.review.similar','دمج الكل المشابه') }}</h6>
    <div class="small text-muted mb-2">{{ t('obs.review.similar_hint','الإيجينت اقترح نفس البند لهذه الملاحظات. ادمجهم دفعة واحدة.') }}</div>
    @foreach($similar as $itemId => $group)
        @php $it = $itemsById[$itemId]; @endphp
        <form method="POST" action="{{ route('obs.review.bulk') }}" class="d-flex flex-wrap align-items-center gap-2 border-top py-2">
            @csrf
            <input type="hidden" name="catalog_item_id" value="{{ $it->id }}">
            @foreach($group as $o)<input type="hidden" name="observation_ids[]" value="{{ $o->id }}">@endforeach
            <code>{{ $it->code }}</code> <span class="fw-semibold">{{ $en ? $it->label_en : $it->label_ar }}</span>
            <span class="badge text-bg-light">{{ $group->count() }} {{ t('obs.review.observations','ملاحظة') }}</span>
            <span class="small text-muted text-truncate" style="max-width:420px">{{ $group->pluck('reference')->join('، ') }}</span>
            <button class="btn btn-sm btn-primary ms-auto"><i class="bi bi-check2-all"></i> {{ t('obs.review.merge_all','دمج الكل') }}</button>
        </form>
    @endforeach
</div>
@endif

@forelse($observations as $o)
    @php
        $s = $o->classification?->suggested ?? [];
        $f = $o->classification?->employee_final ?? [];
        $deptId = $o->reporting_department_id;
        $sItem = ($s['catalog_item_id'] ?? null) ? $itemsById->get($s['catalog_item_id']) : null;
        $sCat = ($s['category_id'] ?? null) ? ($categories->get($deptId) ?? collect())->firstWhere('id', $s['category_id']) : null;
        $sRisk = ($s['risk_level'] ?? null) ? $riskLevels->firstWhere('slug', $s['risk_level']) : null;
        $sDept = ($s['responsible_department_id'] ?? null) ? $allDepartments->firstWhere('id', $s['responsible_department_id']) : null;
        $conf = isset($s['confidence']) ? round($s['confidence'] * 100) : null;
        $json = [
            'id' => $o->id, 'reference' => $o->reference, 'department_id' => $deptId, 'title' => $o->title, 'description' => $o->description,
            'category_id' => $o->category_id, 'risk_level_id' => $o->risk_level_id, 'responsible_department_id' => $o->responsible_department_id,
            'cc_department_ids' => $o->cc_department_ids ?? [], 'kind' => $o->kind, 'suggested_item_id' => $s['catalog_item_id'] ?? null,
            'dept_prefix' => strtoupper(substr(optional($o->reportingDepartment)->ticket_prefix ?: optional($o->reportingDepartment)->slug ?: 'OBS', 0, 5)),
        ];
    @endphp
    <div class="card p-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <span class="fw-bold">{{ $o->reference }}</span>
                <span class="text-muted small ms-2"><i class="bi bi-geo-alt"></i> {{ optional($o->branch)->branch_name ?? t('obs.all_branches','كل الفروع') }}</span>
                <span class="text-muted small ms-2"><i class="bi bi-building"></i> {{ optional($o->reportingDepartment)->name }}</span>
                <span class="text-muted small ms-2"><i class="bi bi-calendar"></i> {{ optional($o->event_date)->format('Y-m-d') }}</span>
                <span class="text-muted small ms-2"><i class="bi bi-person"></i> {{ optional($o->creator)->name }}</span>
                @if($o->ticket)
                    <a href="{{ route('tickets.show', $o->ticket) }}" class="badge text-bg-{{ $o->ticket->status === 'closed' ? 'success' : 'warning' }} text-decoration-none ms-2">{{ $o->ticket->reference }} · {{ $o->ticket->status }}</a>
                @endif
            </div>
            <span class="badge text-bg-light">{{ t('obs.kind.'.$o->kind, $o->kind) }}</span>
        </div>
        <div class="mt-2 fw-semibold">{{ $o->title }}</div>
        @if($o->description !== $o->title)<div class="small text-muted">{{ $o->description }}</div>@endif

        <div class="row g-3 mt-2">
            <div class="col-md-6">
                <div class="small text-muted mb-1"><i class="bi bi-robot me-1"></i>{{ t('obs.review.agent','اقتراح الإيجينت') }} @if($conf !== null)<span class="badge {{ $conf >= 75 ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $conf }}%</span>@endif</div>
                @if($o->classification)
                    <div class="small">
                        @if($sItem)<div><i class="bi bi-journal-text"></i> <code>{{ $sItem->code }}</code> {{ $en ? $sItem->label_en : $sItem->label_ar }}</div>@endif
                        <div><i class="bi bi-tag"></i> {{ $sCat ? ($en ? $sCat->name_en : $sCat->name_ar) : '—' }}
                            · @if($sRisk)<span class="badge" style="background:{{ $sRisk->color }}">{{ $en ? $sRisk->name_en : $sRisk->name_ar }}</span>@else — @endif
                            · {{ optional($sDept)->name ?? '—' }}</div>
                        @if(!empty($s['reason']))<div class="text-muted fst-italic">{{ $s['reason'] }}</div>@endif
                        @if($o->classification->status !== 'ok')<div class="text-danger">{{ t('obs.review.agent_failed','الفحص لم يكتمل') }} ({{ $o->classification->status }})</div>@endif
                    </div>
                @else
                    <div class="small text-muted">—</div>
                @endif
            </div>
            <div class="col-md-6">
                <div class="small text-muted mb-1"><i class="bi bi-person-check me-1"></i>{{ t('obs.review.employee','تأكيد الموظف (الحالي)') }}</div>
                <div class="small">
                    @if($o->catalogItem)<div><i class="bi bi-journal-text"></i> <code>{{ $o->catalogItem->code }}</code></div>@endif
                    <div><i class="bi bi-tag"></i> {{ $o->category ? ($en ? $o->category->name_en : $o->category->name_ar) : '—' }}
                        · @if($o->riskLevel)<span class="badge" style="background:{{ $o->riskLevel->color }}">{{ $en ? $o->riskLevel->name_en : $o->riskLevel->name_ar }}</span>@else — @endif
                        · {{ optional($o->responsibleDepartment)->name ?? '—' }}</div>
                    @if(!empty($f) && (($f['category_id'] ?? null) != $o->category_id || ($f['risk_level'] ?? null) != optional($o->riskLevel)->slug))
                        <div class="text-muted">{{ t('obs.review.changed_since','تغيّر بعد التأكيد') }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <form method="POST" action="{{ route('obs.review.approve', $o) }}">@csrf
                <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> {{ t('obs.review.approve','اعتماد') }}</button>
            </form>
            <button class="btn btn-sm btn-outline-secondary rv-edit" data-o='@json($json)'><i class="bi bi-pencil"></i> {{ t('obs.review.edit','تعديل') }}</button>
            <button class="btn btn-sm btn-outline-primary rv-merge" data-o='@json($json)' {{ ($itemsByDepartment->get($deptId) ?? collect())->isEmpty() ? 'disabled' : '' }}><i class="bi bi-journal-arrow-down"></i> {{ t('obs.review.merge','دمج في بند') }}</button>
            <button class="btn btn-sm btn-outline-primary rv-promote" data-o='@json($json)' {{ ($categories->get($deptId) ?? collect())->isEmpty() ? 'disabled' : '' }}><i class="bi bi-journal-plus"></i> {{ t('obs.review.promote','إضافة كبند جديد') }}</button>
        </div>
    </div>
@empty
    <div class="card p-4 text-center text-muted"><i class="bi bi-check2-circle fs-3 d-block mb-2"></i>{{ t('obs.review.empty','لا توجد تصنيفات في انتظار الاعتماد.') }}</div>
@endforelse

{{-- Edit modal --}}
<div class="modal fade" id="rvEditModal" tabindex="-1"><div class="modal-dialog">
    <form class="modal-content" method="POST" id="rvEditForm">@csrf @method('PUT')
        <div class="modal-header"><h6 class="modal-title">{{ t('obs.review.edit_title','تعديل التصنيف') }} · <span class="rv-ref"></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label small">{{ t('obs.cat.name','الفئة') }}</label><select name="category_id" class="form-select rv-category"></select></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label small">{{ t('obs.risk_level','درجة الخطورة') }}</label>
                    <select name="risk_level_id" class="form-select rv-risk"><option value="">—</option>@foreach($riskLevels as $r)<option value="{{ $r->id }}">{{ $en ? $r->name_en : $r->name_ar }}</option>@endforeach</select></div>
                <div class="col-6"><label class="form-label small">{{ t('obs.responsible','الإدارة المسؤولة') }}</label>
                    <select name="responsible_department_id" class="form-select rv-resp"><option value="">—</option>@foreach($allDepartments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
            </div>
            <div class="mt-2"><label class="form-label small">{{ t('obs.cc','CC') }}</label>
                <select name="cc_department_ids[]" class="form-select rv-cc" multiple size="3">@foreach($allDepartments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
            <div class="mt-2"><label class="form-label small">{{ t('obs.review.note','ملاحظة (تُسجّل على التيكت)') }}</label><input name="note" class="form-control"></div>
            <div class="form-text">{{ t('obs.review.edit_hint','لو التيكت مفتوح: تتحدث الأولوية والإدارة ويُلغى التعيين.') }}</div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">{{ t('obs.review.save_approve','حفظ واعتماد') }}</button></div>
    </form>
</div></div>

{{-- Merge modal --}}
<div class="modal fade" id="rvMergeModal" tabindex="-1"><div class="modal-dialog">
    <form class="modal-content" method="POST" id="rvMergeForm">@csrf
        <div class="modal-header"><h6 class="modal-title">{{ t('obs.review.merge_title','دمج في بند كتالوج') }} · <span class="rv-ref"></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="small text-muted mb-2 rv-text"></div>
            <div class="mb-2"><label class="form-label small">{{ t('obs.review.item','البند') }}</label><select name="catalog_item_id" class="form-select rv-item" required></select></div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label small">{{ t('obs.risk_level','درجة الخطورة') }} <span class="text-muted">({{ t('obs.review.override','تجاوز اختياري') }})</span></label>
                    <select name="risk_level_id" class="form-select"><option value="">{{ t('obs.review.from_item','من البند') }}</option>@foreach($riskLevels as $r)<option value="{{ $r->id }}">{{ $en ? $r->name_en : $r->name_ar }}</option>@endforeach</select></div>
                <div class="col-6"><label class="form-label small">{{ t('obs.responsible','الإدارة المسؤولة') }} <span class="text-muted">({{ t('obs.review.override','تجاوز اختياري') }})</span></label>
                    <select name="responsible_department_id" class="form-select"><option value="">{{ t('obs.review.from_item','من البند') }}</option>@foreach($allDepartments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
            </div>
            <div class="mt-2"><label class="form-label small">{{ t('obs.review.note','ملاحظة (تُسجّل على التيكت)') }}</label><input name="note" class="form-control"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">{{ t('obs.review.merge','دمج في بند') }}</button></div>
    </form>
</div></div>

{{-- Promote modal --}}
<div class="modal fade" id="rvPromoteModal" tabindex="-1"><div class="modal-dialog modal-lg">
    <form class="modal-content" method="POST" id="rvPromoteForm">@csrf
        <div class="modal-header"><h6 class="modal-title">{{ t('obs.review.promote_title','بند كتالوج جديد من هذه الملاحظة') }} · <span class="rv-ref"></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-2">
                <div class="col-md-5"><label class="form-label small">{{ t('obs.cat.name','الفئة') }}</label><select name="category_id" class="form-select rv-category" required></select></div>
                <div class="col-md-4"><label class="form-label small">{{ t('obs.catalog.code','الكود') }}</label><input name="code" class="form-control text-uppercase rv-code" required></div>
                <div class="col-md-3"><label class="form-label small">{{ t('obs.kind','النوع') }}</label>
                    <select name="kind" class="form-select rv-kind">@foreach(\App\Models\ObservationCategory::KINDS as $k)<option value="{{ $k }}">{{ t('obs.kind.'.$k, $k) }}</option>@endforeach</select></div>
            </div>
            <div class="row g-2 mt-1">
                <div class="col-md-6"><label class="form-label small">{{ t('obs.catalog.label_ar','البند بالعربي') }}</label><input name="label_ar" class="form-control rv-label-ar" required></div>
                <div class="col-md-6"><label class="form-label small">{{ t('obs.catalog.label_en','البند بالإنجليزي') }}</label><input name="label_en" class="form-control rv-label-en" required></div>
            </div>
            <div class="mt-2"><label class="form-label small">{{ t('obs.catalog.keywords','كلمات مساعدة (مفصولة بفواصل)') }}</label><input name="keywords" class="form-control rv-keywords"></div>
            <div class="row g-2 mt-1">
                <div class="col-md-4"><label class="form-label small">{{ t('obs.risk_level','درجة الخطورة') }}</label>
                    <select name="risk_level_id" class="form-select rv-risk"><option value="">{{ t('obs.from_category','من الفئة') }}</option>@foreach($riskLevels as $r)<option value="{{ $r->id }}">{{ $en ? $r->name_en : $r->name_ar }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label small">{{ t('obs.responsible','الإدارة المسؤولة') }}</label>
                    <select name="responsible_department_id" class="form-select rv-resp"><option value="">{{ t('obs.from_category','من الفئة') }}</option>@foreach($allDepartments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label small">{{ t('obs.sla_hours','المهلة (ساعات)') }}</label><input name="sla_hours" type="number" min="1" class="form-control" placeholder="{{ t('obs.from_category','من الفئة') }}"></div>
            </div>
            <div class="form-text mt-2">{{ t('obs.review.promote_hint','الحقول الإضافية وقواعد التصعيد تُضاف لاحقاً من شاشة الكتالوج.') }}</div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">{{ t('obs.review.promote_save','إنشاء البند وربط الملاحظة') }}</button></div>
    </form>
</div></div>
@endsection

@push('scripts')
@php
    $catsJson = $categories->map(fn ($g) => $g->map(fn ($c) => ['id' => $c->id, 'name' => ($en ? $c->name_en : $c->name_ar)])->values());
    $itemsJson = $itemsByDepartment->map(fn ($g) => $g->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'label' => ($en ? $i->label_en : $i->label_ar), 'category_id' => $i->category_id])->values());
@endphp
<script>
const CATS = @json($catsJson);
const ITEMS = @json($itemsJson);
const BASE = '{{ url('/observations/review') }}';
function fill(select, rows, value, labelFn) {
    select.innerHTML = rows.map(r => `<option value="${r.id}" ${String(r.id)===String(value)?'selected':''}>${labelFn(r)}</option>`).join('');
}
function openModal(id, o, form, action) {
    const m = document.getElementById(id);
    m.querySelectorAll('.rv-ref').forEach(e => e.textContent = o.reference);
    m.querySelectorAll('.rv-text').forEach(e => e.textContent = o.description);
    document.getElementById(form).action = action;
    new bootstrap.Modal(m).show();
}
document.querySelectorAll('.rv-edit').forEach(b => b.addEventListener('click', () => {
    const o = JSON.parse(b.dataset.o), m = document.getElementById('rvEditModal');
    fill(m.querySelector('.rv-category'), CATS[o.department_id] || [], o.category_id, c => c.name);
    m.querySelector('.rv-risk').value = o.risk_level_id || '';
    m.querySelector('.rv-resp').value = o.responsible_department_id || '';
    const cc = (o.cc_department_ids || []).map(String);
    Array.from(m.querySelector('.rv-cc').options).forEach(x => x.selected = cc.includes(x.value));
    m.querySelector('[name=note]').value = '';
    openModal('rvEditModal', o, 'rvEditForm', BASE + '/' + o.id);
}));
document.querySelectorAll('.rv-merge').forEach(b => b.addEventListener('click', () => {
    const o = JSON.parse(b.dataset.o), m = document.getElementById('rvMergeModal');
    fill(m.querySelector('.rv-item'), ITEMS[o.department_id] || [], o.suggested_item_id, i => i.code + ' · ' + i.label);
    m.querySelectorAll('select[name=risk_level_id], select[name=responsible_department_id]').forEach(s => s.value = '');
    m.querySelector('[name=note]').value = '';
    openModal('rvMergeModal', o, 'rvMergeForm', BASE + '/' + o.id + '/merge');
}));
document.querySelectorAll('.rv-promote').forEach(b => b.addEventListener('click', () => {
    const o = JSON.parse(b.dataset.o), m = document.getElementById('rvPromoteModal');
    fill(m.querySelector('.rv-category'), CATS[o.department_id] || [], o.category_id, c => c.name);
    const slug = (o.title || '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toUpperCase().slice(0, 24);
    m.querySelector('.rv-code').value = o.dept_prefix + '-' + (slug || 'NEW-' + o.id);
    m.querySelector('.rv-kind').value = o.kind || 'issue';
    m.querySelector('.rv-label-ar').value = o.title || '';
    m.querySelector('.rv-label-en').value = '';
    m.querySelector('.rv-keywords').value = (o.title || '').split(/\s+/).filter(w => w.length > 2).slice(0, 8).join(', ');
    m.querySelector('.rv-risk').value = o.risk_level_id || '';
    m.querySelector('.rv-resp').value = o.responsible_department_id || '';
    m.querySelector('[name=sla_hours]').value = '';
    openModal('rvPromoteModal', o, 'rvPromoteForm', BASE + '/' + o.id + '/promote');
}));
</script>
@endpush
