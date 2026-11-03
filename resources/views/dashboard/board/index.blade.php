@extends('layouts.app')
@section('title', dt('لوحة المخالفات', 'Violations board'))
@include('dashboard.cctv.partials.style')

@section('content')
@php
    use App\Support\CctvDashboard;
    use App\Support\CctvReport;

    // Colour follows the department, never its rank: a fixed order over every reporting department.
    $palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    $colour = [];
    foreach ($allDepartments->sortBy('id')->values() as $i => $d) { $colour[$d->id] = $palette[$i % count($palette)]; }

    $q = request()->query();
    $with = fn (array $c) => route('board.index', array_filter(array_merge($q, $c), fn ($v) => $v !== null && $v !== ''));
    $list = fn (array $c) => route('observations.index', array_filter(array_merge(['range' => 'custom', 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'kind' => 'issue', 'branch_id' => $branchId, 'reporting_department_id' => $departmentId], $c), fn ($v) => $v !== null && $v !== ''));
    $max = max(1, (int) $rows->max(fn ($r) => max($r['cells'] ?: [0])));
    $top = $rows->take(5);
    $topMax = max(1, (int) $top->max('total'));
    $change = CctvDashboard::change($kpi['total'], $kpi['prev']);
    $periodLabel = $week ? dt('أسبوع', 'Week').' '.$week.' · '.$from->format('j/n').' – '.$to->format('j/n') : CctvDashboard::monthName($month);
@endphp

<div class="cv vb">
<form method="GET" action="{{ route('board.index') }}" class="cv-bar">
    <div class="cv-bar-row">
        <h4 class="fw-bold mb-0 me-2">{{ dt('لوحة المخالفات', 'Violations board') }}</h4>
        <div class="cv-step">
            <a class="cv-arrow" href="{{ $with(['month' => $month->copy()->subMonthNoOverflow()->format('Y-m'), 'week' => null]) }}" title="{{ dt('الشهر السابق', 'Previous month') }}"><i class="bi bi-chevron-right"></i></a>
            <span class="cv-month">{{ CctvDashboard::monthName($month) }}</span>
            <a class="cv-arrow" href="{{ $with(['month' => $month->copy()->addMonthNoOverflow()->format('Y-m'), 'week' => null]) }}" title="{{ dt('الشهر التالي', 'Next month') }}"><i class="bi bi-chevron-left"></i></a>
        </div>
        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
        @if($week)<input type="hidden" name="week" value="{{ $week }}">@endif
        <select name="branch_id" class="cv-select" onchange="this.form.submit()" aria-label="{{ dt('الفرع', 'Branch') }}">
            <option value="">{{ dt('كل الفروع', 'All branches') }}</option>
            @foreach($branches as $b)<option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ CctvReport::storeName($b->branch_name) }}</option>@endforeach
        </select>
        <select name="department_id" class="cv-select" onchange="this.form.submit()" aria-label="{{ dt('الإدارة', 'Department') }}">
            <option value="">{{ dt('كل الإدارات', 'All departments') }}</option>
            @foreach($allDepartments as $d)<option value="{{ $d->id }}" @selected($departmentId === $d->id)>{{ $d->name }}</option>@endforeach
        </select>
    </div>
    <nav class="cv-weeks">
        <a href="{{ $with(['week' => null]) }}" @class(['on' => ! $week])><b>{{ dt('الشهر كله', 'Whole month') }}</b><small>{{ CctvDashboard::monthName($month) }}</small></a>
        @for($n = 1; $n <= CctvDashboard::WEEKS; $n++)
            @php [$wf, $wt] = CctvDashboard::weekRange($month, $n); @endphp
            <a href="{{ $with(['week' => $n]) }}" @class(['on' => $week === $n, 'is-future' => $wf->isFuture()])><b>{{ dt('أسبوع', 'Week') }} {{ $n }}</b><small>{{ $wf->format('j') }}–{{ $wt->format('j/n') }}</small></a>
        @endfor
    </nav>
</form>

