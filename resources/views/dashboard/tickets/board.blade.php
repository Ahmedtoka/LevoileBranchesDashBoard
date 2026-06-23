@extends('layouts.app')
@section('title', $department->name.' Board')

@section('content')
<h4 class="fw-bold mb-1">{{ $department->name }} Board</h4>
<p class="text-muted small">Each department sees only its own tickets. Assign work to your team and track progress across columns.</p>

<div class="d-flex gap-3 overflow-auto pb-3">
    @foreach($columns as $status => $tickets)
        <div style="min-width: 270px; flex: 0 0 270px;">
            <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                <span class="fw-semibold small text-uppercase text-muted">{{ str_replace('_',' ',$status) }}</span>
                <span class="badge text-bg-light">{{ $tickets->count() }}</span>
            </div>
            @foreach($tickets as $t)
                <div class="card p-2 mb-2">
                    <div class="d-flex justify-content-between">
                        <a href="{{ route('tickets.show', $t) }}" class="small fw-semibold text-decoration-none">{{ $t->reference }}</a>
                        @include('partials.priority-badge', ['priority' => $t->priority])
                    </div>
                    <div class="small mt-1">{{ Str::limit($t->title, 60) }}</div>
                    <div class="small text-muted mt-1">{{ optional($t->branch)->branch_name }}</div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <span class="small text-muted">{{ optional($t->assignee)->name ?? 'Unassigned' }}</span>
                        @if(in_array($status, ['open']))
                            <button class="btn btn-sm btn-outline-primary py-0 px-2" data-bs-toggle="modal" data-bs-target="#assignModal"
                                data-ticket="{{ $t->id }}" data-ref="{{ $t->reference }}">Assign</button>
                        @endif
                    </div>
                </div>
            @endforeach
            @if($tickets->isEmpty())
                <div class="text-muted small px-1">—</div>
            @endif
        </div>
    @endforeach
</div>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" id="assignForm">
            @csrf
            <div class="modal-header">
                <h6 class="modal-title">Assign ticket <span id="assignRef"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small">Employee</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">Select…</option>
                        @foreach($employees as $e)
                            <option value="{{ $e->id }}">{{ $e->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Priority</label>
                    <select name="priority" class="form-select">
                        @foreach(\App\Models\Ticket::PRIORITIES as $p)
                            <option value="{{ $p }}" @selected($p==='medium')>{{ ucfirst($p) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary">Save assignment</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const modal = document.getElementById('assignModal');
    modal.addEventListener('show.bs.modal', e => {
        const btn = e.relatedTarget;
        const id = btn.getAttribute('data-ticket');
        document.getElementById('assignRef').textContent = btn.getAttribute('data-ref');
        document.getElementById('assignForm').action = '/tickets/' + id + '/assign';
    });
</script>
@endpush
