@extends('layouts.app')
@section('title', 'Users')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">Users <span class="text-muted fs-6">({{ $users->count() }})</span></h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="prepUser()"><i class="bi bi-plus-lg"></i> New user</button>
</div>

<div class="card p-0">
    <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Branch</th><th class="text-center">Manager</th><th class="text-center">Active</th><th></th></tr></thead>
        <tbody>
        @foreach($users as $u)
            <tr>
                <td class="fw-semibold">{{ $u->name }}</td>
                <td class="small">{{ $u->email }}</td>
                <td>{{ optional($u->role)->name ?? '—' }}</td>
                <td>{{ optional($u->department)->name ?? '—' }}</td>
                <td class="small">{{ optional($u->branch)->branch_name ?? '—' }}</td>
                <td class="text-center">{!! $u->is_department_manager ? '<i class="bi bi-check-circle-fill text-success"></i>' : '' !!}</td>
                <td class="text-center">{!! $u->active ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Off</span>' !!}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-link p-0 me-2 edit-user" data-u='@json($u)' data-branches='@json($u->branches->pluck("id"))'>Edit</button>
                    <form method="POST" action="{{ route('users.destroy', $u) }}" class="d-inline" onsubmit="return confirm('Delete this user?')">
                        @csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger p-0">Del</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" id="userForm">
            @csrf
            <input type="hidden" name="_method" id="uMethod" value="POST">
            <div class="modal-header"><h6 class="modal-title" id="uTitle">New user</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label small">Name</label><input name="name" id="uName" class="form-control" required></div>
                <div class="mb-2"><label class="form-label small">Email</label><input name="email" id="uEmail" type="email" class="form-control" required></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small">Phone</label><input name="phone" id="uPhone" class="form-control"></div>
                    <div class="col-6"><label class="form-label small">Password <span class="text-muted" id="uPwHint"></span></label><input name="password" id="uPassword" type="text" class="form-control"></div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-6">
                        <label class="form-label small">Role</label>
                        <select name="role_id" id="uRole" class="form-select">
                            <option value="">—</option>
                            @foreach($roles as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Department</label>
                        <select name="department_id" id="uDept" class="form-select">
                            <option value="">—</option>
                            @foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="mt-2">
                    <label class="form-label small">Branch <span class="text-muted">(Store Manager's single branch)</span></label>
                    <select name="branch_id" id="uBranch" class="form-select">
                        <option value="">—</option>
                        @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->branch_name }}</option>@endforeach
                    </select>
                </div>
                <div class="mt-2">
                    <label class="form-label small">Covered branches <span class="text-muted">(Area Manager region / Technician branches — multi)</span></label>
                    <select name="branch_ids[]" id="uBranches" class="form-select" multiple size="6">
                        @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->branch_name }}</option>@endforeach
                    </select>
                    <div class="form-text">للفني: المشاكل في الفروع دي بتتسند له تلقائياً. Ctrl/Cmd-click لاختيار أكتر من فرع.</div>
                </div>
                <div class="form-check mt-2"><input type="checkbox" name="is_department_manager" value="1" class="form-check-input" id="uMgr"><label class="form-check-label small" for="uMgr">Department manager</label></div>
                <div class="form-check"><input type="checkbox" name="active" value="1" class="form-check-input" id="uActive" checked><label class="form-check-label small" for="uActive">Active</label></div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Save user</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function prepUser() {
    const f = document.getElementById('userForm');
    f.action = '{{ route('users.store') }}';
    document.getElementById('uMethod').value = 'POST';
    document.getElementById('uTitle').textContent = 'New user';
    ['uName','uEmail','uPhone','uPassword'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('uRole').value = '';
    document.getElementById('uDept').value = '';
    document.getElementById('uBranch').value = '';
    Array.from(document.getElementById('uBranches').options).forEach(o => o.selected = false);
    document.getElementById('uMgr').checked = false;
    document.getElementById('uActive').checked = true;
    document.getElementById('uPwHint').textContent = '(required)';
}
document.querySelectorAll('.edit-user').forEach(btn => btn.addEventListener('click', () => {
    const u = JSON.parse(btn.dataset.u);
    const f = document.getElementById('userForm');
    f.action = '/users/' + u.id;
    document.getElementById('uMethod').value = 'PUT';
    document.getElementById('uTitle').textContent = 'Edit user';
    document.getElementById('uName').value = u.name || '';
    document.getElementById('uEmail').value = u.email || '';
    document.getElementById('uPhone').value = u.phone || '';
    document.getElementById('uPassword').value = '';
    document.getElementById('uPwHint').textContent = '(leave blank to keep)';
    document.getElementById('uRole').value = u.role_id || '';
    document.getElementById('uDept').value = u.department_id || '';
    document.getElementById('uBranch').value = u.branch_id || '';
    const covered = (JSON.parse(btn.dataset.branches || '[]')).map(String);
    Array.from(document.getElementById('uBranches').options).forEach(o => o.selected = covered.includes(o.value));
    document.getElementById('uMgr').checked = !!u.is_department_manager;
    document.getElementById('uActive').checked = !!u.active;
    new bootstrap.Modal(document.getElementById('userModal')).show();
}));
</script>
@endpush