{{-- Headline --}}
<section class="vb-kpis">
    <div class="vb-kpi vb-kpi-main">
        <span>{{ dt('مخالفات', 'Violations') }} · {{ $periodLabel }}</span>
        <b>{{ number_format($kpi['total']) }}</b>
        <small>
            @if($change === null) {{ dt('مفيش فترة قبلها للمقارنة', 'Nothing before to compare') }}
            @else <span @class(['vb-delta', 'is-up' => $change > 0, 'is-down' => $change < 0])>{{ $change > 0 ? '▲' : ($change < 0 ? '▼' : '•') }} {{ abs($change) }}%</span> {{ dt('عن', 'vs') }} {{ $kpi['prev'] }} {{ dt('الفترة اللي قبلها', 'the period before') }} @endif
        </small>
    </div>
    <a class="vb-kpi" href="#open"><span>{{ dt('مفتوحة ومتحلتش', 'Open, not solved') }}</span><b class="is-warn">{{ number_format($kpi['open']) }}</b><small>{{ dt('دلوقتي، من كل التواريخ', 'Now, all dates') }}</small></a>
    <a class="vb-kpi" href="#open"><span>{{ dt('متأخرة عن الميعاد', 'Overdue') }}</span><b class="is-bad">{{ number_format($kpi['overdue']) }}</b><small>{{ $kpi['open'] ? round($kpi['overdue'] * 100 / $kpi['open']) : 0 }}% {{ dt('من المفتوحة', 'of the open ones') }}</small></a>
    <div class="vb-kpi"><span>{{ dt('اتقفلت من مخالفات الفترة', 'Closed, of the period') }}</span><b>{{ $kpi['closed'] }}</b><small>{{ $kpi['total'] ? round($kpi['closed'] * 100 / $kpi['total']) : 0 }}%</small></div>
    <div class="vb-kpi"><span>{{ dt('فروع عليها مخالفات', 'Branches with violations') }}</span><b>{{ $kpi['branches'] }}</b><small>{{ dt('من', 'of') }} {{ $branches->count() }}</small></div>
</section>

{{-- By department + top 5 branches --}}
<div class="vb-two">
    <section class="cv-panel">
        <h2 class="cv-h">{{ dt('حسب الإدارة اللي سجّلت', 'By reporting department') }}</h2>
        @if(! $byDept)
            <p class="text-muted small mb-0">{{ dt('مفيش مخالفات في الفترة دي.', 'No violations in this period.') }}</p>
        @else
            <ul class="vb-depts">
                @foreach($departments->filter(fn ($d) => isset($byDept[$d->id])) as $d)
                    @php $n = $byDept[$d->id]; $p = $prevByDept[$d->id] ?? 0; $ch = CctvDashboard::change($n, $p); @endphp
                    <li><a href="{{ $with(['department_id' => $departmentId === $d->id ? null : $d->id]) }}" @class(['on' => $departmentId === $d->id])>
                        <i class="cv-dot" @style(['--c: '.$colour[$d->id]])></i>
                        <span>{{ $d->name }}</span>
                        <b>{{ $n }}</b>
                        <small>{{ round($n * 100 / max(1, $kpi['total'])) }}%</small>
                        @if($ch !== null)<span @class(['vb-delta', 'is-up' => $ch > 0, 'is-down' => $ch < 0])>{{ $ch > 0 ? '▲' : ($ch < 0 ? '▼' : '•') }} {{ abs($ch) }}%</span>@else<span class="vb-delta">{{ dt('جديد', 'new') }}</span>@endif
                    </a></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="cv-panel">
        <h2 class="cv-h">{{ dt('أكتر 5 فروع مخالفات', 'Top 5 branches') }}</h2>
        @if($top->where('total', '>', 0)->isEmpty())
            <p class="text-muted small mb-0">{{ dt('مفيش مخالفات في الفترة دي.', 'No violations in this period.') }}</p>
        @else
            <ol class="vb-top">
                @foreach($top->where('total', '>', 0) as $i => $r)
                    <li>
                        <a href="{{ $with(['branch_id' => $r['id']]) }}" class="vb-top-name"><span class="vb-rank">{{ $i + 1 }}</span>{{ $names[$r['id']] ?? '?' }}</a>
                        <span class="vb-stack" style="inline-size: {{ max(8, round($r['total'] * 100 / $topMax)) }}%">
                            @foreach($departments as $d)
                                @if($r['cells'][$d->id] ?? 0)<i style="flex: {{ $r['cells'][$d->id] }}; background: {{ $colour[$d->id] }}" title="{{ $d->name }}: {{ $r['cells'][$d->id] }}"></i>@endif
                            @endforeach
                        </span>
                        <b>{{ $r['total'] }}</b>
                    </li>
                @endforeach
            </ol>
            <p class="vb-legend">@foreach($departments->filter(fn ($d) => isset($byDept[$d->id])) as $d)<span><i class="cv-dot" @style(['--c: '.$colour[$d->id]])></i>{{ $d->name }}</span>@endforeach</p>
        @endif
    </section>
</div>

