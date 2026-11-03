@extends('layouts.app')
@section('title', dt('ملخص مخالفات CCTV', 'CCTV violations summary'))
@include('dashboard.cctv.partials.style')
@push('head')<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>@endpush

@section('content')
@php
    use App\Support\CctvDashboard;
    use App\Support\CctvReport;

    [$from, $to] = $d->range();
    [$pFrom, $pTo, $pLabel] = $d->previousRange();
    $monthKey = $d->month->format('Y-m');
    $url = fn (array $q = []) => route('cctv.summary', array_filter(array_merge(
        ['branch_id' => $branch?->id, 'month' => $monthKey, 'week' => $d->week], $q
    ), fn ($v) => $v !== null && $v !== ''));
    $list = fn (array $q = []) => route('cctv.index', array_filter(array_merge(
        ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'branch_id' => $branch?->id], $q
    ), fn ($v) => $v !== null && $v !== ''));

    // Branch stepping: "all branches" first, then the branches in menu order; the arrows wrap around.
    $branchIds = array_merge([null], $branches->pluck('id')->all());
    $at = array_search($branch?->id, $branchIds, true);
    $prevBranch = $branchIds[($at - 1 + count($branchIds)) % count($branchIds)];
    $nextBranch = $branchIds[($at + 1) % count($branchIds)];

    // One validated categorical colour per violation type, fixed by the type's order (never by rank).
    $palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    $colour = [];
    foreach ($categories->values() as $i => $c) { $colour[$c->id] = $palette[$i % count($palette)]; }

    $total = array_sum($byCategory);
    $prevTotal = array_sum($prevByCategory);
    $periodLabel = $d->week
        ? dt('الأسبوع ', 'Week ').$d->week.' · '.$from->format('j').'–'.$to->format('j').' '.CctvDashboard::monthName($d->month)
        : CctvDashboard::monthName($d->month);
    $maxBranch = max(array_column($topBranches, 'n') ?: [1]);
    $maxViolation = max(array_column($topViolations, 'n') ?: [1]);
    $maxEmployee = max(array_column($topEmployees, 'n') ?: [1]);
    $peopleNamed = collect($byEmployee['branches'])->flatMap(fn ($b) => $b['people'])->filter(fn ($p) => $p['employee_id'])->count();
@endphp

<div class="cv">
{{-- 1. Where am I looking: branch, then month and week --}}
<section class="cv-bar" aria-label="{{ dt('الفلاتر', 'Filters') }}">
    <div class="cv-bar-row">
        <div class="cv-step" role="group" aria-label="{{ dt('الفرع', 'Branch') }}">
            <a class="cv-arrow" href="{{ $url(['branch_id' => $prevBranch]) }}" title="{{ dt('الفرع السابق', 'Previous branch') }}"><i class="bi bi-chevron-right"></i></a>
            <form method="GET" action="{{ route('cctv.summary') }}" class="m-0">
                <input type="hidden" name="month" value="{{ $monthKey }}">
                @if($d->week)<input type="hidden" name="week" value="{{ $d->week }}">@endif
                <select name="branch_id" class="cv-select" onchange="this.form.submit()" aria-label="{{ dt('الفرع', 'Branch') }}">
                    <option value="">{{ dt('كل الفروع', 'All branches') }}</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected($branch?->id === $b->id)>{{ CctvReport::storeName($b->branch_name) }}</option>@endforeach
                </select>
            </form>
            <a class="cv-arrow" href="{{ $url(['branch_id' => $nextBranch]) }}" title="{{ dt('الفرع التالي', 'Next branch') }}"><i class="bi bi-chevron-left"></i></a>
        </div>

        <div class="cv-step" role="group" aria-label="{{ dt('الشهر', 'Month') }}">
            <a class="cv-arrow" href="{{ $url(['month' => $d->month->copy()->subMonthNoOverflow()->format('Y-m'), 'week' => null]) }}" title="{{ dt('الشهر السابق', 'Previous month') }}"><i class="bi bi-chevron-right"></i></a>
            <span class="cv-month">{{ CctvDashboard::monthName($d->month) }}</span>
            <a class="cv-arrow" href="{{ $url(['month' => $d->month->copy()->addMonthNoOverflow()->format('Y-m'), 'week' => null]) }}" title="{{ dt('الشهر التالي', 'Next month') }}"><i class="bi bi-chevron-left"></i></a>
        </div>

        <div class="cv-actions d-print-none">
            @if($canRecord)<a href="{{ route('cctv.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> {{ dt('تسجيل مخالفة', 'Record a violation') }}</a>@endif
            <a href="{{ $list() }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-ul"></i> {{ dt('القائمة', 'List') }}</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()" title="{{ dt('طباعة', 'Print') }}"><i class="bi bi-printer"></i></button>
        </div>
    </div>

    <nav class="cv-weeks" aria-label="{{ dt('الأسبوع', 'Week') }}">
        <a href="{{ $url(['week' => null]) }}" @class(['on' => ! $d->week])>
            <b>{{ dt('الشهر كله', 'Whole month') }}</b><small>1–{{ $d->month->copy()->endOfMonth()->format('j') }}</small>
        </a>
        @foreach($weeks as $w)
            <a href="{{ $url(['week' => $w['n']]) }}" @class(['on' => $d->week === $w['n'], 'is-future' => $w['future']])>
                <b>{{ dt('الأسبوع', 'Week') }} {{ $w['n'] }}</b><small>{{ $w['from']->format('j') }}–{{ $w['to']->format('j') }} · {{ $w['total'] }}</small>
            </a>
        @endforeach
    </nav>
