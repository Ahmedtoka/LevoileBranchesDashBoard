@extends('layouts.app')
@section('title', 'Build · '.$template->name)

@php
    $priorities = \App\Models\Ticket::PRIORITIES;
    $fieldTypes = ['note'=>'Note','number'=>'Number','percentage'=>'Percentage','photo'=>'Photo','video'=>'Video'];
@endphp

@section('content')
<a href="{{ route('templates.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left"></i> Checklist Builder</a>
<div class="d-flex justify-content-between align-items-center mt-1 mb-3 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">{{ $template->name }} <span class="badge text-bg-light">{{ ucwords(str_replace('_',' ',$template->type)) }}</span></h4>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#settingsModal"><i class="bi bi-gear"></i> Template settings</button>
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#sectionModal" onclick="prepSection('{{ route('sections.store', $template) }}','POST','','','')"><i class="bi bi-plus-lg"></i> Add section</button>
    </div>
</div>

@forelse($template->sections as $section)
    <div class="card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h6 class="fw-bold mb-0">{{ $section->title }}
                    @if($section->title_ar)<span class="text-muted small" dir="rtl">{{ $section->title_ar }}</span>@endif
                </h6>
                @if($template->scored)<span class="small text-muted">Weight: {{ $section->weight ?? 0 }}</span>@endif
            </div>
            <div class="btn-group">
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#sectionModal"
                    onclick="prepSection('{{ route('sections.update', $section) }}','PUT',@js($section->title),@js($section->title_ar),'{{ $section->weight }}')">Edit</button>
                <form method="POST" action="{{ route('sections.destroy', $section) }}" onsubmit="return confirm('Delete section and its questions?')">
                    @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Del</button>
                </form>
            </div>
        </div>

        <table class="table table-sm align-middle mt-2 mb-2">
            <thead><tr><th style="width:38%">Question</th><th>Type / Answers</th><th>Depts</th><th>Priority</th>@if($template->scored)<th>Score</th>@endif<th></th></tr></thead>
            <tbody>
            @forelse($section->questions as $q)
                <tr>
                    <td>{{ $q->question_text }}<div class="small text-muted" dir="rtl">{{ $q->question_text_ar }}</div></td>
                    <td class="small">
                        @if(($q->input_type ?? 'boolean') === 'options')
                            <span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle">Options</span>
                            @foreach($q->options ?? [] as $opt)
                                <span class="badge text-bg-light">{{ $opt['label'] ?? '' }}@if(!empty($opt['creates_ticket'])) 🎫 @endif</span>
                            @endforeach
                        @else
                            <span class="badge text-bg-success-subtle text-success-emphasis border border-success-subtle">Pass/Fail</span>
                            @foreach($q->fail_config ?? [] as $f)<span class="badge text-bg-danger-subtle text-danger-emphasis border">{{ ucfirst($f['type']) }}{{ ($f['required']??false)?'*':'' }}</span>@endforeach
                        @endif
                    </td>
                    <td class="small">
                        @php $qDeptIds = $q->departmentIds(); @endphp
                        @forelse($departments->whereIn('id', $qDeptIds) as $dep)<span class="badge text-bg-light">{{ $dep->name }}</span>@empty — @endforelse
                    </td>
                    <td>@include('partials.priority-badge', ['priority' => $q->default_priority])</td>
                    @if($template->scored)<td class="small">{{ $q->max_score ?? '—' }}</td>@endif
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-link p-0 me-2 edit-q" data-q='@json($q)' data-action="{{ route('questions.update', $q) }}">Edit</button>
                        <form method="POST" action="{{ route('questions.destroy', $q) }}" class="d-inline" onsubmit="return confirm('Delete question?')">
                            @csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger p-0">Del</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted small">No questions yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#questionModal"
            onclick="prepQuestionAdd('{{ route('questions.store', $section) }}')"><i class="bi bi-plus-lg"></i> Add question</button>
    </div>
@empty
    <div class="card p-4 text-center text-muted">No sections yet. Use “Add section”.</div>
@endforelse

