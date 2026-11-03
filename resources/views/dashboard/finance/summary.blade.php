@extends('layouts.app')
@section('title', dt('ملخص المالية', 'Finance summary'))
@include('dashboard.cctv.partials.style')
@include('dashboard.cctv.partials.list-style')
@push('head')<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>@endpush

@section('content')
@php
    use App\Support\CctvDashboard;
    use App\Support\CctvReport;

    $monthKey = $month->format('Y-m');
    $url = fn (array $q = []) => route('finance.summary', array_filter(array_merge(['branch_id' => $branch?->id, 'month' => $monthKey, 'week' => $week], $q), fn ($v) => $v !== null && $v !== ''));
    $list = fn (array $q = []) => route('finance.index', array_filter(array_merge(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'branch_id' => $branch?->id], $q), fn ($v) => $v !== null && $v !== ''));

    $branchIds = array_merge([null], $branches->pluck('id')->all());
    $at = array_search($branch?->id, $branchIds, true);
    $prevBranch = $branchIds[($at - 1 + count($branchIds)) % count($branchIds)];
    $nextBranch = $branchIds[($at + 1) % count($branchIds)];

    // One validated categorical colour per control, fixed by the control's order.
    $palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    $colour = [];
    foreach ($categories->values() as $i => $c) { $colour[$c->id] = $palette[$i % count($palette)]; }
    $controls = $categories->reject(fn ($c) => $c->slug === 'other')->values();

    $total = array_sum($byCategory);
    $prevTotal = array_sum($prevByCategory);
    $periodLabel = $week ? dt('الأسبوع ', 'Week ').$week.' · '.$from->format('j').'–'.$to->format('j').' '.CctvDashboard::monthName($month) : CctvDashboard::monthName($month);
    $maxBranch = max(array_column($topBranches, 'n') ?: [1]);
    $egp = fn ($v) => number_format((float) $v, 0).' '.dt('ج', 'EGP');
@endphp

<div class="cv">
<section class="cv-bar" aria-label="{{ dt('الفلاتر', 'Filters') }}">
    <div class="cv-bar-row">
        <div class="cv-step" role="group" aria-label="{{ dt('الفرع', 'Branch') }}">
            <a class="cv-arrow" href="{{ $url(['branch_id' => $prevBranch]) }}" title="{{ dt('الفرع السابق', 'Previous branch') }}"><i class="bi bi-chevron-right"></i></a>
            <form method="GET" action="{{ route('finance.summary') }}" class="m-0">
                <input type="hidden" name="month" value="{{ $monthKey }}">
                @if($week)<input type="hidden" name="week" value="{{ $week }}">@endif
                <select name="branch_id" class="cv-select" onchange="this.form.submit()" aria-label="{{ dt('الفرع', 'Branch') }}">
                    <option value="">{{ dt('كل الفروع', 'All branches') }}</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected($branch?->id === $b->id)>{{ CctvReport::storeName($b->branch_name) }}</option>@endforeach
                </select>
            </form>
            <a class="cv-arrow" href="{{ $url(['branch_id' => $nextBranch]) }}" title="{{ dt('الفرع التالي', 'Next branch') }}"><i class="bi bi-chevron-left"></i></a>
        </div>
        <div class="cv-step" role="group" aria-label="{{ dt('الشهر', 'Month') }}">
            <a class="cv-arrow" href="{{ $url(['month' => $month->copy()->subMonthNoOverflow()->format('Y-m'), 'week' => null]) }}" title="{{ dt('الشهر السابق', 'Previous month') }}"><i class="bi bi-chevron-right"></i></a>
            <span class="cv-month">{{ CctvDashboard::monthName($month) }}</span>
            <a class="cv-arrow" href="{{ $url(['month' => $month->copy()->addMonthNoOverflow()->format('Y-m'), 'week' => null]) }}" title="{{ dt('الشهر التالي', 'Next month') }}"><i class="bi bi-chevron-left"></i></a>
        </div>
        <div class="cv-actions d-print-none">
            @if($canRecord)<a href="{{ route('finance.create', ['month' => $monthKey, 'week' => $week]) }}" class="btn btn-sm btn-primary"><i class="bi bi-clipboard-check"></i> {{ dt('مراجعة الفروع', 'Review branches') }}</a>@endif
            <a href="{{ $list() }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-ul"></i> {{ dt('القائمة', 'List') }}</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()" title="{{ dt('طباعة', 'Print') }}"><i class="bi bi-printer"></i></button>
        </div>
    </div>
    <nav class="cv-weeks" aria-label="{{ dt('الأسبوع', 'Week') }}">
        <a href="{{ $url(['week' => null]) }}" @class(['on' => ! $week])><b>{{ dt('الشهر كله', 'Whole month') }}</b><small>1–{{ $month->copy()->endOfMonth()->format('j') }}</small></a>
        @foreach($weeks as $w)
            <a href="{{ $url(['week' => $w['n']]) }}" @class(['on' => $week === $w['n'], 'is-future' => $w['future']])>
                <b>{{ dt('الأسبوع', 'Week') }} {{ $w['n'] }}</b>
                <small>{{ $w['from']->format('j') }}–{{ $w['to']->format('j') }} · {{ $w['compliance']['rate'] !== null ? $w['compliance']['rate'].'%' : dt('مفيش مراجعة', 'no review') }}</small>
            </a>
        @endforeach
    </nav>