</section>

@if($total === 0)
    <div class="cv-empty">
        <i class="bi bi-camera-video-off"></i>
        <p class="mb-1 fw-semibold">{{ dt('مفيش مخالفات في', 'No violations in') }} {{ $periodLabel }}{{ $branch ? ' · '.CctvReport::storeName($branch->branch_name) : '' }}</p>
        <p class="text-muted small mb-0">{{ dt('جرّب شهر تاني بالأسهم، أو «كل الفروع».', 'Try another month with the arrows, or all branches.') }}</p>
    </div>
@else

{{-- 2. The headline and one box per violation type --}}
<section class="cv-head">
    <div class="cv-total">
        <span class="cv-total-label">{{ dt('إجمالي المخالفات', 'Total violations') }}</span>
        <span class="cv-total-n">{{ $total }}</span>
        <span class="cv-total-sub">
            @include('dashboard.cctv.partials.delta', ['now' => $total, 'prev' => $prevTotal])
            <span>{{ dt('عن', 'vs') }} {{ $pLabel }} ({{ $prevTotal }})</span>
        </span>
        <dl class="cv-total-facts">
            <div><dt>{{ dt('الفترة', 'Period') }}</dt><dd>{{ $periodLabel }}</dd></div>
            <div><dt>{{ dt('الفرع', 'Branch') }}</dt><dd>{{ $branch ? CctvReport::storeName($branch->branch_name) : dt('كل الفروع', 'All branches') }}</dd></div>
            <div><dt>{{ dt('فروع عليها مخالفات', 'Branches') }}</dt><dd>{{ count($byStore['rows']) }}</dd></div>
            <div><dt>{{ dt('موظفين عليهم مخالفات', 'Employees') }}</dt><dd>{{ $peopleNamed }}</dd></div>
        </dl>
    </div>

    <div class="cv-types">
        @foreach($categories as $c)
            @php $n = $byCategory[$c->id] ?? 0; $p = $prevByCategory[$c->id] ?? 0; @endphp
            @continue($c->slug === 'other')
            <a href="{{ $list(['category_id' => $c->id]) }}" class="cv-type" @style(['--c: '.$colour[$c->id]])>
                <span class="cv-type-name"><i class="cv-dot"></i>{{ $c->sheet_label }}</span>
                <span class="cv-type-n">{{ $n }}</span>
                <span class="cv-type-bar" aria-hidden="true"><i style="inline-size: {{ $total ? round($n * 100 / $total) : 0 }}%"></i></span>
                <span class="cv-type-foot">
                    <span>{{ $total ? round($n * 100 / $total) : 0 }}% {{ dt('من الإجمالي', 'of total') }}</span>
                    @include('dashboard.cctv.partials.delta', ['now' => $n, 'prev' => $p])
                </span>
            </a>
        @endforeach
    </div>
