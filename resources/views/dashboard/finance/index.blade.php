@extends('layouts.app')
@section('title', dt('قائمة المشاكل المالية', 'Finance problems'))
@include('dashboard.cctv.partials.style')
@include('dashboard.cctv.partials.list-style')

@section('content')
@php
    use App\Support\CctvDashboard;
    use App\Support\CctvReport;
    use App\Support\FinanceReport;
    use App\Support\ObservationLabels;

    $q = request()->query();
    $with = fn (array $changes) => route('finance.index', array_filter(array_merge($q, $changes, ['page' => null]), fn ($v) => $v !== null && $v !== ''));
    $navMonth = $month ?? FinanceReport::defaultMonth(auth()->user());
    $exact = request('from') || request('to');

    $palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    $colour = [];
    foreach ($categories->values() as $i => $c) { $colour[$c->id] = $palette[$i % count($palette)]; }
    $egp = fn ($v) => number_format((float) $v, 0).' '.dt('ج', 'EGP');

    $chips = [];
    if ($exact) { $chips[] = [($from?->format('j/n/Y') ?? '…').' – '.($to?->format('j/n/Y') ?? '…'), ['from' => null, 'to' => null]]; }
    if (request('branch_id')) { $chips[] = [CctvReport::storeName($branches->firstWhere('id', (int) request('branch_id'))?->branch_name), ['branch_id' => null]]; }
    if (request('category_id')) { $chips[] = [$categories->firstWhere('id', (int) request('category_id'))?->name_ar, ['category_id' => null, 'catalog_item_id' => null]]; }
    if (request('catalog_item_id')) { $chips[] = [$items->firstWhere('id', (int) request('catalog_item_id'))?->label_ar, ['catalog_item_id' => null]]; }
    if (request('status')) { $chips[] = [request('status') === 'active' ? dt('لسه مفتوحة', 'Still open') : ObservationLabels::status(request('status')), ['status' => null]]; }
    if (request('lp')) { $chips[] = [request('lp') === 'yes' ? dt('اتحولت لمنع الخسائر', 'Sent to loss prevention') : dt('ماتحولتش لمنع الخسائر', 'Not sent to loss prevention'), ['lp' => null]]; }
    if (request('department_id')) { $chips[] = [dt('مسئولية', 'To').' '.$departments->firstWhere('id', (int) request('department_id'))?->name, ['department_id' => null]]; }
    if (request('amount')) { $chips[] = [dt('عليها مبلغ', 'Has an amount'), ['amount' => null]]; }
    if (request('q')) { $chips[] = ['«'.request('q').'»', ['q' => null]]; }
    $total = $stats['total'];
@endphp

<div class="cv">
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="fw-bold mb-0">{{ dt('قائمة المشاكل المالية', 'Finance problems') }}</h4>
    <div class="ms-auto d-flex gap-2 d-print-none">
        @if($canRecord)<a href="{{ route('finance.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-clipboard-check"></i> {{ dt('مراجعة الفروع', 'Review branches') }}</a>@endif
        <a href="{{ route('finance.export', $q) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> {{ dt('تنزيل Excel', 'Download Excel') }} ({{ $total }})</a>
    </div>
</div>

