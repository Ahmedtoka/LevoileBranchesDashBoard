@extends('layouts.app')
@section('title', 'Overview')

@section('content')
<h4 class="fw-bold mb-3">Overview</h4>

@include('partials.filterbar', ['range' => $range])

@php
    $rk = ['range' => $range->key, 'from' => $range->customFrom, 'to' => $range->customTo];
    $cards = [
        ['Branches', $stats['branches'], 'geo-alt', 'primary', route('branches.index', $rk)],
        ['Visits', $stats['visits_total'], 'clipboard', 'secondary', route('visits.index', $rk)],
        ['Visits completed', $stats['visits_completed'], 'clipboard-check', 'success', route('visits.index', $rk + ['status' => 'completed'])],
        ['Open visits', $stats['visits_open'], 'hourglass', 'info', route('visits.index', $rk + ['status' => 'open'])],
        ['Tickets', $stats['tickets_total'], 'ticket-detailed', 'dark', route('tickets.index', $rk)],
        ['Open tickets', $stats['tickets_open'], 'exclamation-circle', 'warning', route('tickets.index', $rk + ['status' => 'open'])],
        ['Waiting approval', $stats['waiting_approval'], 'hourglass-split', 'info', route('tickets.index', $rk + ['status' => 'waiting_approval'])],
        ['Closed tickets', $stats['tickets_closed'], 'check2-circle', 'success', route('tickets.index', $rk + ['status' => 'closed'])],
        ['Overdue', $stats['overdue'], 'exclamation-triangle', 'danger', route('tickets.index', $rk)],
    ];
@endphp
<div class="row g-3 mb-4">
    @foreach($cards as [$label, $value, $icon, $color, $link])
        <div class="col-md-3 col-6">
            <a href="{{ $link }}" target="_blank" class="text-decoration-none">
                <div class="card stat-card p-3 h-100" style="cursor:pointer">
                    <div class="text-muted small"><i class="bi bi-{{ $icon }} text-{{ $color }} me-1"></i>{{ $label }} <i class="bi bi-box-arrow-up-right text-muted" style="font-size:.7rem"></i></div>
                    <div class="value text-dark">{{ $value }}</div>
                </div>
            </a>
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">Tickets by department <span class="text-muted small">({{ $range->label }})</span></h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Department</th><th class="text-center">Open</th><th class="text-center">Closed</th><th></th></tr></thead>
                <tbody>
                @foreach($ticketsByDept as $d)
                    <tr>
                        <td>{{ $d->name }}</td>
                        <td class="text-center">{{ $d->open_count }}</td>
                        <td class="text-center">{{ $d->closed_count }}</td>
                        <td class="text-end"><a href="{{ route('departments.board', $d) }}" class="btn btn-sm btn-outline-secondary">Board</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">Repeated problems</h6>
            @forelse($repeated as $row)
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span><span class="badge text-bg-light text-capitalize">{{ $row->category }}</span> {{ optional($row->branch)->branch_name }}</span>
                    <strong>{{ $row->total }}×</strong>
                </div>
            @empty
                <p class="text-muted small mb-0">No repeated problems in this period.</p>
            @endforelse
        </div>
    </div>

    <div class="col-12">
        <div class="card p-3">
            <h6 class="fw-bold mb-3">Recent tickets</h6>
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Ref</th><th>Title</th><th>Branch</th><th>Department</th><th>Priority</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($recentTickets as $t)
                    <tr onclick="window.location='{{ route('tickets.show', $t) }}'" style="cursor:pointer">
                        <td class="fw-semibold">{{ $t->reference }}</td>
                        <td>{{ Str::limit($t->title, 50) }}</td>
                        <td>{{ optional($t->branch)->branch_name }}</td>
                        <td>{{ optional($t->department)->name }}</td>
                        <td>@include('partials.priority-badge', ['priority' => $t->priority])</td>
                        <td>@include('partials.status-badge', ['status' => $t->status])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted small">No tickets in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
