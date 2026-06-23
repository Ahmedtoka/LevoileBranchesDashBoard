@extends('layouts.app')
@section('title', 'Schedule Visit')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="fw-bold mb-0">Schedule Visits</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignVisitModal"><i class="bi bi-calendar-plus"></i> Assign visit</button>
</div>

@include('partials.filterbar', ['range' => $range, 'searchable' => true, 'searchPlaceholder' => 'Search branch / user…'])

<div class="row g-3 mb-3">
    @php $boxes = [['Total',$stats['total'],'dark'],['Assigned (not started)',$stats['not_started'],'secondary'],['In progress',$stats['in_progress'],'warning'],['Completed',$stats['completed'],'success']]; @endphp
    @foreach($boxes as [$l,$v,$c])
        <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="text-muted small">{{ $l }}</div><div class="value text-{{ $c }}">{{ $v }}</div></div></div>
    @endforeach
</div>

<div class="card p-0">
    <div class="p-3 pb-0"><h6 class="fw-bold mb-0">Visits in this period</h6></div>
    <table class="table table-hover align-middle mb-0 mt-2">
        <thead><tr><th>Date</th><th>Time</th><th>Template</th><th>Branch</th><th>User</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($visits as $v)
            <tr>
                <td class="small">{{ optional($v->scheduled_date)->format('d M Y') }}</td>
                <td class="small">{{ $v->scheduled_time ?? '—' }}</td>
                <td>{{ optional($v->template)->name }}</td>
                <td>{{ optional($v->branch)->branch_name }}</td>
                <td>{{ optional($v->user)->name }}</td>
                <td>@include('partials.status-badge', ['status' => $v->status])</td>
                <td class="text-end"><a href="{{ route('visits.show', $v) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-muted small p-3">No visits in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<!-- Assign Visit Modal -->
<div class="modal fade" id="assignVisitModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="{{ route('visits.store') }}">
            @csrf
            <div class="modal-header"><h6 class="modal-title">Assign / schedule visit</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label small">Checklist template</label>
                    <select name="visit_template_id" class="form-select" required>
                        <option value="">Select…</option>
                        @foreach($templates as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ ucwords(str_replace('_',' ',$t->type)) }})</option>@endforeach
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Assign to user</label>
                    <select name="user_id" class="form-select" required>
                        <option value="">Select…</option>
                        @foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }} — {{ optional($u->role)->name }}</option>@endforeach
                    </select>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label small">Date</label><input type="date" name="scheduled_date" class="form-control" value="{{ now()->toDateString() }}" required></div>
                    <div class="col-6"><label class="form-label small">Time</label><input type="time" name="scheduled_time" class="form-control"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Branches <span class="text-muted">(one or more)</span></label>
                    <select name="branch_id[]" class="form-select" multiple size="8" required>
                        @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->branch_name }}</option>@endforeach
                    </select>
                    <div class="form-text">Ctrl/Cmd-click to pick multiple.</div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Assign visit</button></div>
        </form>
    </div>
</div>
@endsection