{{-- Template settings modal --}}
<div class="modal fade" id="settingsModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="{{ route('templates.update', $template) }}">
            @csrf @method('PUT')
            <div class="modal-header"><h6 class="modal-title">Template settings</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label small">Name</label><input name="name" class="form-control" value="{{ $template->name }}" required></div>
                <div class="mb-2"><label class="form-label small">Type</label>
                    <select name="type" class="form-select">
                        @foreach($types as $type)<option value="{{ $type->slug }}" @selected($template->type===$type->slug)>{{ $type->name }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-2"><label class="form-label small">Description</label><textarea name="description" class="form-control" rows="2">{{ $template->description }}</textarea></div>
                <div class="form-check"><input type="checkbox" name="scored" value="1" class="form-check-input" id="s1" @checked($template->scored)><label class="form-check-label small" for="s1">Scored</label></div>
                <div class="form-check"><input type="checkbox" name="active" value="1" class="form-check-input" id="a1" @checked($template->active)><label class="form-check-label small" for="a1">Active</label></div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Save settings</button></div>
        </form>
    </div>
</div>

{{-- Section modal --}}
<div class="modal fade" id="sectionModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" id="sectionForm">
            @csrf
            <input type="hidden" name="_method" id="sectionMethod" value="POST">
            <div class="modal-header"><h6 class="modal-title">Section</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label small">Title (EN)</label><input name="title" id="sTitle" class="form-control" required></div>
                <div class="mb-2"><label class="form-label small">Title (AR)</label><input name="title_ar" id="sTitleAr" class="form-control" dir="rtl"></div>
                @if($template->scored)<div class="mb-2"><label class="form-label small">Weight</label><input name="weight" id="sWeight" type="number" min="0" class="form-control"></div>@endif
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Save section</button></div>
        </form>
    </div>
</div>

{{-- Question modal --}}
<div class="modal fade" id="questionModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form class="modal-content" method="POST" id="questionForm" oninput="renderPreview()">
            @csrf
            <input type="hidden" name="_method" id="qMethod" value="POST">
            <div class="modal-header"><h6 class="modal-title">Question</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="row g-2">
                            <div class="col-12"><label class="form-label small">Question (EN)</label><input name="question_text" id="qText" class="form-control" required></div>
                            <div class="col-12"><label class="form-label small">Question (AR)</label><input name="question_text_ar" id="qTextAr" class="form-control" dir="rtl"></div>
                            <div class="col-12">
                                <label class="form-label small">Question type</label>
                                <div>
                                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="input_type" value="boolean" id="itBool" checked onchange="onInputType()"><label class="form-check-label small" for="itBool">Pass / Fail</label></div>
                                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="input_type" value="options" id="itOpt" onchange="onInputType()"><label class="form-check-label small" for="itOpt">Options (buttons / dropdown)</label></div>
                                </div>
                            </div>
                        </div>

                        {{-- BOOLEAN MODE --}}
                        <div id="booleanBlock">
                            <div class="row g-2 mt-1">
                                <div class="col-md-6">
                                    <div class="border rounded p-2 h-100">
                                        <div class="fw-semibold small text-success mb-1"><i class="bi bi-check-circle"></i> When PASS, show:</div>
                                        <div id="passFields"></div>
                                        <button type="button" class="btn btn-sm btn-outline-success" onclick="addField('passFields','pass_config',nextIdx('pass'),'note',false)"><i class="bi bi-plus-lg"></i> Add field</button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border rounded p-2 h-100">
                                        <div class="fw-semibold small text-danger mb-1"><i class="bi bi-x-circle"></i> When FAIL, show:</div>
                                        <div id="failFields"></div>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="addField('failFields','fail_config',nextIdx('fail'),'note',true)"><i class="bi bi-plus-lg"></i> Add field</button>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-2 mt-1">
                                <div class="col-md-12">
                                    <label class="form-label small">Responsible depts on fail <span class="text-muted">(ticket+notif each)</span></label>
                                    <select name="responsible_department_ids[]" id="qDept" class="form-select form-select-sm" multiple size="3" onchange="renderPreview()">
                                        @foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="form-check mt-2"><input type="checkbox" name="auto_create_ticket_on_fail" value="1" class="form-check-input" id="qTicket" checked><label class="form-check-label small" for="qTicket">Auto-create ticket on fail</label></div>
                            <div class="form-check"><input type="checkbox" name="is_people_issue" value="1" class="form-check-input" id="qPeople"><label class="form-check-label small" for="qPeople">People / staff issue</label></div>
                        </div>

                        {{-- OPTIONS MODE --}}
                        <div id="optionsBlock" style="display:none">
                            <div class="row g-2 mt-1">
                                <div class="col-md-5">
                                    <label class="form-label small">Display style</label>
                                    <select name="options_style" id="qOptStyle" class="form-select form-select-sm" onchange="renderPreview()">
                                        <option value="buttons">Buttons</option>
                                        <option value="dropdown">Dropdown menu</option>
                                    </select>
                                </div>
                            </div>
                            <div id="optionsList" class="mt-2"></div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addOption()"><i class="bi bi-plus-lg"></i> Add option</button>
                        </div>

                        <div class="row g-2 mt-2">
                            <div class="col-md-4"><label class="form-label small">Priority</label>
                                <select name="default_priority" id="qPriority" class="form-select form-select-sm">@foreach($priorities as $p)<option value="{{ $p }}">{{ ucfirst($p) }}</option>@endforeach</select>
                            </div>
                            <div class="col-md-4"><label class="form-label small">SLA (hrs)</label><input name="sla_hours" id="qSla" type="number" min="1" class="form-control form-control-sm"></div>
                            <div class="col-md-4"><label class="form-label small">Score</label><input name="max_score" id="qScore" type="number" min="0" class="form-control form-control-sm"></div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <label class="form-label small text-muted">Live preview</label>
                        <div style="background:#111827;border-radius:24px;padding:10px;">
                            <div style="background:#F4F5F7;border-radius:16px;min-height:380px;padding:14px;" id="qPreview"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Save question</button></div>
        </form>
    </div>
</div>

@php
    $deptOptions = $departments->map(fn($d) => ['id'=>$d->id,'name'=>$d->name])->values();
@endphp
<script id="deptData" type="application/json">@json($deptOptions)</script>
@endsection

@push('scripts')
<script>
const FIELD_LABELS = {note:'Note',number:'Number',percentage:'Percentage',photo:'Photo',video:'Video'};
const PRIORITIES = @json($priorities);
const DEPTS = JSON.parse(document.getElementById('deptData').textContent || '[]');
let counters = {};
function nextIdx(k){ counters[k] = (counters[k]||0)+1; return counters[k]; }

function prepSection(action, method, title, titleAr, weight) {
    const f = document.getElementById('sectionForm');
    f.action = action; document.getElementById('sectionMethod').value = method;
    document.getElementById('sTitle').value = title || '';
    document.getElementById('sTitleAr').value = titleAr || '';
    const w = document.getElementById('sWeight'); if (w) w.value = weight || '';
}

function esc(s){ return (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }

// Generic field-row: container + name prefix (e.g. pass_config[1] or options[0][fields][2])
function addField(containerId, base, idx, type, required) {
    const wrap = document.getElementById(containerId);
    const prefix = base.includes('[') ? `${base}[fields][${idx}]` : `${base}[${idx}]`;
    const row = document.createElement('div');
    row.className = 'input-group input-group-sm mb-1';
    const opts = Object.entries(FIELD_LABELS).map(([k,v])=>`<option value="${k}" ${k===type?'selected':''}>${v}</option>`).join('');
    row.innerHTML =
        `<select name="${prefix}[type]" class="form-select" onchange="renderPreview()">${opts}</select>` +
        `<select name="${prefix}[required]" class="form-select" onchange="renderPreview()" style="max-width:110px"><option value="0" ${!required?'selected':''}>Optional</option><option value="1" ${required?'selected':''}>Required</option></select>` +
        `<button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove();renderPreview()">&times;</button>`;
    wrap.appendChild(row);
    renderPreview();
}
function readFields(containerId) {
    return Array.from(document.querySelectorAll(`#${containerId} .input-group`)).map(r => {
        const s = r.querySelectorAll('select');
        return {type:s[0].value, required:s[1].value==='1'};
    });
}

// ---- options editor ----
let optCount = 0;
function addOption(data) {
    const idx = optCount++;
    const wrap = document.getElementById('optionsList');
    const card = document.createElement('div');
    card.className = 'border rounded p-2 mb-2';
    card.dataset.opt = idx;
    const deptOpts = DEPTS.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
    const prioOpts = PRIORITIES.map(p => `<option value="${p}">${p}</option>`).join('');
    card.innerHTML =
        `<div class="d-flex gap-2 mb-1">
            <input name="options[${idx}][label]" class="form-control form-control-sm" placeholder="Option label (EN)" oninput="renderPreview()">
            <input name="options[${idx}][label_ar]" class="form-control form-control-sm" placeholder="بالعربي" dir="rtl" oninput="renderPreview()">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('[data-opt]').remove();renderPreview()">&times;</button>
        </div>
        <div class="d-flex gap-2 mb-1 align-items-center">
            <select name="options[${idx}][creates_ticket]" class="form-select form-select-sm" style="max-width:150px" onchange="renderPreview()"><option value="0">No ticket</option><option value="1">Creates ticket</option></select>
            <select name="options[${idx}][priority]" class="form-select form-select-sm" style="max-width:120px">${prioOpts}</select>
            <select name="options[${idx}][department_ids][]" class="form-select form-select-sm" multiple size="1" title="Departments">${deptOpts}</select>
        </div>
        <div class="small text-muted">Fields for this option:</div>
        <div id="opt${idx}Fields"></div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addField('opt${idx}Fields','options[${idx}]',nextIdx('opt${idx}'),'note',false)"><i class="bi bi-plus-lg"></i> Add field</button>`;
    wrap.appendChild(card);

    if (data) {
        card.querySelector(`[name="options[${idx}][label]"]`).value = data.label || '';
        card.querySelector(`[name="options[${idx}][label_ar]"]`).value = data.label_ar || '';
        card.querySelector(`[name="options[${idx}][creates_ticket]"]`).value = data.creates_ticket ? '1' : '0';
        card.querySelector(`[name="options[${idx}][priority]"]`).value = data.priority || 'medium';
        const dsel = card.querySelector(`[name="options[${idx}][department_ids][]"]`);
        const ids = (data.department_ids||[]).map(String);
        Array.from(dsel.options).forEach(o => o.selected = ids.includes(o.value));
        (data.fields||[]).forEach(f => addField(`opt${idx}Fields`, `options[${idx}]`, nextIdx('opt'+idx), f.type, !!f.required));
    }
    renderPreview();
}

function onInputType() {
    const opt = document.getElementById('itOpt').checked;
    document.getElementById('optionsBlock').style.display = opt ? 'block' : 'none';
    document.getElementById('booleanBlock').style.display = opt ? 'none' : 'block';
    if (opt && document.querySelectorAll('#optionsList [data-opt]').length === 0) { addOption(); addOption(); }
    renderPreview();
}

// ---- live preview ----
function fieldWidget(f) {
    const L = FIELD_LABELS[f.type] + (f.required?' <span style="color:#dc2626">*</span>':'');
    const box = (inner)=>`<div style="margin-top:6px"><div style="font-size:11px;color:#64748b">${L}</div>${inner}</div>`;
    if (f.type==='note') return box('<div style="border:1px solid #cbd5e1;border-radius:8px;padding:8px;color:#94a3b8">note…</div>');
    if (f.type==='number') return box('<div style="border:1px solid #cbd5e1;border-radius:8px;padding:8px;color:#94a3b8">0</div>');
    if (f.type==='percentage') return box('<input type="range" style="width:100%">');
    if (f.type==='photo') return box('<div style="border:1px dashed #94a3b8;border-radius:8px;padding:10px;text-align:center;color:#64748b">📷 Camera / Gallery</div>');
    if (f.type==='video') return box('<div style="border:1px dashed #94a3b8;border-radius:8px;padding:10px;text-align:center;color:#64748b">🎥 Record / Upload</div>');
    return '';
}
function renderPreview() {
    const text = document.getElementById('qText').value || 'Question text…';
    const ar = document.getElementById('qTextAr').value;
    const isOpt = document.getElementById('itOpt').checked;
    let body = '';
    if (isOpt) {
        const style = document.getElementById('qOptStyle').value;
        const cards = Array.from(document.querySelectorAll('#optionsList [data-opt]'));
        const opts = cards.map(c => {
            const idx = c.dataset.opt;
            const label = c.querySelector(`[name="options[${idx}][label]"]`).value || 'Option';
            const ticket = c.querySelector(`[name="options[${idx}][creates_ticket]"]`).value === '1';
            const fields = readFields(`opt${idx}Fields`);
            return {label, ticket, fields};
        });
        if (style === 'dropdown') {
            body = `<select style="width:100%;margin-top:10px;padding:8px;border-radius:8px;border:1px solid #cbd5e1">${opts.map(o=>`<option>${esc(o.label)}</option>`).join('')}</select>`;
        } else {
            body = `<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px">${opts.map(o=>`<div style="padding:8px 12px;border:1px solid #4F46E5;border-radius:8px;color:#4F46E5">${esc(o.label)}${o.ticket?' 🎫':''}</div>`).join('')}</div>`;
        }
        body += opts.map(o => o.fields.length ? `<div style="margin-top:8px;border-top:1px dashed #e2e8f0;padding-top:4px"><div style="font-size:11px;color:#334155;font-weight:600">If "${esc(o.label)}":</div>${o.fields.map(fieldWidget).join('')}</div>` : '').join('');
    } else {
        const depts = Array.from(document.getElementById('qDept').selectedOptions).map(o=>o.text);
        const pass = readFields('passFields'), fail = readFields('failFields');
        body = `<div style="display:flex;gap:8px;margin-top:10px">
            <div style="flex:1;text-align:center;padding:8px;border-radius:8px;background:#16a34a;color:#fff;font-weight:600">Pass</div>
            <div style="flex:1;text-align:center;padding:8px;border-radius:8px;border:1px solid #dc2626;color:#dc2626;font-weight:600">Fail</div></div>
            ${depts.length?`<div style="font-size:11px;color:#64748b;margin-top:4px">→ ${esc(depts.join(', '))} (${depts.length} ticket${depts.length>1?'s':''} on fail)</div>`:''}
            <div style="margin-top:8px"><div style="font-size:11px;color:#16a34a;font-weight:600">On Pass:</div>${pass.length?pass.map(fieldWidget).join(''):'<div style="color:#94a3b8;font-size:12px">nothing</div>'}</div>
            <div style="margin-top:8px"><div style="font-size:11px;color:#dc2626;font-weight:600">On Fail:</div>${fail.length?fail.map(fieldWidget).join(''):'<div style="color:#94a3b8;font-size:12px">nothing</div>'}</div>`;
    }
    document.getElementById('qPreview').innerHTML =
        `<div style="background:#fff;border-radius:12px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,.08)">
            <div style="font-weight:600">${esc(text)}</div>
            ${ar?`<div dir="rtl" style="color:#64748b;font-size:13px">${esc(ar)}</div>`:''}${body}</div>`;
}

function resetQuestionForm() {
    counters = {}; optCount = 0;
    document.getElementById('qText').value=''; document.getElementById('qTextAr').value='';
    document.getElementById('itBool').checked = true;
    document.getElementById('passFields').innerHTML=''; document.getElementById('failFields').innerHTML='';
    document.getElementById('optionsList').innerHTML='';
    Array.from(document.getElementById('qDept').options).forEach(o=>o.selected=false);
    document.getElementById('qOptStyle').value='buttons';
    document.getElementById('qPriority').value='medium';
    document.getElementById('qSla').value=''; document.getElementById('qScore').value='';
    document.getElementById('qTicket').checked=true; document.getElementById('qPeople').checked=false;
}

function prepQuestionAdd(action) {
    const f = document.getElementById('questionForm');
    f.action = action; document.getElementById('qMethod').value='POST';
    resetQuestionForm();
    addField('failFields','fail_config',nextIdx('fail'),'note',true);
    addField('failFields','fail_config',nextIdx('fail'),'photo',true);
    onInputType();
}

const parse = v => { if (typeof v==='string'){ try { return JSON.parse(v);}catch(e){return [];}} return v||[]; };

document.querySelectorAll('.edit-q').forEach(btn => btn.addEventListener('click', () => {
    const q = JSON.parse(btn.dataset.q);
    const f = document.getElementById('questionForm');
    f.action = btn.dataset.action; document.getElementById('qMethod').value='PUT';
    resetQuestionForm();
    document.getElementById('qText').value=q.question_text||'';
    document.getElementById('qTextAr').value=q.question_text_ar||'';
    document.getElementById('qPriority').value=q.default_priority||'medium';
    document.getElementById('qSla').value=q.sla_hours||'';
    document.getElementById('qScore').value=q.max_score||'';
    const inputType = q.input_type || 'boolean';
    if (inputType === 'options') {
        document.getElementById('itOpt').checked = true;
        document.getElementById('qOptStyle').value = q.options_style || 'buttons';
        parse(q.options).forEach(o => addOption(o));
    } else {
        document.getElementById('itBool').checked = true;
        document.getElementById('qTicket').checked=!!q.auto_create_ticket_on_fail;
        document.getElementById('qPeople').checked=!!q.is_people_issue;
        parse(q.pass_config).forEach(x => addField('passFields','pass_config',nextIdx('pass'),x.type,!!x.required));
        parse(q.fail_config).forEach(x => addField('failFields','fail_config',nextIdx('fail'),x.type,!!x.required));
        let dIds = parse(q.responsible_department_ids);
        if (!dIds.length && q.responsible_department_id) dIds=[q.responsible_department_id];
        dIds = dIds.map(String);
        Array.from(document.getElementById('qDept').options).forEach(o=>o.selected=dIds.includes(o.value));
    }
    onInputType();
    new bootstrap.Modal(document.getElementById('questionModal')).show();
}));
</script>
@endpush
