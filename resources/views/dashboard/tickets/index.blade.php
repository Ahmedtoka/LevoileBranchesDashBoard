@extends('layouts.app')
@section('title', 'Tickets')

@section('content')
<h4 class="fw-bold mb-3">Tickets</h4>

@include('partials.filterbar', ['range' => $range, 'searchable' => true, 'searchPlaceholder' => 'Search title / reference…'])

@php
    $rk = ['range' => $range->key, 'from' => $range->customFrom, 'to' => $range->customTo];
    $boxes = [
        ['Total',$stats['total'],'dark', route('tickets.index', $rk)],
        ['Open',$stats['open'],'warning', route('tickets.index', $rk + ['status'=>'open'])],
        ['Waiting approval',$stats['waiting_approval'],'info', route('tickets.index', $rk + ['status'=>'waiting_approval'])],
        ['Closed',$stats['closed'],'success', route('tickets.index', $rk + ['status'=>'closed'])],
    ];
@endphp
<div class="row g-3 mb-3">
    @foreach($boxes as [$l,$v,$c,$link])
        <div class="col-md-3 col-6">
            <a href="{{ $link }}" target="_blank" class="text-decoration-none">
                <div class="card stat-card p-3" style="cursor:pointer"><div class="text-muted small">{{ $l }} <i class="bi bi-box-arrow-up-right" style="font-size:.7rem"></i></div><div class="value text-{{ $c }}">{{ $v }}</div></div>
            </a>
        </div>
    @endforeach
</div>

<form class="card p-3 mb-3" method="GET">
    <input type="hidden" name="range" value="{{ $range->key }}">
    @if($range->key==='custom')<input type="hidden" name="from" value="{{ $range->customFrom }}"><input type="hidden" name="to" value="{{ $range->customTo }}">@endif
    @if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small mb-1">Department</label>
            <select name="department" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach($departments as $d)<option value="{{ $d->slug }}" @selected(request('department')===$d->slug)>{{ $d->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach(\App\Models\Ticket::STATUSES as $s)<option value="{{ $s }}" @selected(request('status')===$s)>{{ ucwords(str_replace('_',' ',$s)) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Priority</label>
            <select name="priority" class="form-select form-select-sm">
                <option value="">All</option>
                @foreach(\App\Models\Ticket::PRIORITIES as $p)<option value="{{ $p }}" @selected(request('priority')===$p)>{{ ucfirst($p) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-sm btn-primary">Apply</button>
            <a href="{{ route('tickets.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
        </div>
    </div>
</form>

<div class="d-flex mb-2">
    <input id="ticketsSearch" class="form-control form-control-sm" style="max-width:280px" placeholder="Filter rows on this page…">
</div>
<div class="card p-0">
    <table class="table table-hover align-middle mb-0 js-table" data-search="ticketsSearch">
        <thead><tr><th>Ref</th><th>Title</th><th>Branch</th><th>Department</th><th>Assignee</th><th>Priority</th><th>Status</th><th data-nosort></th></tr></thead>
        <tbody>
        @forelse($tickets as $t)
            <tr class="{{ $t->isOverdue() ? 'table-danger' : '' }}">
                <td class="fw-semibold">{{ $t->reference }}</td>
                <td>{{ Str::limit($t->title, 45) }}</td>
                <td>{{ optional($t->branch)->branch_name }}</td>
                <td>{{ optional($t->department)->name }}</td>
                <td>{{ optional($t->assignee)->name ?? '—' }}</td>
                <td>@include('partials.priority-badge', ['priority' => $t->priority])</td>
                <td>@include('partials.status-badge', ['status' => $t->status])</td>
                <td class="text-end"><a href="{{ route('tickets.show', $t) }}" class="btn btn-sm btn-outline-secondary">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-muted small p-3">No tickets in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{ $tickets->links() }}</div>
@endsection