</section>

{{-- 3. Who: the branches (or, for one branch, the violations) and the employees with the most --}}
<section class="cv-grid">
    <div class="cv-panel">
        @if($branch)
            <h2 class="cv-h">{{ dt('أكتر 5 مخالفات في', 'Top 5 violations at') }} {{ CctvReport::storeName($branch->branch_name) }}</h2>
            <ol class="cv-rank">
                @foreach($topViolations as $i => $v)
                    <li>
                        <span class="cv-rank-i">{{ $i + 1 }}</span>
                        <span class="cv-rank-body">
                            <span class="cv-rank-name">{{ $v['label'] }}</span>
                            <span class="cv-rank-bar"><i style="inline-size: {{ round($v['n'] * 100 / $maxViolation) }}%"></i></span>
                        </span>
                        <span class="cv-rank-n">{{ $v['n'] }}</span>
                        @include('dashboard.cctv.partials.delta', ['now' => $v['n'], 'prev' => $v['prev']])
                    </li>
                @endforeach
            </ol>
        @else
            <h2 class="cv-h">{{ dt('أكتر 5 فروع مخالفات', 'Top 5 branches') }}</h2>
            <ol class="cv-rank">
                @foreach($topBranches as $i => $b)
                    <li>
                        <span class="cv-rank-i">{{ $i + 1 }}</span>
                        <a class="cv-rank-body" href="{{ $url(['branch_id' => $b['id']]) }}" title="{{ dt('افتح ملخص الفرع', 'Open the branch summary') }}">
                            <span class="cv-rank-name">{{ $b['name'] }}</span>
                            <span class="cv-rank-bar"><i style="inline-size: {{ round($b['n'] * 100 / $maxBranch) }}%"></i></span>
                        </a>
                        <span class="cv-rank-n">{{ $b['n'] }} <small>{{ round($b['n'] * 100 / $total) }}%</small></span>
                        @include('dashboard.cctv.partials.delta', ['now' => $b['n'], 'prev' => $b['prev']])
                    </li>
                @endforeach
            </ol>
            @if($topViolations)
                <h3 class="cv-h cv-h-sm">{{ dt('أكتر المخالفات تكرارًا', 'Most repeated violations') }}</h3>
                <ul class="cv-chips">
                    @foreach($topViolations as $v)<li>{{ $v['label'] }} <b>{{ $v['n'] }}</b></li>@endforeach
                </ul>
            @endif
        @endif
    </div>

    <div class="cv-panel">
        <h2 class="cv-h">{{ dt('أكتر 5 موظفين مخالفات', 'Top 5 employees') }}</h2>
        @if($topEmployees)
        <ol class="cv-rank cv-rank-people">
            @foreach($topEmployees as $i => $e)
                <li>
                    <span class="cv-rank-i">{{ $i + 1 }}</span>
                    <a class="cv-rank-body" href="{{ $list(['employee_id' => $e['id'], 'branch_id' => $e['branch_id']]) }}">
                        <span class="cv-rank-name">{{ $e['name'] }} <small>{{ $e['branch'] }}</small></span>
                        <span class="cv-rank-bar"><i style="inline-size: {{ round($e['n'] * 100 / $maxEmployee) }}%"></i></span>
                        @if($e['top_n'] > 1)<span class="cv-rank-note"><i class="bi bi-arrow-repeat"></i> {{ $e['top'] }} × {{ $e['top_n'] }}</span>@endif
                    </a>
                    <span class="cv-rank-n">{{ $e['n'] }}</span>
                    @include('dashboard.cctv.partials.delta', ['now' => $e['n'], 'prev' => $e['prev']])
                </li>
            @endforeach
        </ol>
        @else
            <p class="text-muted small">{{ dt('المخالفات في الفترة دي من غير أسماء موظفين.', 'No named employees in this period.') }}</p>
        @endif
    </div>
