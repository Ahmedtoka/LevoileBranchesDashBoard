@extends('layouts.app')
@section('title', $branch->branch_name)

@section('content')
<a href="{{ route('branches.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left"></i> Branches</a>
<h4 class="fw-bold mt-1 mb-3">{{ $branch->branch_name }}</h4>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card p-3 mb-3">
            <h6 class="fw-bold">Details</h6>
            <p class="small mb-1"><strong>Area:</strong> {{ $branch->area }} — {{ $branch->city }}</p>
            <p class="small mb-1"><strong>Address:</strong> {{ $branch->address }}</p>
            <p class="small mb-1"><strong>Mobile:</strong> {{ $branch->mobile }}</p>
            <p class="small mb-1"><strong>Check-in radius:</strong> {{ $branch->checkin_radius }} m</p>
            @if($branch->google_maps_url)
                <a href="{{ $branch->google_maps_url }}" target="_blank" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-geo-alt"></i> Open in Google Maps</a>
            @endif
        </div>

        <div class="card p-3">
            <h6 class="fw-bold">Map preview</h6>
            @if($branch->hasCoordinates())
                <iframe width="100%" height="220" frameborder="0" style="border-radius:.5rem"
                    src="https://maps.google.com/maps?q={{ $branch->latitude }},{{ $branch->longitude }}&z=16&output=embed"></iframe>
                <p class="small text-muted mt-2 mb-0">{{ $branch->latitude }}, {{ $branch->longitude }}</p>
            @else
                <div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i> Coordinates missing — add latitude/longitude to enable the map and GPS check-in.</div>
            @endif
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card p-3 mb-3">
            <h6 class="fw-bold">Repeated problems</h6>
            @forelse($repeated as $row)
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span class="badge text-bg-light text-capitalize">{{ $row->category }}</span>
                    <strong>{{ $row->total }}×</strong>
                </div>
            @empty
                <p class="text-muted small mb-0">None recorded.</p>
            @endforelse
        </div>

        <div class="card p-3 mb-3">
            <h6 class="fw-bold">Visit history</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Date</th><th>Template</th><th>By</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($branch->visits as $v)
                    <tr onclick="window.location='{{ route('visits.show', $v) }}'" style="cursor:pointer">
                        <td>{{ optional($v->scheduled_date)->format('d M Y') }}</td>
                        <td>{{ optional($v->template)->name }}</td>
                        <td>{{ optional($v->user)->name }}</td>
                        <td>@include('partials.status-badge', ['status' => $v->status])</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted small">No visits yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card p-3">
            <h6 class="fw-bold">Ticket history</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Ref</th><th>Title</th><th>Dept</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($tickets as $t)
                    <tr onclick="window.location='{{ route('tickets.show', $t) }}'" style="cursor:pointer">
                        <td>{{ $t->reference }}</td>
                        <td>{{ Str::limit($t->title, 40) }}</td>
                        <td>{{ optional($t->department)->name }}</td>
                        <td>@include('partials.status-badge', ['status' => $t->status])</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted small">No tickets.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
