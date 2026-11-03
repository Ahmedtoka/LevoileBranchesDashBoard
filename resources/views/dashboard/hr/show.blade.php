@extends('layouts.app')
@section('title', $e->name)
@include('dashboard.cctv.partials.style')

@section('content')
@php
    use App\Support\CctvReport;
    use App\Support\ObservationLabels;

    $place = $e->branch ? CctvReport::storeName($e->branch->branch_name) : trim((string) $e->work_location);
    $facts = [
        [dt('الوظيفة', 'Job'), $e->job_title],
        [dt('القسم', 'Section'), $e->section],
        [dt('الإدارة', 'Department'), $e->department],
        [dt('القطاع', 'Sector'), $e->sector],
        [dt('مكان العمل', 'Place of work'), $place],
        [dt('التليفون', 'Phone'), $e->phone],
        [dt('الإيميل', 'Email'), $e->email],
    ];
    $open = $violations->whereIn('status', \App\Support\ObservationMetrics::OPEN_STATUSES)->count();
@endphp

<div class="cv hr-p">
<a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('hr.summary') }}" class="small text-muted"><i class="bi bi-arrow-right"></i> {{ dt('دليل الموظفين', 'Staff directory') }}</a>

<header class="hr-p-head">
    <div class="hr-p-avatar" aria-hidden="true">{{ mb_substr($e->name, 0, 1) }}</div>
    <div>
        <h4 class="fw-bold mb-1">{{ $e->name }}</h4>
        <div class="hr-p-sub">
            @if($e->code)<span class="hr-p-code">{{ $e->code }}</span>@else<span class="badge text-bg-warning">{{ dt('من غير كود', 'No code') }}</span>@endif
            <span>{{ $e->job_title }}</span>
            @if($place)<span>· {{ $place }}</span>@endif
            @if(! $e->active)<span class="badge text-bg-secondary">{{ dt('غير نشط', 'Inactive') }}</span>@endif
        </div>
    </div>
</header>

<div class="hr-p-grid">
    <section class="cv-panel">
        <h2 class="cv-h">{{ dt('البيانات', 'Details') }}</h2>
        <dl class="hr-p-facts">
            @foreach($facts as [$label, $value])
                <dt>{{ $label }}</dt><dd @if($label === dt('التليفون', 'Phone')) dir="ltr" @endif>{{ $value ?: '—' }}</dd>
            @endforeach
        </dl>
    </section>

    <section class="cv-panel">
        <h2 class="cv-h">{{ dt('المدير المباشر', 'Line manager') }}</h2>
        @if($manager)
            <a href="{{ route('hr.show', $manager) }}" class="hr-p-person">
                <span class="hr-p-dot">{{ mb_substr($manager->name, 0, 1) }}</span>
                <span><b>{{ $manager->name }}</b><small>{{ $manager->code }} · {{ $manager->job_title }}</small></span>
            </a>
        @elseif($e->manager_name)
            <p class="mb-0">{{ $e->manager_name }} <small class="text-muted">{{ $e->manager_code }}</small></p>
        @else
            <p class="text-muted small mb-0">—</p>
        @endif

        @if($team->isNotEmpty())
            <h2 class="cv-h mt-3">{{ dt('الفريق', 'Team') }} <small class="text-muted fw-normal">{{ $team->count() }}</small></h2>
            <ul class="hr-p-team">
                @foreach($team as $m)
                    <li><a href="{{ route('hr.show', $m) }}"><b>{{ $m->name }}</b><small>{{ $m->job_title }}@if($m->branch) · {{ CctvReport::storeName($m->branch->branch_name) }}@endif</small></a></li>
                @endforeach
            </ul>
            <a href="{{ route('hr.summary', ['manager_code' => $e->code]) }}" class="small">{{ dt('افتح الفريق في الدليل', 'Open the team in the directory') }}</a>
        @endif
    </section>
</div>