</section>

{{-- 4. When: week by week (the types as a coloured list beside the chart), then day by day --}}
@php
    $monthByType = $categories->map(fn ($c) => ['id' => $c->id, 'label' => $c->sheet_label, 'color' => $colour[$c->id],
        'n' => collect($weeks)->sum(fn ($w) => $w['cells'][$c->id] ?? 0)])->filter(fn ($r) => $r['n'] > 0)->sortByDesc('n')->values();
    $monthSum = max(1, $monthByType->sum('n'));
@endphp
<section class="cv-panel cv-weekbox">
    <h2 class="cv-h">{{ dt('أسبوع بأسبوع', 'Week by week') }} · {{ CctvDashboard::monthName($d->month) }}</h2>
    <div class="cv-weekbox-body">
        <div class="cv-weekbox-side">
            <ul class="cv-legend" id="cvLegend">
                @foreach($monthByType as $r)
                    <li data-type="{{ $r['id'] }}" tabindex="0">
                        <i class="cv-dot" @style(['--c: '.$r['color']])></i>
                        <span class="cv-legend-name">{{ $r['label'] }}</span>
                        <b>{{ $r['n'] }}</b>
                        <span class="cv-legend-pct">{{ round($r['n'] * 100 / $monthSum) }}%</span>
                        <span class="cv-legend-bar" aria-hidden="true"><i @style(['inline-size: '.round($r['n'] * 100 / $monthByType->max('n')).'%', 'background: '.$r['color']])></i></span>
                    </li>
                @endforeach
            </ul>
            <div class="cv-weekcells">
                @foreach($weeks as $w)
                    <a href="{{ $url(['week' => $w['n']]) }}" @class(['on' => $d->week === $w['n'], 'is-future' => $w['future']])>
                        <span>{{ dt('الأسبوع', 'Week') }} {{ $w['n'] }}</span>
                        <b>{{ $w['total'] }}</b>
                        @if($w['future'])<span class="cv-delta is-flat">{{ dt('لسه', 'not yet') }}</span>@else @include('dashboard.cctv.partials.delta', ['now' => $w['total'], 'prev' => $w['prev']]) @endif
                    </a>
                @endforeach
            </div>
        </div>
        <div class="cv-weekbox-chart"><canvas id="cvWeeks" role="img" aria-label="{{ dt('المخالفات في كل أسبوع حسب النوع', 'Violations per week by type') }}"></canvas></div>
    </div>
</section>

<section class="cv-panel">
    <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2">
        <h2 class="cv-h mb-0">{{ dt('يوم بيوم', 'Day by day') }} · {{ $periodLabel }}</h2>
        @php $peak = collect($days)->sortDesc()->keys()->first(); @endphp
        <span class="small text-muted ms-auto">{{ dt('أعلى يوم:', 'Peak day:') }} <b class="text-dark">{{ \Illuminate\Support\Carbon::parse($peak)->format('j/n') }}</b> ({{ $days[$peak] }} {{ dt('مخالفة', 'violations') }})</span>
    </div>
    <div class="cv-chart cv-chart-days"><canvas id="cvDays" role="img" aria-label="{{ dt('عدد المخالفات في كل يوم', 'Violations per day') }}"></canvas></div>
</section>

