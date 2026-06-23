@extends('layouts.app')
@section('title', 'Visits')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="fw-bold mb-0">Visits</h4>
    <a href="{{ route('visits.schedule') }}" class="btn btn-primary"><i class="bi bi-calendar-plus"></i> Schedule</a>
</div>

@include('partials.filterbar', ['range' => $range, 'searchable' => true, 'searchPlaceholder' => 'Search branch…'])

@php
    $rk = ['range' => $range->key, 'from' => $range->customFrom, 'to' => $range->customTo];
    $boxes = [
        ['Total',$stats['total'],'dark', route('visits.index', $rk)],
        ['Open',$stats['open'],'warning', route('visits.index', $rk + ['status'=>'open'])],
        ['Completed',$stats['completed'],'success', route('visits.index', $rk + ['status'=>'completed'])],
        ['Problems',$stats['problems'],'danger', route('visits.index', $rk)],
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

<div class="btn-group btn-group-sm mb-3">
    <a href="{{ request()->fullUrlWithQuery(['status'=>null]) }}" class="btn {{ $filter==='all' ? 'btn-primary' : 'btn-outline-secondary' }}">All</a>
    <a href="{{ request()->fullUrlWithQuery(['status'=>'open']) }}" class="btn {{ $filter==='open' ? 'btn-primary' : 'btn-outline-secondary' }}">Open</a>
    <a href="{{ request()->fullUrlWithQuery(['status'=>'completed']) }}" class="btn {{ $filter==='completed' ? 'btn-primary' : 'btn-outline-secondary' }}">Completed</a>
</div>

<div class="d-flex mb-2">
    <input id="visitsSearch" class="form-control form-control-sm" style="max-width:280px" placeholder="Filter rows on this page…">
</div>
<div class="card p-0">
    <table class="table table-hover align-middle mb-0 js-table" data-search="visitsSearch">
        <thead><tr><th>Date</th><th>Template</th><th>Branch</th><th>User</th><th class="text-center" data-sum>Problems</th><th class="text-center" data-sum>Tickets</th><th>Status</th><th data-nosort></th></tr></thead>
        <tbody>
        @forelse($visits as $v)
            <tr>
                <td class="small">{{ optional($v->scheduled_date)->format('d M Y') }}</td>
                <td>{{ optional($v->template)->name }}</td>
                <td>{{ optional($v->branch)->branch_name }}</td>
                <td>{{ optional($v->user)->name }}</td>
                <td class="text-center">{{ $v->problems_count }}</td>
                <td class="text-center">{{ $v->tickets_count }}</td>
                <td>@include('partials.status-badge', ['status' => $v->status])</td>
                <td class="text-end"><a href="{{ route('visits.show', $v) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-muted small p-3">No visits in this period.</td></tr>
        @endforelse
        </tbody>
        <tfoot class="table-light fw-semibold"><tr><td colspan="4" class="text-end">Totals</td><td class="text-center">0</td><td class="text-center">0</td><td colspan="2"></td></tr></tfoot>
    </table>
</div>
<div class="mt-3">{{ $visits->links() }}</div>
@endsection