<form method="GET" action="{{ route('finance.index') }}" class="cv-bar cv-filters" id="cvFilters">
    <div class="cv-bar-row">
        <div class="cv-step" role="group" aria-label="{{ dt('الشهر', 'Month') }}">
            <a class="cv-arrow" href="{{ $with(['month' => $navMonth->copy()->subMonthNoOverflow()->format('Y-m'), 'week' => null, 'from' => null, 'to' => null]) }}" title="{{ dt('الشهر السابق', 'Previous month') }}"><i class="bi bi-chevron-right"></i></a>
            <a href="{{ $with(['month' => $navMonth->format('Y-m'), 'week' => null, 'from' => null, 'to' => null]) }}" class="cv-month {{ $month ? '' : 'text-muted' }}">{{ CctvDashboard::monthName($navMonth) }}</a>
            <a class="cv-arrow" href="{{ $with(['month' => $navMonth->copy()->addMonthNoOverflow()->format('Y-m'), 'week' => null, 'from' => null, 'to' => null]) }}" title="{{ dt('الشهر التالي', 'Next month') }}"><i class="bi bi-chevron-left"></i></a>
        </div>
        <nav class="cv-weeks cv-weeks-inline">
            <a href="{{ $with(['month' => null, 'week' => null, 'from' => null, 'to' => null]) }}" @class(['on' => ! $month && ! $exact])><b>{{ dt('كل التواريخ', 'All dates') }}</b></a>
            @if($month)
                <a href="{{ $with(['week' => null, 'from' => null, 'to' => null]) }}" @class(['on' => ! $week && ! $exact])><b>{{ dt('الشهر كله', 'Whole month') }}</b></a>
                @for($n = 1; $n <= CctvDashboard::WEEKS; $n++)
                    @php [$wf, $wt] = CctvDashboard::weekRange($month, $n); @endphp
                    <a href="{{ $with(['week' => $n, 'from' => null, 'to' => null]) }}" @class(['on' => $week === $n && ! $exact])><b>{{ dt('أسبوع', 'Week') }} {{ $n }}</b><small>{{ $wf->format('j') }}–{{ $wt->format('j') }}</small></a>
                @endfor
            @endif
        </nav>
        <details class="cv-range" @if($exact) open @endif>
            <summary>{{ dt('تاريخ محدد', 'Exact dates') }}</summary>
            <div class="d-flex gap-2 align-items-end mt-2">
                <label class="small">{{ dt('من', 'From') }}<input type="date" name="from" value="{{ $exact ? $from?->toDateString() : '' }}" class="form-control form-control-sm"></label>
                <label class="small">{{ dt('إلى', 'To') }}<input type="date" name="to" value="{{ $exact ? $to?->toDateString() : '' }}" class="form-control form-control-sm"></label>
                <button class="btn btn-sm btn-outline-primary">{{ dt('عرض', 'Show') }}</button>
            </div>
        </details>
    </div>
    @if($month && ! $exact)<input type="hidden" name="month" value="{{ $month->format('Y-m') }}">@endif
    @if($week && ! $exact)<input type="hidden" name="week" value="{{ $week }}">@endif

    <div class="cv-fields">
        <label>{{ dt('الفرع', 'Branch') }}
            <select name="branch_id" class="form-select form-select-sm js-auto">
                <option value="">{{ dt('كل الفروع', 'All branches') }}</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) request('branch_id') === (string) $b->id)>{{ CctvReport::storeName($b->branch_name) }}</option>@endforeach
            </select>
        </label>
        <label>{{ dt('البند', 'Control') }}
            <select name="category_id" class="form-select form-select-sm js-auto">
                <option value="">{{ dt('كل البنود', 'All controls') }}</option>
                @foreach($categories as $c)<option value="{{ $c->id }}" @selected((string) request('category_id') === (string) $c->id)>{{ $c->name_ar }}</option>@endforeach
            </select>
        </label>
        <label>{{ dt('المشكلة', 'Problem') }}
            <select name="catalog_item_id" class="form-select form-select-sm js-auto">
                <option value="">{{ dt('كل المشاكل', 'All problems') }}</option>
                @foreach($categories as $c)
                    <optgroup label="{{ $c->name_ar }}" data-cat="{{ $c->id }}">
                        @foreach($items->where('category_id', $c->id) as $i)<option value="{{ $i->id }}" data-cat="{{ $c->id }}" @selected((string) request('catalog_item_id') === (string) $i->id)>{{ $i->label_ar }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select>
            <small class="cv-field-note d-none" id="cvNoItems">{{ dt('البند ده مالوش مشاكل محددة', 'No listed problems for this control') }}</small>
        </label>
        <label>{{ dt('الحالة', 'Status') }}
            <select name="status" class="form-select form-select-sm js-auto">
                <option value="">{{ dt('كل الحالات', 'All') }}</option>
                <option value="active" @selected(request('status') === 'active')>{{ dt('لسه مفتوحة', 'Still open') }}</option>
                @foreach($statuses as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ObservationLabels::status($s) }}</option>@endforeach
            </select>
        </label>
        <label>{{ dt('الإدارة المسئولة', 'Responsible') }}
            <select name="department_id" class="form-select form-select-sm js-auto">
                <option value="">{{ dt('كل الإدارات', 'All departments') }}</option>
                @foreach($departments as $d)<option value="{{ $d->id }}" @selected((string) request('department_id') === (string) $d->id)>{{ $d->name }}</option>@endforeach
            </select>
        </label>
        <label>{{ dt('منع الخسائر', 'Loss prevention') }}
            <select name="lp" class="form-select form-select-sm js-auto">
                <option value="">{{ dt('الكل', 'All') }}</option>
                <option value="yes" @selected(request('lp') === 'yes')>{{ dt('اتحولت', 'Sent') }}</option>
                <option value="no" @selected(request('lp') === 'no')>{{ dt('ماتحولتش', 'Not sent') }}</option>
            </select>
        </label>
        <label>{{ dt('المبلغ', 'Amount') }}
            <select name="amount" class="form-select form-select-sm js-auto">
                <option value="">{{ dt('الكل', 'All') }}</option>
                <option value="yes" @selected(request('amount') === 'yes')>{{ dt('عليها مبلغ', 'Has an amount') }}</option>
            </select>
        </label>
        <label class="cv-field-wide">{{ dt('بحث', 'Search') }}
            <span class="cv-search"><i class="bi bi-search"></i><input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="{{ dt('نص المشكلة أو رقم المرجع', 'Text or reference') }}"></span>
        </label>
        <label>{{ dt('الترتيب', 'Sort') }}
            <select name="sort" class="form-select form-select-sm js-auto">
                @foreach(['newest' => dt('الأحدث', 'Newest'), 'oldest' => dt('الأقدم', 'Oldest'), 'branch' => dt('الفرع', 'Branch'), 'amount' => dt('المبلغ', 'Amount'), 'type' => dt('البند', 'Control')] as $k => $label)
                    <option value="{{ $k }}" @selected(request('sort', 'newest') === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <input type="hidden" name="per_page" value="{{ $perPage }}">
    </div>

    @if($chips)
        <div class="cv-chips-row">
            @foreach($chips as [$label, $clear])<a href="{{ $with($clear) }}" class="cv-chip" title="{{ dt('شيل الفلتر', 'Remove filter') }}">{{ $label }} <i class="bi bi-x"></i></a>@endforeach
            <a href="{{ route('finance.index', array_filter(['month' => request('month'), 'week' => request('week')])) }}" class="cv-chip-clear">{{ dt('شيل كل الفلاتر', 'Clear all') }}</a>
        </div>
    @endif
</form>

<section class="cv-sum">
    <div class="cv-sum-stats">
        <div class="cv-stat cv-stat-main"><span>{{ dt('عدد المشاكل', 'Problems') }}</span><b>{{ $total }}</b></div>
        <div class="cv-stat"><span>{{ dt('نسبة الالتزام', 'Compliance') }}</span><b>{{ $stats['compliance']['rate'] !== null ? $stats['compliance']['rate'].'%' : '—' }}</b></div>
        <div class="cv-stat"><span>{{ dt('مراجعات سليمة', 'Clean reviews') }}</span><b>{{ $stats['compliance']['clean'] }} <small>{{ dt('من', 'of') }} {{ $stats['compliance']['reviewed'] }}</small></b></div>
        <div class="cv-stat"><span>{{ dt('فروع عليها مشاكل', 'Branches') }}</span><b>{{ $stats['branches'] }}</b></div>
        <a class="cv-stat" href="{{ $with(['status' => 'active']) }}"><span>{{ dt('لسه مفتوحة', 'Still open') }}</span><b>{{ $stats['active'] }}</b><i class="bi bi-chevron-left"></i></a>
        <a class="cv-stat" href="{{ $with(['amount' => 'yes', 'sort' => 'amount']) }}"><span>{{ dt('المبالغ', 'Amounts') }}</span><b>{{ $egp($stats['money']['amount']) }}</b><i class="bi bi-chevron-left"></i></a>
        <a class="cv-stat" href="{{ $with(['lp' => 'yes']) }}"><span>{{ dt('لمنع الخسائر', 'To loss prevention') }}</span><b>{{ $stats['money']['lp'] }}</b><i class="bi bi-chevron-left"></i></a>
    </div>
    @if($total)
        <div class="cv-split">
            <div class="cv-split-bar" role="img" aria-label="{{ dt('توزيع المشاكل حسب البند', 'Split by control') }}">
                @foreach($categories as $c)
                    @php $n = $stats['by_category'][$c->id] ?? 0; @endphp
                    @if($n)<a href="{{ $with(['category_id' => $c->id, 'catalog_item_id' => null]) }}" style="flex: {{ $n }}; background: {{ $colour[$c->id] }}" title="{{ $c->name_ar }}: {{ $n }}"></a>@endif
                @endforeach
            </div>
            <ul class="cv-split-legend">
                @foreach($categories->sortByDesc(fn ($c) => $stats['by_category'][$c->id] ?? 0) as $c)
                    @php $n = $stats['by_category'][$c->id] ?? 0; @endphp
                    @if($n)<li><a href="{{ $with(['category_id' => $c->id, 'catalog_item_id' => null]) }}" @class(['on' => (string) request('category_id') === (string) $c->id])><i class="cv-dot" @style(['--c: '.$colour[$c->id]])></i><span>{{ $c->name_ar }}</span><b>{{ $n }}</b><small>{{ round($n * 100 / $total) }}%</small></a></li>@endif
                @endforeach
            </ul>
        </div>
    @endif
</section>

<div class="cv-table-wrap">
    <table class="cv-table">
        <thead><tr>
            <th>{{ dt('التاريخ', 'Date') }}</th>
            <th>{{ dt('الفرع', 'Branch') }}</th>
            <th>{{ dt('المشكلة', 'Problem') }}</th>
            <th>{{ dt('المبلغ', 'Amount') }}</th>
            <th>{{ dt('الإجراء', 'Action') }}</th>
            <th>{{ dt('الإدارة المسئولة', 'Responsible') }}</th>
            <th>{{ dt('الحالة', 'Status') }}</th>
            <th class="text-end">{{ dt('المرجع', 'Ref.') }}</th>
        </tr></thead>
        <tbody>
        @forelse($violations as $o)
            @php $x = (array) ($o->extra_values ?? []); @endphp
            <tr>
                <td class="cv-td-date"><b>{{ $o->event_date?->format('j/n') }}</b><small>{{ dt('أسبوع', 'week') }} {{ $o->event_date ? FinanceReport::weekOfMonth($o->event_date) : '' }} · {{ $o->event_date?->format('Y') }}</small></td>
                <td class="text-nowrap"><a href="{{ $with(['branch_id' => $o->branch_id]) }}" class="text-reset">{{ CctvReport::storeName($o->branch?->branch_name) }}</a></td>
                <td class="cv-td-vio">
                    <span class="cv-td-type"><i class="cv-dot" @style(['--c: '.($colour[$o->category_id] ?? '#898781')])></i>{{ $o->category?->name_ar }}</span>
                    <b>{{ $o->catalogItem?->label_ar ?? $o->title }}</b>
                    @if($o->description !== $o->catalogItem?->label_ar)<span class="cv-td-desc">{{ $o->description }}</span>@endif
                </td>
                <td class="text-nowrap">@if(isset($x['amount']))<b class="fin-amount">{{ $egp($x['amount']) }}</b>@endif</td>
                <td class="cv-td-reply">
                    @if(FinanceReport::isLossPrevention($o))<span class="fin-lp-tag"><i class="bi bi-shield-exclamation"></i> {{ dt('منع الخسائر', 'Loss prevention') }}</span>@endif
                    {{ $x['action'] ?? '' }}
                </td>
                <td class="text-nowrap small">@if($o->responsibleDepartment)<a href="{{ $with(['department_id' => $o->responsible_department_id]) }}" class="text-reset">{{ $o->responsibleDepartment->name }}</a>@endif</td>
                <td><span class="badge rounded-pill text-bg-{{ ObservationLabels::statusColor($o->status) }}">{{ ObservationLabels::status($o->status) }}</span></td>
                <td class="text-end"><a href="{{ route('observations.show', $o) }}" class="cv-ref">{{ $o->reference }}</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="cv-td-empty">
                <i class="bi bi-funnel"></i>
                <p class="mb-1 fw-semibold">{{ dt('مفيش مشاكل بالفلاتر دي.', 'No problems match these filters.') }}</p>
                @if($chips)<a href="{{ route('finance.index') }}" class="small">{{ dt('شيل كل الفلاتر', 'Clear all filters') }}</a>@endif
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="cv-pager">
    <span class="text-muted small">@if($violations->total()){{ dt('عرض', 'Showing') }} {{ $violations->firstItem() }}–{{ $violations->lastItem() }} {{ dt('من', 'of') }} {{ $violations->total() }}@endif</span>
    <div class="d-flex align-items-center gap-2 ms-auto">
        {{ $violations->onEachSide(1)->links('pagination::bootstrap-5') }}
        <form method="GET" action="{{ route('finance.index') }}" class="m-0">
            @foreach(request()->except(['per_page', 'page']) as $k => $v)@if(is_scalar($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
            <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="{{ dt('عدد الصفوف', 'Rows per page') }}">
                @foreach([25, 50, 100, 200] as $n)<option value="{{ $n }}" @selected($perPage === $n)>{{ $n }} / {{ dt('صفحة', 'page') }}</option>@endforeach
            </select>
        </form>
    </div>
</div>
</div>
@endsection

@push('head')
<style>
    .fin-amount { color: var(--up); }
    .fin-lp-tag { display: inline-flex; align-items: center; gap: .2rem; background: #fbeaea; color: #a52a1f; border-radius: 6px; padding: 0 .4rem; font-size: .72rem; font-weight: 700; margin-inline-end: .3rem; }
</style>
@endpush

@push('scripts')
<script>
(() => {
    const form = document.getElementById('cvFilters');
    const f = name => form.elements[name];
    // Control -> problems: only the chosen control's problems stay in the list.
    const itemSel = f('catalog_item_id');
    function showItems() {
        const cat = f('category_id').value;
        let shown = 0;
        itemSel.querySelectorAll('optgroup').forEach(g => { const on = ! cat || g.dataset.cat === cat; g.hidden = ! on; g.disabled = ! on; if (on) shown += g.children.length; });
        if (itemSel.selectedOptions[0]?.parentElement.disabled) itemSel.value = '';
        itemSel.disabled = shown === 0;
        document.getElementById('cvNoItems').classList.toggle('d-none', shown > 0);
    }
    form.querySelectorAll('.js-auto').forEach(el => el.addEventListener('change', () => {
        if (el.name === 'category_id') showItems();
        if (el.name === 'catalog_item_id' && el.value) f('category_id').value = el.selectedOptions[0].dataset.cat;
        form.requestSubmit();
    }));
    showItems();
})();
</script>
@endpush