{{-- 5. The sheet's pivots, now with share and change --}}
<section class="cv-panel p-0">
    <ul class="nav nav-tabs cv-tabs d-print-none" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#by-store" type="button">{{ dt('بالفرع', 'By store') }} <span>By Store</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#by-emp" type="button">{{ dt('بالموظف', 'By employee') }} <span>By EMP</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#by-week" type="button">{{ dt('مقارنة الأسابيع', 'Weeks') }}</button></li>
    </ul>
    <div class="tab-content p-2">
        <div class="tab-pane fade show active" id="by-store">
            @php $stores = $byStore['rows']; @endphp
            <div class="table-responsive">
                <table class="cctv-pivot">
                    <thead><tr>
                        <th>Store Name</th>
                        @foreach($byStore['columns'] as $c)<th><i class="cv-dot" @style(['--c: '.$colour[$c->id]])></i> {{ $c->sheet_label }}</th>@endforeach
                        <th>Grand Total</th><th>{{ dt('النسبة', 'Share') }}</th><th>{{ $pLabel }}</th><th>{{ dt('التغيير', 'Change') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($stores as $r)
                        <tr>
                            <td class="name"><a href="{{ $url(['branch_id' => $r['branch_id']]) }}">{{ $r['name'] }}</a></td>
                            @foreach($byStore['columns'] as $c)
                                @php [$min, $max] = CctvReport::range($stores, $c->id); $n = $r['cells'][$c->id] ?? null; @endphp
                                <td style="{{ CctvReport::heat($n, $min, $max) }}">@if($n)<a href="{{ $list(['branch_id' => $r['branch_id'], 'category_id' => $c->id]) }}">{{ $n }}</a>@endif</td>
                            @endforeach
                            @php [$min, $max] = CctvReport::range($stores, null); $p = $prevByBranch[$r['branch_id']] ?? 0; @endphp
                            <td class="gt" style="{{ CctvReport::heat($r['total'], $min, $max) }}">{{ $r['total'] }}</td>
                            <td class="num">{{ round($r['total'] * 100 / $total) }}%</td>
                            <td class="num muted">{{ $p ?: '' }}</td>
                            <td>@include('dashboard.cctv.partials.delta', ['now' => $r['total'], 'prev' => $p])</td>
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td>Grand Total</td>
                        @foreach($byStore['columns'] as $c)<td>{{ $byStore['totals'][$c->id] ?? 0 }}</td>@endforeach
                        <td>{{ $byStore['total'] }}</td><td>100%</td><td>{{ $prevTotal }}</td>
                        <td>@include('dashboard.cctv.partials.delta', ['now' => $total, 'prev' => $prevTotal])</td>
                    </tr>
                    <tr class="share">
                        <td>{{ dt('النسبة', 'Share') }}</td>
                        @foreach($byStore['columns'] as $c)<td>{{ round(($byStore['totals'][$c->id] ?? 0) * 100 / $total) }}%</td>@endforeach
                        <td colspan="4"></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="by-emp">
            @php $people = collect($byEmployee['branches'])->flatMap(fn ($b) => $b['people'])->all(); @endphp
            <div class="table-responsive">
                <table class="cctv-pivot">
                    <thead><tr>
                        <th>{{ dt('الفرع / الموظف', 'Branch / employee') }}</th>
                        @foreach($byEmployee['columns'] as $c)<th><i class="cv-dot" @style(['--c: '.$colour[$c->id]])></i> {{ $c->sheet_label }}</th>@endforeach
                        <th>Grand Total</th><th>{{ dt('النسبة', 'Share') }}</th><th>{{ dt('التغيير', 'Change') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($byEmployee['branches'] as $b)
                        <tr class="branch">
                            <td class="name"><a href="{{ $url(['branch_id' => $b['branch_id']]) }}">{{ $b['name'] }}</a></td>
                            @foreach($byEmployee['columns'] as $c)<td>{{ $b['cells'][$c->id] ?? '' }}</td>@endforeach
                            <td class="gt">{{ $b['total'] }}</td>
                            <td class="num">{{ round($b['total'] * 100 / $total) }}%</td>
                            <td>@include('dashboard.cctv.partials.delta', ['now' => $b['total'], 'prev' => $prevByBranch[$b['branch_id']] ?? 0])</td>
                        </tr>
                        @foreach($b['people'] as $p)
                            <tr class="person">
                                <td class="name"><a href="{{ $list(['branch_id' => $b['branch_id'], 'employee_id' => $p['employee_id'] ?? 'none']) }}">{!! $p['employee_id'] ? e($p['name']) : '<span class="muted">'.e($p['name']).'</span>' !!}</a></td>
                                @foreach($byEmployee['columns'] as $c)
                                    @php [$min, $max] = CctvReport::range($people, $c->id); $n = $p['cells'][$c->id] ?? null; @endphp
                                    <td style="{{ CctvReport::heat($n, $min, $max) }}">{{ $n }}</td>
                                @endforeach
                                @php [$min, $max] = CctvReport::range($people, null); @endphp
                                <td class="gt" style="{{ CctvReport::heat($p['total'], $min, $max) }}">{{ $p['total'] }}</td>
                                <td class="num">{{ round($p['total'] * 100 / $total) }}%</td>
                                <td>@if($p['employee_id'])@include('dashboard.cctv.partials.delta', ['now' => $p['total'], 'prev' => $prevByEmployee[$p['employee_id']] ?? 0])@endif</td>
                            </tr>
                        @endforeach
                    @endforeach
                    <tr class="total">
                        <td>Grand Total</td>
                        @foreach($byEmployee['columns'] as $c)<td>{{ $byEmployee['totals'][$c->id] ?? 0 }}</td>@endforeach
                        <td>{{ $byEmployee['total'] }}</td><td>100%</td>
                        <td>@include('dashboard.cctv.partials.delta', ['now' => $total, 'prev' => $prevTotal])</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="by-week">
            <div class="table-responsive">
                <table class="cctv-pivot">
                    <thead><tr>
                        <th>{{ dt('الأسبوع', 'Week') }}</th>
                        @foreach($categories as $c)<th><i class="cv-dot" @style(['--c: '.$colour[$c->id]])></i> {{ $c->sheet_label }}</th>@endforeach
                        <th>Grand Total</th><th>{{ dt('النسبة من الشهر', 'Share of month') }}</th><th>{{ dt('عن الأسبوع اللي قبله', 'vs week before') }}</th><th>{{ dt('الاتجاه', 'Trend') }}</th>
                    </tr></thead>
                    <tbody>
                    @php $monthTotal = array_sum(array_column($weeks, 'total')); @endphp
                    @foreach($weeks as $w)
                        @php $pct = CctvDashboard::change($w['total'], $w['prev']); @endphp
                        <tr @class(['is-future' => $w['future']])>
                            <td class="name"><a href="{{ $url(['week' => $w['n']]) }}">{{ dt('الأسبوع', 'Week') }} {{ $w['n'] }}</a> <small class="muted">{{ $w['from']->format('j') }}–{{ $w['to']->format('j/n') }}</small></td>
                            @foreach($categories as $c)
                                @php $vals = array_map(fn ($x) => ['cells' => $x['cells'], 'total' => $x['total']], $weeks); [$min, $max] = CctvReport::range($vals, $c->id); $n = $w['cells'][$c->id] ?? null; @endphp
                                <td style="{{ CctvReport::heat($n, $min, $max) }}">{{ $n }}</td>
                            @endforeach
                            <td class="gt">{{ $w['total'] }}</td>
                            <td class="num">{{ $monthTotal ? round($w['total'] * 100 / $monthTotal) : 0 }}%</td>
                            <td class="num muted">{{ $w['prev'] }} → {{ $w['total'] }}</td>
                            <td>
                                @if($w['future'])<span class="cv-delta is-flat">{{ dt('لسه', 'not yet') }}</span>
                                @elseif($pct !== null && $pct < 0)<span class="cv-delta is-down"><i class="bi bi-graph-down-arrow"></i> {{ dt('بتقل', 'falling') }} {{ abs($pct) }}%</span>
                                @elseif($pct !== null && $pct > 0)<span class="cv-delta is-up"><i class="bi bi-graph-up-arrow"></i> {{ dt('بتزيد', 'rising') }} {{ $pct }}%</span>
                                @else @include('dashboard.cctv.partials.delta', ['now' => $w['total'], 'prev' => $w['prev']]) @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">{{ dt('الأسبوع 1 = من 1 لـ 7، و2 = من 8 لـ 14، و3 = من 15 لـ 21، و4 = من 22 لآخر الشهر. الأسبوع الأول بيتقارن بالأسبوع الرابع من الشهر اللي قبله.', 'Week 1 = days 1-7, 2 = 8-14, 3 = 15-21, 4 = 22 to month end. Week 1 is compared with week 4 of the month before.') }}</p>
        </div>
    </div>
</section>
@endif
</div>
@endsection

@if($total > 0)
@php
    $chartWeeks = collect($weeks)->map(fn ($w) => dt('الأسبوع ', 'Week ').$w['n'])->all();
    $chartSeries = $categories->filter(fn ($c) => collect($weeks)->sum(fn ($w) => $w['cells'][$c->id] ?? 0) > 0)->values()
        ->map(fn ($c) => ['label' => $c->sheet_label, 'color' => $colour[$c->id], 'data' => collect($weeks)->map(fn ($w) => $w['cells'][$c->id] ?? 0)->all()])->all();
    $chartDays = array_keys($days);
    $chartDayCounts = array_values($days);
    $chartText = ['total' => dt('الإجمالي: ', 'Total: '), 'violations' => dt('المخالفات', 'Violations')];
@endphp
@push('scripts')
<script>
(() => {
    if (! window.Chart) return;
    const ink = '#52514e', grid = '#e1e0d9', font = { family: getComputedStyle(document.body).fontFamily, size: 12 };
    Chart.defaults.font = font;
    Chart.defaults.color = ink;
    const tooltip = { rtl: true, textDirection: 'rtl', backgroundColor: '#0b0b0b', padding: 10, cornerRadius: 6, boxPadding: 4 };

    const weeks = @json($chartWeeks);
    const series = @json($chartSeries);
    const text = @json($chartText);

    // Only the top segment of each stack gets rounded corners; the segments below stay square.
    const topOf = i => { for (let d = series.length - 1; d >= 0; d--) if (series[d].data[i] > 0) return d; return -1; };
    const weekChart = new Chart(document.getElementById('cvWeeks'), {
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
                y: { stacked: true, position: 'right', beginAtZero: true, ticks: { precision: 0 }, grid: { color: grid }, border: { display: false } },
            },
            plugins: {
                legend: { display: false },
                tooltip: { ...tooltip, filter: i => i.parsed.y > 0, callbacks: { footer: items => text.total + items.reduce((a, i) => a + i.parsed.y, 0) } },
            },
        },
    });

    // The list beside the chart: pointing at a type fades the others.
    const legend = document.getElementById('cvLegend');
    const focus = label => {
        weekChart.data.datasets.forEach((ds, i) => { ds.backgroundColor = ! label || ds.label === label ? series[i].color : series[i].color + '33'; });
        weekChart.update('none');
        legend.querySelectorAll('li').forEach(li => li.classList.toggle('is-dim', !! label && li.querySelector('.cv-legend-name').textContent !== label));
    };
    legend.querySelectorAll('li').forEach(li => {
        const label = li.querySelector('.cv-legend-name').textContent;
        li.addEventListener('mouseenter', () => focus(label));
        li.addEventListener('focus', () => focus(label));
        li.addEventListener('mouseleave', () => focus(null));
        li.addEventListener('blur', () => focus(null));
    });

    const days = @json($chartDays);
    new Chart(document.getElementById('cvDays'), {
        type: 'bar',
        data: { labels: days.map(d => Number(d.slice(8))), datasets: [{
            label: text.violations, data: @json($chartDayCounts),
            backgroundColor: '#2a78d6', hoverBackgroundColor: '#184f95', borderRadius: 4, maxBarThickness: 22,
        }] },
        options: {
            maintainAspectRatio: false,
            scales: {
                x: { reverse: true, grid: { display: false }, border: { color: '#c3c2b7' }, ticks: { autoSkip: true, maxRotation: 0 } },
                y: { position: 'right', beginAtZero: true, ticks: { precision: 0 }, grid: { color: grid }, border: { display: false } },
            },
            plugins: {
                legend: { display: false },
                tooltip: { ...tooltip, callbacks: { title: items => days[items[0].dataIndex].split('-').reverse().join('/') } },
            },
        },
    });
})();
</script>
@endpush
@endif