{{-- Branches × departments --}}
<section class="cv-panel">
    <h2 class="cv-h">{{ dt('الفروع × الإدارات', 'Branches × departments') }} <small class="text-muted fw-normal">{{ $periodLabel }} · {{ dt('اضغط على أي رقم يفتح المخالفات', 'click a number to open the violations') }}</small></h2>
    <div class="vb-matrix-wrap">
        <table class="vb-matrix">
            <thead><tr>
                <th class="vb-m-branch">{{ dt('الفرع', 'Branch') }}</th>
                @foreach($departments as $d)<th><i class="cv-dot" @style(['--c: '.$colour[$d->id]])></i>{{ $d->name }}</th>@endforeach
                <th class="vb-m-total">{{ dt('الإجمالي', 'Total') }}</th>
                <th>{{ dt('عن الفترة اللي قبلها', 'vs before') }}</th>
                <th class="vb-m-open">{{ dt('مفتوحة الآن', 'Open now') }}</th>
            </tr></thead>
            <tbody>
            @forelse($rows as $r)
                @php $ch = CctvDashboard::change($r['total'], $r['prev']); @endphp
                <tr>
                    <th class="vb-m-branch"><a href="{{ $with(['branch_id' => $r['id']]) }}">{{ $names[$r['id']] ?? '?' }}</a></th>
                    @foreach($departments as $d)
                        @php $n = $r['cells'][$d->id] ?? 0; @endphp
                        <td>@if($n)<a href="{{ $list(['branch_id' => $r['id'], 'reporting_department_id' => $d->id]) }}" class="vb-cell" style="--a: {{ round(0.08 + 0.62 * $n / $max, 2) }}">{{ $n }}</a>@else<span class="vb-zero">·</span>@endif</td>
                    @endforeach
                    <td class="vb-m-total">@if($r['total'])<a href="{{ $list(['branch_id' => $r['id']]) }}">{{ $r['total'] }}</a>@else 0 @endif</td>
                    <td>@if($ch === null)@if($r['total'])<span class="vb-delta">{{ dt('جديد', 'new') }}</span>@endif @else<span @class(['vb-delta', 'is-up' => $ch > 0, 'is-down' => $ch < 0])>{{ $ch > 0 ? '▲' : ($ch < 0 ? '▼' : '•') }} {{ abs($ch) }}%</span>@endif</td>
                    <td class="vb-m-open">@if($r['open'])<a href="{{ $with(['branch_id' => $r['id']]) }}#open">{{ $r['open'] }}</a>@else <span class="vb-zero">0</span> @endif</td>
                </tr>
            @empty
                <tr><td colspan="{{ $departments->count() + 4 }}" class="text-center text-muted py-4">{{ dt('مفيش مخالفات في الفترة دي.', 'No violations in this period.') }}</td></tr>
            @endforelse
            </tbody>
            @if($rows->count() > 1)
                <tfoot><tr>
                    <th class="vb-m-branch">{{ dt('الإجمالي', 'Total') }}</th>
                    @foreach($departments as $d)<td>{{ $byDept[$d->id] ?? 0 }}</td>@endforeach
                    <td class="vb-m-total">{{ $kpi['total'] }}</td>
                    <td></td>
                    <td class="vb-m-open">{{ $kpi['open'] }}</td>
                </tr></tfoot>
            @endif
        </table>
    </div>
</section>