</section>

@if($compliance['reviewed'] === 0)
    <div class="cv-empty">
        <i class="bi bi-clipboard-x"></i>
        <p class="mb-1 fw-semibold">{{ dt('مفيش مراجعات مالية في', 'No finance reviews in') }} {{ $periodLabel }}{{ $branch ? ' · '.CctvReport::storeName($branch->branch_name) : '' }}</p>
        <p class="text-muted small mb-0">{{ dt('جرّب شهر تاني بالأسهم، أو «كل الفروع».', 'Try another month with the arrows, or all branches.') }}</p>
    </div>
@else

{{-- 1. The report's executive summary --}}
<section class="cv-sum">
    <div class="cv-sum-stats">
        <div class="cv-stat cv-stat-main">
            <span>{{ dt('نسبة الالتزام', 'Compliance') }}</span>
            <b>{{ $compliance['rate'] }}%</b>
            <span class="fin-rate-sub">@include('dashboard.finance.partials.rate', ['now' => $compliance['rate'], 'prev' => $prevCompliance['rate']]) {{ dt('عن', 'vs') }} {{ $pLabel }}</span>
        </div>
        <div class="cv-stat"><span><i class="fin-dot is-ok"></i>{{ dt('مستقرة', 'Stable') }}</span><b>{{ $compliance['clean'] }}</b></div>
        <div class="cv-stat"><span><i class="fin-dot is-warn"></i>{{ dt('محتاجة متابعة', 'Follow up') }}</span><b>{{ $compliance['follow'] }}</b></div>
        <div class="cv-stat"><span><i class="fin-dot is-bad"></i>{{ dt('حرجة', 'Critical') }}</span><b>{{ $compliance['critical'] }}</b></div>
        <a class="cv-stat" href="{{ $list() }}"><span>{{ dt('المشاكل', 'Problems') }}</span><b>{{ $total }}</b>@include('dashboard.cctv.partials.delta', ['now' => $total, 'prev' => $prevTotal])</a>
        <a class="cv-stat" href="{{ $list(['amount' => 'yes', 'sort' => 'amount']) }}"><span>{{ dt('مبالغ مسجلة', 'Amounts') }}</span><b>{{ $egp($money['amount']) }}</b><small class="text-muted">{{ $money['with_amount'] }} {{ dt('مشكلة', 'problems') }}</small></a>
        <a class="cv-stat" href="{{ $list(['lp' => 'yes']) }}"><span>{{ dt('اتحولت لمنع الخسائر', 'Sent to loss prevention') }}</span><b>{{ $money['lp'] }}</b><i class="bi bi-chevron-left"></i></a>
    </div>
    <p class="fin-note">
        {{ $compliance['reviewed'] }} {{ dt('مراجعة فرع', 'branch reviews') }} ({{ $compliance['branches'] }} {{ dt('فرع', 'branches') }}) ·
        {{ dt('المستقر: مراجعة من غير ولا مشكلة. الحرج:', 'Stable: a review with no problem. Critical:') }} {{ \App\Support\FinanceReport::CRITICAL_FROM }} {{ dt('مشاكل أو أكتر في الأسبوع.', 'or more problems in the week.') }}
    </p>
</section>

{{-- 2. One box per control --}}
<section class="fin-controls">
    @foreach($controls as $c)
        @php $n = $byCategory[$c->id] ?? 0; $p = $prevByCategory[$c->id] ?? 0; @endphp
        <a href="{{ $list(['category_id' => $c->id]) }}" class="cv-type" @style(['--c: '.$colour[$c->id]])>
            <span class="cv-type-name"><i class="cv-dot"></i>{{ $c->name_ar }}</span>
            <span class="cv-type-n">{{ $n }}</span>
            <span class="cv-type-bar" aria-hidden="true"><i style="inline-size: {{ $total ? round($n * 100 / $total) : 0 }}%"></i></span>
            <span class="cv-type-foot">
                <span>{{ $total ? round($n * 100 / $total) : 0 }}% {{ dt('من المشاكل', 'of problems') }}</span>
                @include('dashboard.cctv.partials.delta', ['now' => $n, 'prev' => $p])
            </span>
        </a>
    @endforeach
</section>

{{-- 3. Where, and how much --}}
<section class="cv-grid">
    <div class="cv-panel">
        @if($branch)
            <h2 class="cv-h">{{ dt('أكتر المشاكل في', 'Most frequent problems at') }} {{ CctvReport::storeName($branch->branch_name) }}</h2>
        @else
            <h2 class="cv-h">{{ dt('أكتر 5 فروع مشاكل', 'Top 5 branches') }}</h2>
            <ol class="cv-rank">
                @foreach($topBranches as $i => $b)
                    <li>
                        <span class="cv-rank-i">{{ $i + 1 }}</span>
                        <a class="cv-rank-body" href="{{ $url(['branch_id' => $b['id']]) }}">
                            <span class="cv-rank-name">{{ $b['name'] }}</span>
                            <span class="cv-rank-bar"><i style="inline-size: {{ round($b['n'] * 100 / $maxBranch) }}%"></i></span>
                        </a>
                        <span class="cv-rank-n">{{ $b['n'] }} <small>{{ $total ? round($b['n'] * 100 / $total) : 0 }}%</small></span>
                        @include('dashboard.cctv.partials.delta', ['now' => $b['n'], 'prev' => $b['prev']])
                    </li>
                @endforeach
            </ol>
            <h3 class="cv-h cv-h-sm">{{ dt('أكتر المشاكل تكرارًا', 'Most frequent problems') }}</h3>
        @endif
        <ul class="cv-chips">
            @foreach($topProblems as $p)<li>{{ $p['label'] }} <b>{{ $p['n'] }}</b></li>@endforeach
        </ul>
    </div>
    <div class="cv-panel">
        <h2 class="cv-h">{{ dt('أكبر المبالغ', 'Biggest amounts') }}</h2>
        @if($biggest->isEmpty())
            <p class="text-muted small mb-0">{{ dt('مفيش مبالغ متسجلة في الفترة دي.', 'No amounts recorded in this period.') }}</p>
        @else
            <ul class="fin-money">
                @foreach($biggest as $o)
                    <li>
                        <b class="fin-money-n">{{ $egp($o->extra_values['amount'] ?? 0) }}</b>
                        <span class="fin-money-body">
                            <span>{{ $o->catalogItem?->label_ar ?? $o->title }}</span>
                            <small>{{ CctvReport::storeName($o->branch?->branch_name) }} · {{ $o->event_date?->format('j/n') }}@if(\App\Support\FinanceReport::isLossPrevention($o)) · <i class="bi bi-shield-exclamation"></i> {{ dt('منع الخسائر', 'loss prevention') }}@endif</small>
                        </span>
                        <a href="{{ route('observations.show', $o) }}" class="cv-ref">{{ $o->reference }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

{{-- 4. Week by week: the controls as a coloured list beside the chart, the compliance of each week under it --}}
@php
    $monthByType = $controls->map(fn ($c) => ['id' => $c->id, 'label' => $c->name_ar, 'color' => $colour[$c->id], 'n' => collect($weeks)->sum(fn ($w) => $w['cells'][$c->id] ?? 0)])
        ->filter(fn ($r) => $r['n'] > 0)->sortByDesc('n')->values();
@endphp
<section class="cv-panel cv-weekbox">
    <h2 class="cv-h">{{ dt('أسبوع بأسبوع', 'Week by week') }} · {{ CctvDashboard::monthName($month) }}</h2>
    <div class="cv-weekbox-body">
        <div class="cv-weekbox-side">
            <ul class="cv-legend" id="cvLegend">
                @foreach($monthByType as $r)
                    <li tabindex="0">
                        <i class="cv-dot" @style(['--c: '.$r['color']])></i>
                        <span class="cv-legend-name">{{ $r['label'] }}</span>
                        <b>{{ $r['n'] }}</b>
                        <span class="cv-legend-pct">{{ round($r['n'] * 100 / max(1, $monthByType->sum('n'))) }}%</span>
                        <span class="cv-legend-bar" aria-hidden="true"><i @style(['inline-size: '.round($r['n'] * 100 / max(1, $monthByType->max('n'))).'%', 'background: '.$r['color']])></i></span>
                    </li>
                @endforeach
            </ul>
            <div class="cv-weekcells">
                @foreach($weeks as $w)
                    <a href="{{ $url(['week' => $w['n']]) }}" @class(['on' => $week === $w['n'], 'is-future' => $w['future']])>
                        <span>{{ dt('الأسبوع', 'Week') }} {{ $w['n'] }}</span>
                        <b>{{ $w['compliance']['rate'] !== null ? $w['compliance']['rate'].'%' : '—' }}</b>
                        <small class="text-muted">{{ $w['total'] }} {{ dt('مشكلة', 'problems') }}</small>
                        @if($w['compliance']['rate'] !== null)@include('dashboard.finance.partials.rate', ['now' => $w['compliance']['rate'], 'prev' => $w['prev_rate']])@endif
                    </a>
                @endforeach
            </div>
        </div>
        <div class="cv-weekbox-chart"><canvas id="cvWeeks" role="img" aria-label="{{ dt('المشاكل في كل أسبوع حسب البند', 'Problems per week by control') }}"></canvas></div>
    </div>
</section>

{{-- 5. The report's matrix: branches against the controls --}}
<section class="cv-panel p-2">
    <div class="d-flex align-items-baseline gap-2 px-2 pt-1 pb-2">
        <h2 class="cv-h mb-0">{{ dt('متابعة الفروع', 'Branch follow-up') }} · {{ $periodLabel }}</h2>
        <span class="small text-muted ms-auto">{{ dt('الرقم = عدد المشاكل تحت البند', 'Number = problems under the control') }}</span>
    </div>
    <div class="table-responsive">
        <table class="cctv-pivot">
            <thead><tr>
                <th>{{ dt('الفرع', 'Branch') }}</th>
                @foreach($controls as $c)<th><i class="cv-dot" @style(['--c: '.$colour[$c->id]])></i> {{ $c->name_ar }}</th>@endforeach
                <th>{{ dt('عدد المشاكل', 'Problems') }}</th>
                <th>{{ dt('البنود المخالفة', 'Controls failed') }}</th>
                <th>{{ dt('أسابيع سليمة', 'Clean weeks') }}</th>
                <th>{{ dt('التغيير', 'Change') }}</th>
            </tr></thead>
            <tbody>
            @foreach($matrix as $r)
                <tr>
                    <td class="name"><a href="{{ $url(['branch_id' => $r['branch_id']]) }}">{{ $r['name'] }}</a></td>
                    @foreach($controls as $c)
                        @php [$min, $max] = CctvReport::range($matrix, $c->id); $n = $r['cells'][$c->id] ?? null; @endphp
                        <td style="{{ CctvReport::heat($n, $min, $max) }}">@if($n)<a href="{{ $list(['branch_id' => $r['branch_id'], 'category_id' => $c->id]) }}">{{ $n }}</a>@endif</td>
                    @endforeach
                    @php [$min, $max] = CctvReport::range($matrix, null); @endphp
                    <td class="gt" style="{{ CctvReport::heat($r['total'], $min, $max) }}">{{ $r['total'] ?: '✓' }}</td>
                    <td class="num">{{ $r['hit'] }} {{ dt('من', 'of') }} {{ $controls->count() }} <small class="muted">({{ round($r['hit'] * 100 / max(1, $controls->count())) }}%)</small></td>
                    <td class="num">{{ $r['clean'] }} {{ dt('من', 'of') }} {{ $r['weeks'] }}</td>
                    <td>@include('dashboard.cctv.partials.delta', ['now' => $r['total'], 'prev' => $prevByBranch[$r['branch_id']] ?? 0])</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>{{ dt('الإجمالي', 'Total') }}</td>
                @foreach($controls as $c)<td>{{ $byCategory[$c->id] ?? 0 }}</td>@endforeach
                <td>{{ $total }}</td>
                <td colspan="2">{{ $compliance['clean'] }} {{ dt('سليمة من', 'clean of') }} {{ $compliance['reviewed'] }}</td>
                <td>@include('dashboard.cctv.partials.delta', ['now' => $total, 'prev' => $prevTotal])</td>
            </tr>
            </tbody>
        </table>
    </div>
</section>
@endif
</div>
@endsection

@push('head')
<style>
    .fin-controls { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .6rem; margin-bottom: 1rem; }
    .fin-dot { display: inline-block; inline-size: .55rem; block-size: .55rem; border-radius: 50%; margin-inline-end: .3rem; }
    .fin-dot.is-ok { background: #0ca30c; } .fin-dot.is-warn { background: #fab219; } .fin-dot.is-bad { background: #d03b3b; }
    .fin-rate-sub { display: flex; align-items: center; gap: .3rem; flex-wrap: wrap; font-size: .72rem; }
    .cv-stat-main .cv-delta { background: rgba(255,255,255,.18); color: #fff; }
    .fin-note { margin: 0; padding: .55rem 1rem; border-top: 1px solid var(--line); font-size: .76rem; color: var(--muted); }
    .fin-money { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
    .fin-money li { display: grid; grid-template-columns: 7rem 1fr auto; align-items: center; gap: .7rem; padding: .5rem 0; border-bottom: 1px solid var(--line); }
    .fin-money li:last-child { border-bottom: 0; }
    .fin-money-n { font-size: 1.1rem; color: var(--up); }
    .fin-money-body { display: flex; flex-direction: column; font-size: .88rem; }
    .fin-money-body small { color: var(--muted); font-size: .74rem; }
    .cv-weekcells small { font-size: .7rem; }
    @media (max-width: 991.98px) { .fin-controls { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
@endpush

@if($compliance['reviewed'] > 0)
@php
    $chartWeeks = collect($weeks)->map(fn ($w) => dt('الأسبوع ', 'Week ').$w['n'])->all();
    $chartSeries = $controls->filter(fn ($c) => collect($weeks)->sum(fn ($w) => $w['cells'][$c->id] ?? 0) > 0)->values()
        ->map(fn ($c) => ['label' => $c->name_ar, 'color' => $colour[$c->id], 'data' => collect($weeks)->map(fn ($w) => $w['cells'][$c->id] ?? 0)->all()])->all();
    $chartText = ['total' => dt('الإجمالي: ', 'Total: ')];
@endphp
@push('scripts')
<script>
(() => {
    if (! window.Chart) return;
    Chart.defaults.font = { family: getComputedStyle(document.body).fontFamily, size: 12 };
    Chart.defaults.color = '#52514e';
    const weeks = @json($chartWeeks), series = @json($chartSeries), text = @json($chartText);
    const topOf = i => { for (let d = series.length - 1; d >= 0; d--) if (series[d].data[i] > 0) return d; return -1; };
    const chart = new Chart(document.getElementById('cvWeeks'), {
        type: 'bar',
        data: { labels: weeks, datasets: series.map(s => ({
            label: s.label, data: s.data, backgroundColor: s.color, borderColor: '#fff', borderWidth: { top: 2 },
            borderSkipped: false, maxBarThickness: 64, categoryPercentage: .62,
            borderRadius: ctx => ctx.datasetIndex === topOf(ctx.dataIndex) ? { topLeft: 6, topRight: 6 } : 0,
        })) },
        options: {
            maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            scales: {
                x: { stacked: true, reverse: true, grid: { display: false }, border: { color: '#c3c2b7' }, ticks: { font: { weight: 600 } } },
                y: { stacked: true, position: 'right', beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e1e0d9' }, border: { display: false } },
            },
            plugins: {
                legend: { display: false },
                tooltip: { rtl: true, textDirection: 'rtl', backgroundColor: '#0b0b0b', padding: 10, cornerRadius: 6, boxPadding: 4,
                    filter: i => i.parsed.y > 0, callbacks: { footer: items => text.total + items.reduce((a, i) => a + i.parsed.y, 0) } },
            },
        },
    });
    const legend = document.getElementById('cvLegend');
    const focus = label => {
        chart.data.datasets.forEach((ds, i) => { ds.backgroundColor = ! label || ds.label === label ? series[i].color : series[i].color + '33'; });
        chart.update('none');
        legend.querySelectorAll('li').forEach(li => li.classList.toggle('is-dim', !! label && li.querySelector('.cv-legend-name').textContent !== label));
    };
    legend.querySelectorAll('li').forEach(li => {
        const label = li.querySelector('.cv-legend-name').textContent;
        ['mouseenter', 'focus'].forEach(e => li.addEventListener(e, () => focus(label)));
        ['mouseleave', 'blur'].forEach(e => li.addEventListener(e, () => focus(null)));
    });
})();
</script>
@endpush
@endif
