@extends('layouts.app')
@section('title', t('dept.title','الإدارات'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">{{ t('dept.title','الإدارات') }}</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="prepDept()"><i class="bi bi-plus-lg"></i> {{ t('dept.new','إدارة جديدة') }}</button>
</div>

@include('partials.filterbar', ['range' => $range, 'searchable' => true, 'searchPlaceholder' => t('dept.search','بحث عن إدارة…')])

<div class="row g-3 mb-3">
    @php $boxes = [[t('dept.title','الإدارات'),$summary['departments'],'primary'],[t('tk.title','التذاكر'),$summary['total'],'dark'],[t('tk.open','مفتوحة'),$summary['open'],'warning'],[t('tk.closed','مقفولة'),$summary['closed'],'success'],[t('dash.overdue','متأخرة'),$summary['overdue'],'danger']]; @endphp
    @foreach($boxes as [$l,$v,$c])
        <div class="col"><div class="card stat-card p-3"><div class="text-muted small">{{ $l }}</div><div class="value text-{{ $c }}">{{ $v }}</div></div></div>
    @endforeach
</div>

<div class="row g-3">
    @forelse($departments as $d)
        <div class="col-md-6 col-lg-4">
            <div class="card p-3 h-100 {{ $d->active ? '' : 'opacity-50' }}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0">
                        <span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:{{ $d->color ?? '#64748b' }}"></span>
                        {{ $d->name }}
                        @if($d->ticket_prefix)<code class="small text-muted">· {{ $d->ticket_prefix }}</code>@endif
                    </h6>
                    <div class="d-flex gap-1 align-items-center">
                        @if(!$d->active)<span class="badge text-bg-secondary">{{ t('dept.inactive','موقوفة') }}</span>@endif
                        @if($d->overdue > 0)<span class="badge text-bg-danger">{{ $d->overdue }} {{ t('dash.overdue','متأخرة') }}</span>@endif
                    </div>
                </div>
                <div class="small text-muted mb-2"><i class="bi bi-person-badge me-1"></i>{{ t('common.manager','المدير') }}: {{ $d->manager_name ?? t('dept.no_manager','— لا يوجد') }}</div>
                <a href="{{ route('departments.board', $d) }}" class="text-decoration-none">
                    <div class="d-flex gap-3">
                        <div><div class="fw-bold text-dark">{{ $d->total }}</div><div class="text-muted" style="font-size:.72rem">{{ t('visit.tickets','تذاكر') }}</div></div>
                        <div><div class="fw-bold text-warning">{{ $d->open }}</div><div class="text-muted" style="font-size:.72rem">{{ t('tk.open','مفتوحة') }}</div></div>
                        <div><div class="fw-bold text-success">{{ $d->closed }}</div><div class="text-muted" style="font-size:.72rem">{{ t('tk.closed','مقفولة') }}</div></div>
                        <div><div class="fw-bold text-dark">{{ $d->employees_count }}</div><div class="text-muted" style="font-size:.72rem">{{ t('dept.staff','موظفين') }}</div></div>
                    </div>
                </a>
                <div class="d-flex gap-2 mt-2">
                    <a href="{{ route('departments.board', $d) }}" class="btn btn-sm btn-outline-primary flex-fill">{{ t('dept.board','فتح اللوحة') }}</a>
                    <button class="btn btn-sm btn-outline-secondary edit-dept" data-d='@json($d)'>{{ t('common.edit','تعديل') }}</button>
                    <form method="POST" action="{{ route('departments.destroy', $d) }}" onsubmit="return confirm('{{ t('dept.confirm_delete','حذف الإدارة؟') }}')">
                        @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12"><div class="card p-4 text-center text-muted">No departments. Add one.</div></div>
    @endforelse
</div>

<!-- Department modal (add / edit) -->
<div class="modal fade" id="deptModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" id="deptForm">
            @csrf
            <input type="hidden" name="_method" id="deptMethod" value="POST">
            <div class="modal-header"><h6 class="modal-title" id="deptTitle">New department</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label small">Name</label><input name="name" id="deptName" class="form-control" required></div>
                <div class="mb-2"><label class="form-label small">Color</label><input name="color" id="deptColor" type="color" class="form-control form-control-color" value="#64748b"></div>
                <div class="form-check" id="deptActiveWrap"><input type="checkbox" name="active" value="1" class="form-check-input" id="deptActive" checked><label class="form-check-label small" for="deptActive">Active</label></div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Save department</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function prepDept() {
    const f = document.getElementById('deptForm');
    f.action = '{{ route('departments.store') }}';
    document.getElementById('deptMethod').value = 'POST';
    document.getElementById('deptTitle').textContent = 'New department';
    document.getElementById('deptName').value = '';
    document.getElementById('deptColor').value = '#64748b';
    document.getElementById('deptActive').checked = true;
    document.getElementById('deptActiveWrap').style.display = 'none';
}
document.querySelectorAll('.edit-dept').forEach(btn => btn.addEventListener('click', () => {
    const d = JSON.parse(btn.dataset.d);
    const f = document.getElementById('deptForm');
    f.action = '/departments/' + d.id;
    document.getElementById('deptMethod').value = 'PUT';
    document.getElementById('deptTitle').textContent = 'Edit department';
    document.getElementById('deptName').value = d.name || '';
    document.getElementById('deptColor').value = d.color || '#64748b';
    document.getElementById('deptActive').checked = !!d.active;
    document.getElementById('deptActiveWrap').style.display = 'block';
    new bootstrap.Modal(document.getElementById('deptModal')).show();
}));
</script>
@endpush