<section class="cv-panel mt-3" id="violations">
    <h2 class="cv-h">{{ dt('مخالفات الكاميرات', 'CCTV violations') }}
        <small class="text-muted fw-normal">{{ $violations->count() }}@if($open) · {{ $open }} {{ dt('مفتوحة', 'open') }}@endif</small></h2>
    @if($violations->isEmpty())
        <p class="text-muted small mb-0">{{ dt('مفيش مخالفات مسجلة عليه.', 'No violations recorded.') }}</p>
    @else
        <ul class="hr-p-vios">
            @foreach($violations as $o)
                @php $x = (array) ($o->extra_values ?? []); $mins = $x['minutes_late'] ?? $x['minutes'] ?? null; @endphp
                <li>
                    <span class="hr-p-when">{{ $o->event_date?->format('j/n/Y') }}</span>
                    <span class="small text-muted">{{ CctvReport::storeName($o->branch?->branch_name) }}</span>
                    <span><b>{{ $o->catalogItem?->label_ar ?? $o->title }}</b>@if($mins) <span class="cv-mins">{{ $mins }} {{ dt('د', 'm') }}</span>@endif
                        @if($o->penalty)<small class="d-block text-muted">{{ dt('الجزاء:', 'Penalty:') }} {{ $o->penalty }}</small>@endif</span>
                    <span class="badge rounded-pill text-bg-{{ ObservationLabels::statusColor($o->status) }}">{{ ObservationLabels::status($o->status) }}</span>
                    <a href="{{ route('observations.show', $o) }}" class="cv-ref">{{ $o->reference }}</a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
</div>
@endsection

@push('head')
<style>
    .hr-p-head { display: flex; align-items: center; gap: 1rem; margin: .7rem 0 1rem; }
    .hr-p-avatar { inline-size: 3.6rem; block-size: 3.6rem; border-radius: 16px; background: var(--brand); color: #fff; display: grid; place-items: center; font-size: 1.6rem; font-weight: 800; flex: none; }
    .hr-p-sub { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; color: var(--ink-2); font-size: .9rem; }
    .hr-p-code { background: var(--brand-soft); color: var(--brand); font-weight: 800; border-radius: 7px; padding: 0 .5rem; font-variant-numeric: tabular-nums; }
    .hr-p-grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 1rem; }
    .hr-p-grid .cv-panel { margin: 0; }
    .hr-p-facts { display: grid; grid-template-columns: 8rem 1fr; gap: .45rem 1rem; margin: 0; font-size: .9rem; }
    .hr-p-facts dt { color: var(--muted); font-weight: 600; font-size: .8rem; }
    .hr-p-facts dd { margin: 0; color: var(--ink); }
    .hr-p-person { display: flex; align-items: center; gap: .6rem; padding: .5rem; border-radius: 11px; color: var(--ink); }
    .hr-p-person:hover { background: #f8f4f7; }
    .hr-p-dot { inline-size: 2.3rem; block-size: 2.3rem; border-radius: 10px; background: var(--brand-soft); color: var(--brand); display: grid; place-items: center; font-weight: 800; flex: none; }
    .hr-p-person small, .hr-p-team small { display: block; color: var(--muted); font-size: .76rem; }
    .hr-p-team { list-style: none; margin: 0 0 .5rem; padding: 0; max-block-size: 16rem; overflow: auto; }
    .hr-p-team a { display: block; padding: .35rem .5rem; border-radius: 8px; color: var(--ink); font-size: .86rem; }
    .hr-p-team a:hover { background: #f8f4f7; }
    .hr-p-vios { list-style: none; margin: 0; padding: 0; }
    .hr-p-vios li { display: grid; grid-template-columns: 6.5rem 7rem 1fr auto auto; gap: .7rem; align-items: center; padding: .45rem 0; border-bottom: 1px solid var(--line); font-size: .86rem; }
    .hr-p-vios li:last-child { border-bottom: 0; }
    .hr-p-when { color: var(--muted); font-variant-numeric: tabular-nums; }
    @media (max-width: 767.98px) { .hr-p-grid { grid-template-columns: 1fr; } .hr-p-vios li { grid-template-columns: 1fr 1fr; } }
</style>
@endpush
