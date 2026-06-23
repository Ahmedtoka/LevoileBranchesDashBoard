@extends('layouts.app')
@section('title', 'Visit #'.$visit->id)

@section('content')
<a href="{{ route('visits.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left"></i> Visits</a>
<div class="d-flex justify-content-between align-items-center mt-1 mb-1">
    <h4 class="fw-bold mb-0">{{ optional($visit->template)->name }} — {{ optional($visit->branch)->branch_name }}</h4>
    @include('partials.status-badge', ['status' => $visit->status])
</div>
<p class="text-muted small mb-3">
    <span class="badge text-bg-dark">{{ $visit->code }}</span>
    @if($visit->scheduled_date) · {{ $visit->scheduled_date->format('d M Y') }} @endif
    @if($visit->scheduled_time) {{ $visit->scheduled_time }} @endif
    @if($visit->template && $visit->template->scored) · Score: <strong>{{ $visit->score ?? 0 }}</strong> @endif
</p>

@php $deptGroups = $visit->tickets->groupBy(fn ($t) => optional($t->department)->name ?? 'Unassigned'); @endphp
@if($deptGroups->count())
<div class="card p-3 mb-3">
    <h6 class="fw-bold">Departments involved (problems from this visit)</h6>
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Department</th><th class="text-center">Tickets</th><th>References</th></tr></thead>
        <tbody>
        @foreach($deptGroups as $dept => $group)
            <tr>
                <td>{{ $dept }}</td>
                <td class="text-center">{{ $group->count() }}</td>
                <td class="small">
                    @foreach($group as $t)<a href="{{ route('tickets.show', $t) }}" class="me-1">{{ $t->reference }}</a>@endforeach
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="row g-3 mb-3">
    @php
        $cards = [['Positives', $visit->positives_count, 'success'], ['Problems', $visit->problems_count, 'danger'], ['Unanswered', $visit->unanswered_count, 'secondary'], ['Tickets', $visit->tickets_count, 'warning']];
    @endphp
    @foreach($cards as [$l, $v, $c])
        <div class="col-md-3 col-6"><div class="card stat-card p-3"><div class="text-muted small">{{ $l }}</div><div class="value text-{{ $c }}">{{ $v }}</div></div></div>
    @endforeach
</div>

<p class="small text-muted">
    Assigned to <strong>{{ optional($visit->user)->name }}</strong>
    · Scheduled {{ optional($visit->scheduled_date)->format('d M Y') }}
    @if($visit->checked_in_at) · Checked in {{ $visit->checked_in_at->format('d M H:i') }} {{ $visit->checkin_simulated ? '(simulated)' : '' }} @endif
</p>

@foreach($visit->template->sections as $section)
    <div class="card p-3 mb-3">
        <h6 class="fw-bold">{{ $section->title }} <span class="text-muted small">{{ $section->title_ar }}</span></h6>
        <table class="table table-sm align-middle mb-0">
            <tbody>
            @foreach($section->questions as $q)
                @php $a = $answers->get($q->id); @endphp
                <tr>
                    <td style="width:55%">{{ $q->question_text }}</td>
                    <td>
                        @if(!$a || !$a->result)
                            <span class="badge text-bg-light">—</span>
                        @elseif($a->result === 'pass')
                            <span class="badge text-bg-success">Pass</span>
                        @elseif($a->result === 'fail')
                            <span class="badge text-bg-danger">Fail</span>
                        @else
                            <span class="badge text-bg-secondary">N/A</span>
                        @endif
                    </td>
                    <td class="small text-muted">{{ optional($a)->comment }}</td>
                    <td>
                        @if($a && $a->evidence->count())
                            @foreach($a->evidence as $e)
                                <a href="{{ $e->url }}" target="_blank"><i class="bi bi-image text-primary"></i></a>
                            @endforeach
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endforeach

@if($visit->general_comments)
    <div class="card p-3 mb-3"><h6 class="fw-bold">General comments</h6><p class="small mb-0" dir="auto">{{ $visit->general_comments }}</p></div>
@endif

<div class="card p-3">
    <h6 class="fw-bold">Tickets created from this visit</h6>
    @forelse($visit->tickets as $t)
        <div class="d-flex justify-content-between border-bottom py-2 small">
            <a href="{{ route('tickets.show', $t) }}" class="text-decoration-none">{{ $t->reference }} — {{ Str::limit($t->title, 50) }}</a>
            <span>{{ optional($t->department)->name }} · @include('partials.status-badge', ['status' => $t->status])</span>
        </div>
    @empty
        <p class="text-muted small mb-0">No tickets.</p>
    @endforelse
</div>
@endsection
