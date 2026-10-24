@extends('layouts.app')
@section('title', t('obs.areas.title','المناطق'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">{{ t('obs.areas.title','المناطق') }} <span class="text-muted fs-6">({{ $areas->count() }})</span></h4>
    <button class="btn btn-primary" onclick="prepArea()"><i class="bi bi-plus-lg"></i> {{ t('obs.areas.new','منطقة جديدة') }}</button>
</div>

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

@if($unassigned > 0)
    <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>{{ $unassigned }} {{ t('obs.areas.unassigned','فرع بدون منطقة. افتح منطقة واختر "الفروع" لإضافتهم.') }}</div>
@endif

<div class="card p-0">
    <table class="table table-hover align-middle mb-0 js-table">
        <thead><tr>
            <th>#</th>
            <th>{{ t('common.name','الاسم') }}</th>
            <th>{{ t('obs.areas.name_ar','الاسم بالعربي') }}</th>
            <th>{{ t('obs.areas.manager','مدير المنطقة') }}</th>
            <th class="text-center">{{ t('obs.areas.branches','الفروع') }}</th>
            <th class="text-center">{{ t('common.active','نشط') }}</th>
            <th data-nosort></th>
        </tr></thead>
        <tbody>
        @forelse($areas as $a)
            @php $rowJson = $a->only(['id', 'name', 'name_ar', 'manager_id', 'sort_order', 'active']); @endphp
            <tr class="{{ $a->active ? '' : 'opacity-50' }}">
                <td class="text-muted small">{{ $a->sort_order }}</td>
                <td class="fw-semibold">{{ $a->name }} <code class="small text-muted">· {{ $a->slug }}</code></td>
                <td>{{ $a->name_ar ?? '—' }}</td>
                <td>{{ optional($a->manager)->name ?? '—' }}</td>
                <td class="text-center"><span class="badge text-bg-light">{{ $a->branches_count }}</span></td>
                <td class="text-center">{!! $a->active ? '<span class="badge text-bg-success">'.t('common.active','نشط').'</span>' : '<span class="badge text-bg-secondary">'.t('dept.inactive','موقوفة').'</span>' !!}</td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-primary branches-area" data-a='@json($rowJson)'><i class="bi bi-geo-alt"></i> {{ t('obs.areas.branches','الفروع') }}</button>
                    <button class="btn btn-sm btn-outline-secondary edit-area" data-a='@json($rowJson)'>{{ t('common.edit','تعديل') }}</button>
                    <form method="POST" action="{{ route('obs.areas.destroy', $a) }}" class="d-inline" onsubmit="return confirm('{{ t('obs.areas.confirm_delete','حذف المنطقة؟') }}')">
                        @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" {{ $a->branches_count ? 'disabled title="'.t('obs.areas.has_branches','انقل الفروع أولاً').'"' : '' }}><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted p-4">{{ t('obs.areas.empty','لا توجد مناطق. أضف واحدة.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Area modal (add / edit) --}}
<div class="modal fade" id="areaModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" id="areaForm">
            @csrf
            <input type="hidden" name="_method" id="areaMethod" value="POST">
            <div class="modal-header"><h6 class="modal-title" id="areaTitle">{{ t('obs.areas.new','منطقة جديدة') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small">{{ t('common.name','الاسم') }} (EN)</label><input name="name" id="areaName" class="form-control" required></div>
                    <div class="col-6"><label class="form-label small">{{ t('obs.areas.name_ar','الاسم بالعربي') }}</label><input name="name_ar" id="areaNameAr" class="form-control"></div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-8">
                        <label class="form-label small">{{ t('obs.areas.manager','مدير المنطقة') }}</label>
                        <select name="manager_id" id="areaManager" class="form-select">
                            <option value="">—</option>
                            @foreach($managers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
                        </select>
                        <div class="form-text">{{ t('obs.areas.manager_hint','تغطية المدير (الفروع المغطّاة) تتزامن تلقائياً مع فروع المنطقة.') }}</div>
                    </div>
                    <div class="col-4"><label class="form-label small">{{ t('obs.sort','الترتيب') }}</label><input name="sort_order" id="areaSort" type="number" min="0" class="form-control"></div>
                </div>
                <div class="form-check mt-2"><input type="checkbox" name="active" value="1" class="form-check-input" id="areaActive" checked><label class="form-check-label small" for="areaActive">{{ t('common.active','نشط') }}</label></div>
                <div class="mt-2" id="areaBranchesWrap">
                    <label class="form-label small">{{ t('obs.areas.branches','الفروع') }}</label>
                    <select name="branch_ids[]" id="areaBranchesNew" class="form-select" multiple size="6">
                        @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->branch_name }}{{ $b->area_id ? ' · '.optional($areas->firstWhere('id', $b->area_id))->name : '' }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">{{ t('obs.areas.save','حفظ المنطقة') }}</button></div>
        </form>
    </div>
</div>

{{-- Branch assignment modal --}}
<div class="modal fade" id="branchesModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" id="branchesForm">
            @csrf
            <div class="modal-header"><h6 class="modal-title">{{ t('obs.areas.assign_title','فروع المنطقة') }}: <span id="branchesAreaName"></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="form-text mb-2">{{ t('obs.areas.assign_hint','اختر كل فروع المنطقة. أي فرع يُزال من القائمة يرجع بدون منطقة. Ctrl/Cmd-click للاختيار المتعدد.') }}</div>
                <select name="branch_ids[]" id="branchesSelect" class="form-select" multiple size="14">
                    @foreach($branches->groupBy(fn ($b) => $b->area_id ? optional($areas->firstWhere('id', $b->area_id))->name : null) as $groupName => $group)
                        <optgroup label="{{ $groupName ?: t('obs.areas.no_area','بدون منطقة') }}">
                            @foreach($group as $b)<option value="{{ $b->id }}" data-area="{{ $b->area_id }}">{{ $b->branch_name }}{{ $b->active ? '' : ' ('.t('dept.inactive','موقوفة').')' }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">{{ t('common.save','حفظ') }}</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function prepArea() {
    const f = document.getElementById('areaForm');
    f.action = '{{ route('obs.areas.store') }}';
    document.getElementById('areaMethod').value = 'POST';
    document.getElementById('areaTitle').textContent = @json(t('obs.areas.new','منطقة جديدة'));
    ['areaName','areaNameAr','areaSort'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('areaManager').value = '';
    document.getElementById('areaActive').checked = true;
    document.getElementById('areaBranchesWrap').style.display = 'block';
    Array.from(document.getElementById('areaBranchesNew').options).forEach(o => o.selected = false);
    new bootstrap.Modal(document.getElementById('areaModal')).show();
}
document.querySelectorAll('.edit-area').forEach(btn => btn.addEventListener('click', () => {
    const a = JSON.parse(btn.dataset.a);
    const f = document.getElementById('areaForm');
    f.action = '{{ url('/admin/areas') }}/' + a.id;
    document.getElementById('areaMethod').value = 'PUT';
    document.getElementById('areaTitle').textContent = @json(t('obs.areas.edit','تعديل منطقة'));
    document.getElementById('areaName').value = a.name || '';
    document.getElementById('areaNameAr').value = a.name_ar || '';
    document.getElementById('areaSort').value = a.sort_order ?? '';
    document.getElementById('areaManager').value = a.manager_id || '';
    document.getElementById('areaActive').checked = !!a.active;
    // Branches are managed from the dedicated modal when editing (avoids resetting them by accident).
    document.getElementById('areaBranchesWrap').style.display = 'none';
    Array.from(document.getElementById('areaBranchesNew').options).forEach(o => o.selected = false);
    new bootstrap.Modal(document.getElementById('areaModal')).show();
}));
document.querySelectorAll('.branches-area').forEach(btn => btn.addEventListener('click', () => {
    const a = JSON.parse(btn.dataset.a);
    document.getElementById('branchesForm').action = '{{ url('/admin/areas') }}/' + a.id + '/branches';
    document.getElementById('branchesAreaName').textContent = a.name;
    Array.from(document.getElementById('branchesSelect').options).forEach(o => o.selected = String(o.dataset.area) === String(a.id));
    new bootstrap.Modal(document.getElementById('branchesModal')).show();
}));
</script>
@endpush
