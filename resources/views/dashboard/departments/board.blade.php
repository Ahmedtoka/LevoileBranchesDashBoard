@extends('layouts.app')
@section('title', $department->name.' Board')

@section('content')
<a href="{{ route('departments.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left"></i> Departments</a>
<h4 class="fw-bold mt-1 mb-2">{{ $department->name }} — Board</h4>

@include('partials.filterbar', ['range' => $range, 'searchable' => true, 'searchPlaceholder' => 'Search ticket / reference…'])

{{-- Dashboard stats --}}
<div class="row g-2 mb-3">
    @php $boxes = [['Total',$stats['total'],'dark'],['Open',$stats['open'],'secondary'],['Assigned',$stats['assigned'],'info'],['In progress',$stats['in_progress'],'warning'],['Waiting',$stats['waiting_approval'],'primary'],['Closed',$stats['closed'],'success'],['Overdue',$stats['overdue'],'danger']]; @endphp
    @foreach($boxes as [$l,$v,$c])
        <div class="col"><div class="card stat-card p-2 text-center"><div class="value text-{{ $c }}" style="font-size:1.3rem">{{ $v }}</div><div class="text-muted" style="font-size:.7rem">{{ $l }}</div></div></div>
    @endforeach
</div>

<div class="row g-3">
    {{-- Employees workload --}}
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h6 class="fw-bold">Team workload</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Employee</th><th class="text-center">Open</th><th class="text-center">Closed</th></tr></thead>
                <tbody>
                @forelse($employees as $e)
                    <tr class="{{ $e->assigned == 0 ? 'table-light' : '' }}">
                        <td>{{ $e->name }}@if($e->job_title)<br><span class="text-muted" style="font-size:.72rem">{{ $e->job_title }}</span>@endif @if($e->assigned == 0)<span class="badge text-bg-light text-muted">free</span>@endif</td>
                        <td class="text-center">{{ $e->open }}</td>
                        <td class="text-center">{{ $e->closed }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-muted small">No employees in this department.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card p-3">
            <h6 class="fw-bold">Branches</h6>
            @forelse($branches as $row)
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span>{{ optional($row->branch)->branch_name }}</span>
                    <span><strong>{{ $row->total }}</strong> <span class="text-warning">({{ $row->open }} open)</span></span>
                </div>
            @empty
                <p class="text-muted small mb-0">No tickets / branches in this period.</p>
            @endforelse
        </div>
    </div>

    {{-- Tickets table --}}
    <div class="col-lg-8">
        <div class="card p-0">
            <div class="d-flex justify-content-between align-items-center p-3 pb-2">
                <h6 class="fw-bold mb-0">Tickets</h6>
                <div class="btn-group btn-group-sm">
                    <a href="{{ request()->fullUrlWithQuery(['status'=>null]) }}" class="btn {{ !$statusFilter ? 'btn-primary' : 'btn-outline-secondary' }}">All</a>
                    @foreach(['open','assigned','in_progress','waiting_approval','closed'] as $s)
                        <a href="{{ request()->fullUrlWithQuery(['status'=>$s]) }}" class="btn {{ $statusFilter===$s ? 'btn-primary' : 'btn-outline-secondary' }}">{{ ucwords(str_replace('_',' ',$s)) }}</a>
                    @endforeach
                </div>
            </div>
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Ref</th><th>Title</th><th>Branch</th><th>Assignee</th><th>Opened</th><th>Open for</th><th>Priority</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($tickets as $t)
                    <tr class="{{ $t->isOverdue() ? 'table-danger' : '' }}">
                        <td class="fw-semibold">{{ $t->reference }}</td>
                        <td>{{ Str::limit($t->title, 38) }}</td>
                        <td class="small">{{ optional($t->branch)->branch_name }}</td>
                        <td class="small">{{ optional($t->assignee)->name ?? '—' }}</td>
                        <td class="small">{{ $t->created_at->format('d M, g:i A') }}</td>
                        <td class="small">{{ $t->ageInHours() }}h @if($t->scheduled_at)<br><span class="text-primary">→ {{ $t->scheduled_at->format('d M g:i A') }}</span>@endif</td>
                        <td>@include('partials.priority-badge', ['priority' => $t->priority])</td>
                        <td>@include('partials.status-badge', ['status' => $t->status])</td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-primary py-0" data-bs-toggle="modal" data-bs-target="#assignModal"
                                data-ticket="{{ $t->id }}" data-ref="{{ $t->reference }}">تعيين</button>
                            <a href="{{ route('tickets.show', $t) }}" class="btn btn-sm btn-outline-secondary py-0">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-muted small p-3">No tickets.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" id="assignForm">
            @csrf
            <div class="modal-header"><h6 class="modal-title">تعيين التذكرة <span id="assignRef"></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small">Employee</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">Select…</option>
                        @foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}{{ $e->job_title ? ' · '.$e->job_title : '' }} ({{ $e->open }} open)</option>@endforeach
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Priority</label>
                    <select name="priority" class="form-select">
                        @foreach(\App\Models\Ticket::PRIORITIES as $p)<option value="{{ $p }}" @selected($p==='medium')>{{ ucfirst($p) }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Appointment — when will the employee go?</label>
                    <input type="datetime-local" name="scheduled_at" class="form-control">
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">{{ t('tk.save_assignment','حفظ التعيين') }}</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const modal = document.getElementById('assignModal');
    modal.addEventListener('show.bs.modal', e => {
        const btn = e.relatedTarget;
        document.getElementById('assignRef').textContent = btn.getAttribute('data-ref');
        document.getElementById('assignForm').action = '/tickets/' + btn.getAttribute('data-ticket') + '/assign';
    });
</script>
@endpush
