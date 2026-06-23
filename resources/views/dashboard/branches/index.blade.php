@extends('layouts.app')
@section('title', 'Branches')

@section('content')
<h4 class="fw-bold mb-3">Branches</h4>

@include('partials.filterbar', ['range' => $range, 'searchable' => true, 'searchPlaceholder' => 'Search branch / area / city…'])

<div class="row g-3 mb-3">
    @php $boxes = [['Branches',$summary['branches'],'primary'],['Visits',$summary['visits'],'info'],['Tickets',$summary['tickets'],'dark'],['Open tickets',$summary['open'],'warning']]; @endphp
    @foreach($boxes as [$l,$v,$c])
        <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="text-muted small">{{ $l }}</div><div class="value text-{{ $c }}">{{ $v }}</div></div></div>
    @endforeach
</div>

@if($missingCoords)
    <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>
        {{ $missingCoords }} branches are missing GPS coordinates — check-in runs in simulated mode for those.</div>
@endif

<div class="row g-3">
    @forelse($branches as $b)
        <div class="col-md-6 col-lg-4">
            <div class="card p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <h6 class="fw-bold mb-0">{{ $b->branch_name }}</h6>
                    @if($b->hasCoordinates())
                        <i class="bi bi-geo-alt-fill text-success" title="Has coordinates"></i>
                    @else
                        <i class="bi bi-geo-alt text-warning" title="Missing coordinates"></i>
                    @endif
                </div>
                <div class="small text-muted">{{ $b->area }} · {{ $b->city }}</div>
                <div class="small text-muted mb-2">{{ $b->mobile }}</div>
                <div class="d-flex gap-3 mb-2">
                    <div><div class="fw-bold">{{ $b->visits_in_range }}</div><div class="text-muted" style="font-size:.72rem">Visits</div></div>
                    <div><div class="fw-bold">{{ $b->tickets_in_range }}</div><div class="text-muted" style="font-size:.72rem">Tickets</div></div>
                    <div><div class="fw-bold text-warning">{{ $b->open_tickets }}</div><div class="text-muted" style="font-size:.72rem">Open</div></div>
                </div>
                <a href="{{ route('branches.show', $b) }}" class="btn btn-sm btn-outline-primary mt-auto">View branch</a>
            </div>
        </div>
    @empty
        <div class="col-12"><div class="card p-4 text-center text-muted">No branches match your search.</div></div>
    @endforelse
</div>
@endsection
