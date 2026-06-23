@extends('layouts.app')
@section('title', 'Reports')

@section('content')
<h4 class="fw-bold mb-3">Reports</h4>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">Visits per branch</h6>
            <table class="table table-sm mb-0 js-table">
                <thead><tr><th>Branch</th><th class="text-end" data-sum>Visits</th></tr></thead>
                <tbody>
                @foreach($visitsPerBranch as $row)
                    <tr><td>{{ optional($row->branch)->branch_name }}</td><td class="text-end fw-semibold">{{ $row->total }}</td></tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold"><tr><td class="text-end">Total</td><td class="text-end">0</td></tr></tfoot>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">Tickets by department</h6>
            <table class="table table-sm mb-0 js-table">
                <thead><tr><th>Department</th><th class="text-center" data-sum>Open</th><th class="text-center" data-sum>Closed</th></tr></thead>
                <tbody>
                @foreach($byDept as $d)
                    <tr><td>{{ $d->name }}</td><td class="text-center">{{ $d->open }}</td><td class="text-center">{{ $d->closed }}</td></tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold"><tr><td class="text-end">Totals</td><td class="text-center">0</td><td class="text-center">0</td></tr></tfoot>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">Avg resolution time (hours)</h6>
            <table class="table table-sm mb-0"><tbody>
                @forelse($resolution as $row)
                    <tr><td>{{ optional($row->department)->name ?? '—' }}</td><td class="text-end fw-semibold">{{ round($row->avg_hours ?? 0, 1) }}h</td></tr>
                @empty
                    <tr><td class="text-muted small">No closed tickets yet.</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">Repeated problems</h6>
            <table class="table table-sm mb-0"><tbody>
                @forelse($repeated as $row)
                    <tr><td><span class="badge text-bg-light text-capitalize">{{ $row->category }}</span> {{ optional($row->branch)->branch_name }}</td><td class="text-end fw-semibold">{{ $row->total }}×</td></tr>
                @empty
                    <tr><td class="text-muted small">None.</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>

    <div class="col-12">
        <div class="card p-3">
            <h6 class="fw-bold">Employee performance</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Employee</th><th>Department</th><th class="text-center">Assigned</th><th class="text-center">Closed</th><th class="text-center">Pending</th><th class="text-center">Reopened</th><th class="text-center">Avg hrs</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($performance as $u)
                    <tr>
                        <td>{{ $u->name }}</td>
                        <td>{{ optional($u->department)->name }}</td>
                        <td class="text-center">{{ $u->assigned }}</td>
                        <td class="text-center">{{ $u->closed }}</td>
                        <td class="text-center">{{ $u->pending }}</td>
                        <td class="text-center">{{ $u->reopened }}</td>
                        <td class="text-center">{{ $u->avg_hours ?? '—' }}</td>
                        <td><span class="badge {{ $u->performance === 'Excellent' ? 'text-bg-success' : ($u->performance === 'Good' ? 'text-bg-info' : 'text-bg-warning') }}">{{ $u->performance }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-muted small">No assignments yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-12">
        <div class="card p-3">
            <h6 class="fw-bold">Overdue tickets</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Ref</th><th>Title</th><th>Branch</th><th>Department</th><th>Due</th></tr></thead>
                <tbody>
                @forelse($overdue as $t)
                    <tr class="table-danger" onclick="window.location='{{ route('tickets.show', $t) }}'" style="cursor:pointer">
                        <td>{{ $t->reference }}</td><td>{{ Str::limit($t->title, 40) }}</td>
                        <td>{{ optional($t->branch)->branch_name }}</td><td>{{ optional($t->department)->name }}</td>
                        <td>{{ optional($t->due_at)->format('d M H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted small">No overdue tickets.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