{{-- Open, not solved --}}
<div class="vb-two vb-open" id="open">
    <section class="cv-panel">
        <h2 class="cv-h">{{ dt('أقدم المخالفات المفتوحة', 'Oldest open violations') }} <small class="text-muted fw-normal">{{ $kpi['open'] }} {{ dt('مفتوحة', 'open') }}@if($branchId) · {{ $names[$branchId] ?? CctvReport::storeName($branches->firstWhere('id', $branchId)?->branch_name) }}@endif</small></h2>
        @if($oldest->isEmpty())
            <p class="text-muted small mb-0"><i class="bi bi-check2-circle text-success"></i> {{ dt('مفيش مخالفات مفتوحة.', 'Nothing open.') }}</p>
        @else
            <ul class="vb-olist">
                @foreach($oldest as $o)
                    @php $date = $o->event_date ?? $o->created_at; $late = $o->ticket?->isOverdue(); @endphp
                    <li @class(['is-late' => $late])>
                        <span class="vb-age"><b>{{ (int) $date->diffInDays(now()) }}</b> {{ dt('يوم', 'days') }}</span>
                        <span class="vb-o-main">
                            <b>{{ $o->catalogItem?->label_ar ?? $o->title }}</b>
                            <small>{{ CctvReport::storeName($o->branch?->branch_name) }} · {{ dt('من', 'from') }} {{ $o->reportingDepartment?->name }}@if($o->employee || $o->employee_name) · {{ $o->employee?->name ?? $o->employee_name }}@endif</small>
                        </span>
                        <span class="vb-o-side">
                            <small>{{ dt('عند', 'with') }} <b>{{ $o->responsibleDepartment?->name ?? '—' }}</b></small>
                            @if($late)<span class="badge text-bg-danger">{{ dt('متأخرة', 'Overdue') }}</span>@endif
                        </span>
                        <a href="{{ route('observations.show', $o) }}" class="cv-ref">{{ $o->reference }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="cv-panel">
        <h2 class="cv-h">{{ dt('المفتوحة عند مين', 'Open, by who holds them') }}</h2>
        @if(! $openByResponsible)
            <p class="text-muted small mb-0">—</p>
        @else
            @php $rMax = max($openByResponsible); @endphp
            <ul class="vb-resp">
                @foreach($openByResponsible as $id => $n)
                    <li>
                        <span>{{ $responsibleNames[$id] ?? dt('من غير إدارة', 'Unassigned') }}</span>
                        <span class="vb-resp-bar"><i style="inline-size: {{ round($n * 100 / $rMax) }}%"></i></span>
                        <b>{{ $n }}</b>
                    </li>
                @endforeach
            </ul>
            <p class="small text-muted mt-2 mb-0">{{ dt('كل إدارة بتشوف اللي عندها في «مهام إدارتي».', 'Each department sees its own in «My department\'s tasks».') }}</p>
        @endif
    </section>
</div>
</div>
@endsection

@push('head')
<style>
    .vb .cv-bar-row h4 { font-size: 1.2rem; }
    .vb-kpis { display: grid; grid-template-columns: 1.5fr repeat(4, 1fr); gap: .7rem; margin-bottom: 1rem; }
    .vb-kpi { background: var(--surface); border-radius: 14px; padding: .9rem 1rem; display: flex; flex-direction: column; gap: .2rem; color: var(--ink); box-shadow: 0 1px 2px rgba(74,15,51,.05); }
    a.vb-kpi:hover { box-shadow: 0 0 0 1.5px var(--brand); }
    .vb-kpi span { font-size: .78rem; color: var(--muted); }
    .vb-kpi b { font-size: 1.8rem; font-weight: 800; line-height: 1.1; letter-spacing: -.02em; }
    .vb-kpi b.is-warn { color: #9a5b00; } .vb-kpi b.is-bad { color: var(--up); }
    .vb-kpi small { font-size: .74rem; color: var(--ink-2); }
    .vb-kpi-main { background: var(--brand); color: #fff; }
    .vb-kpi-main span, .vb-kpi-main small { color: #f3dbe9; }
    .vb-kpi-main b { font-size: 2.4rem; }
    .vb-kpi-main .vb-delta { background: rgba(255,255,255,.18); color: #fff; }
    .vb-delta { display: inline-block; font-size: .72rem; font-weight: 700; padding: 0 .4rem; border-radius: 6px; background: #f1edf0; color: var(--ink-2); white-space: nowrap; }
    .vb-delta.is-up { background: var(--up-bg); color: var(--up); }
    .vb-delta.is-down { background: var(--down-bg); color: var(--down); }

    .vb-two { display: grid; grid-template-columns: 1fr 1.3fr; gap: 1rem; margin-bottom: 1rem; }
    .vb-two .cv-panel { margin: 0; }
    .vb-depts { list-style: none; margin: 0; padding: 0; }
    .vb-depts a { display: grid; grid-template-columns: .8rem 1fr auto 2.6rem 4.2rem; align-items: center; gap: .5rem; padding: .45rem .5rem; border-radius: 9px; color: var(--ink); font-size: .9rem; }
    .vb-depts a:hover, .vb-depts a.on { background: #f8f4f7; }
    .vb-depts b { font-size: 1.05rem; }
    .vb-depts small { color: var(--muted); text-align: end; }
    .vb-depts .vb-delta { justify-self: end; }
    .vb-top { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .55rem; }
    .vb-top li { display: grid; grid-template-columns: 9rem 1fr 2.4rem; align-items: center; gap: .6rem; }
    .vb-top-name { color: var(--ink); font-weight: 700; font-size: .88rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .vb-rank { display: inline-grid; place-items: center; inline-size: 1.3rem; block-size: 1.3rem; border-radius: 6px; background: #f4eff2; color: var(--ink-2); font-size: .72rem; margin-inline-end: .35rem; }
    .vb-stack { display: flex; gap: 2px; block-size: 14px; }
    .vb-stack i { display: block; min-inline-size: 4px; }
    .vb-stack i:first-child { border-start-start-radius: 4px; border-end-start-radius: 4px; }
    .vb-stack i:last-child { border-start-end-radius: 4px; border-end-end-radius: 4px; }
    .vb-top b { text-align: end; }
    .vb-legend { display: flex; flex-wrap: wrap; gap: .3rem .9rem; font-size: .76rem; color: var(--ink-2); margin: .8rem 0 0; }
    .vb-legend span { display: inline-flex; align-items: center; gap: .3rem; }

    .vb-matrix-wrap { overflow: auto; max-block-size: 34rem; scrollbar-width: thin; }
    .vb-matrix { inline-size: 100%; border-collapse: separate; border-spacing: 0; font-size: .86rem; font-variant-numeric: tabular-nums; }
    .vb-matrix th, .vb-matrix td { padding: .4rem .5rem; text-align: center; border-bottom: 1px solid var(--line); white-space: nowrap; }
    .vb-matrix thead th { position: sticky; top: 0; z-index: 2; background: #faf7f9; font-size: .76rem; font-weight: 700; color: var(--ink-2); }
    .vb-matrix thead th .cv-dot { margin-inline-end: .3rem; }
    .vb-matrix .vb-m-branch { text-align: start; position: sticky; inset-inline-start: 0; background: var(--surface); z-index: 1; font-weight: 700; }
    .vb-matrix thead .vb-m-branch { z-index: 3; background: #faf7f9; }
    .vb-matrix .vb-m-branch a { color: var(--ink); }
    .vb-matrix .vb-m-branch a:hover { color: var(--brand); }
    .vb-cell { display: inline-block; min-inline-size: 2.2rem; padding: .15rem .4rem; border-radius: 7px; color: var(--ink); font-weight: 700; background: rgba(156, 30, 110, var(--a)); }
    .vb-cell:hover { outline: 2px solid var(--brand); }
    .vb-zero { color: #d3c9cf; }
    .vb-m-total { font-weight: 800; background: #fbf8fa; }
    .vb-m-total a { color: var(--ink); }
    .vb-m-open a { color: #9a5b00; font-weight: 800; }
    .vb-matrix tfoot th, .vb-matrix tfoot td { position: sticky; bottom: 0; background: #faf7f9; font-weight: 800; border-top: 1.5px solid var(--line); }

    .vb-open { grid-template-columns: 1.6fr 1fr; }
    .vb-olist { list-style: none; margin: 0; padding: 0; }
    .vb-olist li { display: grid; grid-template-columns: 4.2rem 1fr auto auto; gap: .7rem; align-items: center; padding: .5rem 0; border-bottom: 1px solid var(--line); }
    .vb-olist li:last-child { border-bottom: 0; }
    .vb-age { text-align: center; color: var(--ink-2); font-size: .72rem; line-height: 1.1; }
    .vb-age b { display: block; font-size: 1.1rem; color: var(--ink); }
    .vb-olist li.is-late .vb-age b { color: var(--up); }
    .vb-o-main { min-inline-size: 0; }
    .vb-o-main b { display: block; font-size: .88rem; font-weight: 600; }
    .vb-o-main small, .vb-o-side small { color: var(--muted); font-size: .74rem; }
    .vb-o-side { display: flex; flex-direction: column; align-items: flex-end; gap: .2rem; }
    .vb-resp { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .45rem; }
    .vb-resp li { display: grid; grid-template-columns: 8rem 1fr 3rem; align-items: center; gap: .6rem; font-size: .86rem; }
    .vb-resp-bar { block-size: 10px; background: #f1edf0; border-radius: 5px; overflow: hidden; }
    .vb-resp-bar i { display: block; block-size: 100%; background: #9a5b00; border-radius: 5px; min-inline-size: 3px; }
    .vb-resp b { text-align: end; }
    @media (max-width: 1199.98px) { .vb-kpis { grid-template-columns: repeat(3, 1fr); } .vb-kpi-main { grid-column: span 3; } }
    @media (max-width: 991.98px) { .vb-two, .vb-open { grid-template-columns: 1fr; } }
    @media (max-width: 575.98px) { .vb-kpis { grid-template-columns: 1fr 1fr; } .vb-kpi-main { grid-column: span 2; } }
</style>
@endpush
