@extends('layouts.app')
@section('title', dt('قائمة مخالفات CCTV', 'CCTV violations'))
@include('dashboard.cctv.partials.style')
@include('dashboard.cctv.partials.list-style')

@section('content')
@php
    use App\Support\CctvDashboard;
    use App\Support\CctvReport;
    use App\Support\ObservationLabels;

    $q = request()->query();
    // A link to this page with some filters changed (null removes one); the page number always resets.
    $with = fn (array $changes) => route('cctv.index', array_filter(array_merge($q, $changes, ['page' => null]), fn ($v) => $v !== null && $v !== ''));
    $navMonth = $month ?? CctvDashboard::defaultMonth(auth()->user());
    $exact = request('from') || request('to');

    $palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    $colour = [];
    foreach ($categories->values() as $i => $c) { $colour[$c->id] = $palette[$i % count($palette)]; }

    // The active filters as removable chips.
    $chips = [];
    if ($exact) { $chips[] = [($from?->format('j/n/Y') ?? '…').' – '.($to?->format('j/n/Y') ?? '…'), ['from' => null, 'to' => null]]; }
    if (request('branch_id')) { $chips[] = [CctvReport::storeName($branches->firstWhere('id', (int) request('branch_id'))?->branch_name), ['branch_id' => null, 'employee_id' => null]]; }
    $employeeLabel = ! request('employee_id') ? '' : (request('employee_id') === 'none' ? dt('بدون اسم', 'No name') : ($employees->firstWhere('id', (int) request('employee_id'))?->name ?? '?'));
    if ($employeeLabel !== '') { $chips[] = [$employeeLabel, ['employee_id' => null]]; }
    if (request('category_id')) { $chips[] = [$categories->firstWhere('id', (int) request('category_id'))?->sheet_label, ['category_id' => null, 'catalog_item_id' => null]]; }
    if (request('catalog_item_id')) { $chips[] = [$items->firstWhere('id', (int) request('catalog_item_id'))?->label_ar, ['catalog_item_id' => null]]; }
    if (request('status')) { $chips[] = [request('status') === 'active' ? dt('لسه مفتوحة', 'Still open') : ObservationLabels::status(request('status')), ['status' => null]]; }
    if (request('department_id')) { $chips[] = [dt('مسئولية', 'To').' '.$departments->firstWhere('id', (int) request('department_id'))?->name, ['department_id' => null]]; }
    if (request('reply')) { $chips[] = [request('reply') === 'yes' ? dt('فيها رد', 'Has a reply') : dt('من غير رد', 'No reply'), ['reply' => null]]; }
    if (request('penalty')) { $chips[] = [dt('عليها جزاء', 'Has a penalty'), ['penalty' => null]]; }
    if (request('min_minutes')) { $chips[] = [dt('تأخير ', 'Late ').request('min_minutes').dt(' دقيقة أو أكتر', '+ min'), ['min_minutes' => null]]; }
    if (request('q')) { $chips[] = ['«'.request('q').'»', ['q' => null]]; }
    $total = $stats['total'];
@endphp

<div class="cv">
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="fw-bold mb-0">{{ dt('قائمة مخالفات CCTV', 'CCTV violations') }}</h4>
    <div class="ms-auto d-flex gap-2 d-print-none">
        @if($canRecord)<a href="{{ route('cctv.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> {{ dt('تسجيل مخالفة', 'Record a violation') }}</a>@endif
        <a href="{{ route('cctv.export', $q) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> {{ dt('تنزيل Excel', 'Download Excel') }} ({{ $total }})</a>
    </div>
</div>

{{-- Filters: the period first (month, week), then the rest; selects apply at once --}}
<form method="GET" action="{{ route('cctv.index') }}" class="cv-bar cv-filters" id="cvFilters">
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
            <select name="branch_id" class="form-select form-select-sm js-auto" data-resets="employee_id">
                <option value="">{{ dt('كل الفروع', 'All branches') }}</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) request('branch_id') === (string) $b->id)>{{ CctvReport::storeName($b->branch_name) }}</option>@endforeach
            </select>
        </label>
        <label>{{ dt('الموظف', 'Employee') }}
            <input name="employee_lookup" list="cvEmployees" class="form-control form-control-sm" autocomplete="off"
                   placeholder="{{ dt('اكتب الاسم أو الكود', 'Type a name or code') }}" value="{{ $employeeLabel }}">
            <input type="hidden" name="employee_id" value="{{ request('employee_id') }}">
            <datalist id="cvEmployees">
                <option value="{{ dt('بدون اسم', 'No name') }}" data-id="none"></option>
                @foreach($employees as $e)
                    <option value="{{ $e->name }} · {{ CctvReport::storeName($e->branch?->branch_name) }}" data-id="{{ $e->id }}" data-branch="{{ $e->branch_id }}" data-code="{{ $e->code }}">{{ $e->code }}</option>
                @endforeach
            </datalist>
        </label>
        <label>{{ dt('نوع المخالفة', 'Type') }}
            <select name="category_id" class="form-select form-select-sm js-auto" data-resets="catalog_item_id">
                <option value="">{{ dt('كل الأنواع', 'All types') }}</option>
                @foreach($categories as $c)<option value="{{ $c->id }}" @selected((string) request('category_id') === (string) $c->id)>{{ $c->sheet_label }}</option>@endforeach
            </select>
        </label>
        <label>{{ dt('المخالفة', 'Violation') }}
            <select name="catalog_item_id" class="form-select form-select-sm js-auto" data-sets="category_id">
                <option value="">{{ dt('كل المخالفات', 'All violations') }}</option>
                @foreach($categories as $c)
                    <optgroup label="{{ $c->sheet_label }}" data-cat="{{ $c->id }}">
                        @foreach($items->where('category_id', $c->id) as $i)<option value="{{ $i->id }}" data-cat="{{ $c->id }}" @selected((string) request('catalog_item_id') === (string) $i->id)>{{ $i->label_ar }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select>
            <small class="cv-field-note d-none" id="cvNoItems">{{ dt('النوع ده مالوش مخالفات محددة', 'No listed violations for this type') }}</small>
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
        <label>{{ dt('رد الفرع', 'Branch reply') }}
            <select name="reply" class="form-select form-select-sm js-auto">
                <option value="">{{ dt('الكل', 'All') }}</option>
                <option value="yes" @selected(request('reply') === 'yes')>{{ dt('فيها رد', 'Has a reply') }}</option>
                <option value="no" @selected(request('reply') === 'no')>{{ dt('من غير رد', 'No reply') }}</option>
            </select>
        </label>
        <label>{{ dt('دقايق التأخير', 'Minutes late') }}
            <select name="min_minutes" class="form-select form-select-sm js-auto">
                <option value="">{{ dt('أي مدة', 'Any') }}</option>
                @foreach([15, 30, 60] as $m)<option value="{{ $m }}" @selected((string) request('min_minutes') === (string) $m)>{{ $m }}+ {{ dt('دقيقة', 'min') }}</option>@endforeach
            </select>
        </label>
        <label class="cv-field-wide">{{ dt('بحث', 'Search') }}
            <span class="cv-search"><i class="bi bi-search"></i><input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="{{ dt('نص المخالفة، الاسم، أو رقم المرجع', 'Text, name or reference') }}"></span>
        </label>
        <label>{{ dt('الترتيب', 'Sort') }}
            <select name="sort" class="form-select form-select-sm js-auto">
                @foreach(['newest' => dt('الأحدث', 'Newest'), 'oldest' => dt('الأقدم', 'Oldest'), 'branch' => dt('الفرع', 'Branch'), 'employee' => dt('الموظف', 'Employee'), 'type' => dt('النوع', 'Type')] as $k => $label)
                    <option value="{{ $k }}" @selected(request('sort', 'newest') === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <input type="hidden" name="per_page" value="{{ $perPage }}">
    </div>

    @if($chips)
        <div class="cv-chips-row">
            @foreach($chips as [$label, $clear])
                <a href="{{ $with($clear) }}" class="cv-chip" title="{{ dt('شيل الفلتر', 'Remove filter') }}">{{ $label }} <i class="bi bi-x"></i></a>
            @endforeach
            <a href="{{ route('cctv.index', array_filter(['month' => request('month'), 'week' => request('week')])) }}" class="cv-chip-clear">{{ dt('شيل كل الفلاتر', 'Clear all') }}</a>
        </div>
    @endif
</form>

{{-- Summary of everything the filters match: the numbers, then the split by type --}}
<section class="cv-sum">
    <div class="cv-sum-stats">
        <div class="cv-stat cv-stat-main"><span>{{ dt('عدد المخالفات', 'Violations') }}</span><b>{{ $total }}</b></div>
        <div class="cv-stat"><span>{{ dt('فروع', 'Branches') }}</span><b>{{ $stats['branches'] }}</b></div>
        <div class="cv-stat"><span>{{ dt('موظفين', 'Employees') }}</span><b>{{ $stats['employees'] }}</b></div>
        <a class="cv-stat" href="{{ $with(['employee_id' => 'none']) }}"><span>{{ dt('بدون اسم', 'No name') }}</span><b>{{ $stats['unnamed'] }}</b><i class="bi bi-chevron-left"></i></a>
        <a class="cv-stat" href="{{ $with(['status' => 'active']) }}"><span>{{ dt('لسه مفتوحة', 'Still open') }}</span><b>{{ $stats['active'] }}</b><i class="bi bi-chevron-left"></i></a>
        <a class="cv-stat" href="{{ $with(['reply' => 'yes']) }}"><span>{{ dt('فيها رد من الفرع', 'Branch replied') }}</span><b>{{ $stats['replied'] }}</b><i class="bi bi-chevron-left"></i></a>
        <div class="cv-stat"><span>{{ dt('متوسط التأخير', 'Avg. late') }}</span><b>{{ $stats['avg_late'] ?? '—' }}@if($stats['avg_late']) <small>{{ dt('دقيقة', 'min') }}</small>@endif</b></div>
    </div>
    @if($total)
        <div class="cv-split">
            <div class="cv-split-bar" role="img" aria-label="{{ dt('توزيع المخالفات حسب النوع', 'Split by type') }}">
                @foreach($categories as $c)
                    @php $n = $stats['by_category'][$c->id] ?? 0; @endphp
                    @if($n)<a href="{{ $with(['category_id' => $c->id, 'catalog_item_id' => null]) }}" style="flex: {{ $n }}; background: {{ $colour[$c->id] }}" title="{{ $c->sheet_label }}: {{ $n }}"></a>@endif
                @endforeach
            </div>
            <ul class="cv-split-legend">
                @foreach($categories->sortByDesc(fn ($c) => $stats['by_category'][$c->id] ?? 0) as $c)
                    @php $n = $stats['by_category'][$c->id] ?? 0; @endphp
                    @if($n)<li><a href="{{ $with(['category_id' => $c->id, 'catalog_item_id' => null]) }}" @class(['on' => (string) request('category_id') === (string) $c->id])><i class="cv-dot" @style(['--c: '.$colour[$c->id]])></i><span>{{ $c->sheet_label }}</span><b>{{ $n }}</b><small>{{ round($n * 100 / $total) }}%</small></a></li>@endif
                @endforeach
            </ul>
        </div>
    @endif
</section>

{{-- The table: header fixed, rows scroll under it --}}
<div class="cv-table-wrap">
    <table class="cv-table">
        <thead><tr>
            <th>{{ dt('التاريخ', 'Date') }}</th>
            <th>{{ dt('الفرع', 'Branch') }}</th>
            <th>{{ dt('الموظف', 'Employee') }}</th>
            <th>{{ dt('المخالفة', 'Violation') }}</th>
            <th>{{ dt('رد الفرع / ملاحظات', 'Reply / notes') }}</th>
            <th>{{ dt('الجزاء', 'Penalty') }}</th>
            <th>{{ dt('الإدارة المسئولة', 'Responsible') }}</th>
            <th>{{ dt('الحالة', 'Status') }}</th>
            <th class="text-end">{{ dt('المرجع', 'Ref.') }}</th>
        </tr></thead>
        <tbody>
        @forelse($violations as $o)
            @php $extra = (array) ($o->extra_values ?? []); $mins = $extra['minutes_late'] ?? $extra['minutes'] ?? null; @endphp
            <tr>
                <td class="cv-td-date"><b>{{ $o->event_date?->format('j/n') }}</b><small>{{ $o->event_date?->format('Y') }}@if(! empty($extra['time'])) · {{ $extra['time'] }}@endif</small></td>
                <td class="text-nowrap">{{ CctvReport::storeName($o->branch?->branch_name) }}</td>
                <td class="cv-td-emp">
                    @if($o->employee_id || $o->employee_name)
                        <a href="{{ $with(['employee_id' => $o->employee_id, 'branch_id' => $o->branch_id]) }}">{{ $o->employee?->name ?? $o->employee_name }}</a>
                        @if($o->employee?->code)<small>{{ $o->employee->code }}</small>@endif
                    @else
                        <span class="text-muted">{{ dt('بدون اسم', 'No name') }}</span>
                    @endif
                </td>
                <td class="cv-td-vio">
                    <span class="cv-td-type"><i class="cv-dot" @style(['--c: '.($colour[$o->category_id] ?? '#898781')])></i>{{ $categories->firstWhere('id', $o->category_id)?->sheet_label ?? $o->category?->name_ar }}</span>
                    <b>{{ $o->catalogItem?->label_ar ?? $o->title }}</b>
                    @if($mins)<span class="cv-mins">{{ $mins }} {{ dt('دقيقة', 'min') }}</span>@endif
                    @if($o->description !== $o->catalogItem?->label_ar)<span class="cv-td-desc">{{ $o->description }}</span>@endif
                </td>
                <td class="cv-td-reply">{{ $extra['reply'] ?? '' }}</td>
                <td class="small">{{ $o->penalty }}</td>
                <td class="text-nowrap small">@if($o->responsibleDepartment)<a href="{{ $with(['department_id' => $o->responsible_department_id]) }}" class="text-reset">{{ $o->responsibleDepartment->name }}</a>@endif</td>
                <td><span class="badge rounded-pill text-bg-{{ ObservationLabels::statusColor($o->status) }}">{{ ObservationLabels::status($o->status) }}</span></td>
                <td class="text-end"><a href="{{ route('observations.show', $o) }}" class="cv-ref">{{ $o->reference }}</a></td>
            </tr>
        @empty
            <tr><td colspan="9" class="cv-td-empty">
                <i class="bi bi-funnel"></i>
                <p class="mb-1 fw-semibold">{{ dt('مفيش مخالفات بالفلاتر دي.', 'No violations match these filters.') }}</p>
                @if($chips)<a href="{{ route('cctv.index') }}" class="small">{{ dt('شيل كل الفلاتر', 'Clear all filters') }}</a>@endif
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="cv-pager">
    <span class="text-muted small">
        @if($violations->total()){{ dt('عرض', 'Showing') }} {{ $violations->firstItem() }}–{{ $violations->lastItem() }} {{ dt('من', 'of') }} {{ $violations->total() }}@endif
    </span>
    <div class="d-flex align-items-center gap-2 ms-auto">
        {{ $violations->onEachSide(1)->links('pagination::bootstrap-5') }}
        <form method="GET" action="{{ route('cctv.index') }}" class="m-0">
            @foreach(request()->except(['per_page', 'page']) as $k => $v)@if(is_scalar($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
            <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="{{ dt('عدد الصفوف', 'Rows per page') }}">
                @foreach([25, 50, 100, 200] as $n)<option value="{{ $n }}" @selected($perPage === $n)>{{ $n }} / {{ dt('صفحة', 'page') }}</option>@endforeach
            </select>
        </form>
    </div>
</div>
</div>
@endsection



@push('scripts')
<script>
(() => {
    const form = document.getElementById('cvFilters');
    const f = name => form.elements[name];

    // Type -> violations: only the chosen type's violations stay in the list ("أخرى" has none of its own).
    const itemSel = f('catalog_item_id');
    function showItems() {
        const cat = f('category_id').value;
        let shown = 0;
        itemSel.querySelectorAll('optgroup').forEach(g => {
            const on = ! cat || g.dataset.cat === cat;
            g.hidden = ! on; g.disabled = ! on;
            if (on) shown += g.children.length;
        });
        if (itemSel.selectedOptions[0]?.parentElement.disabled) itemSel.value = '';
        itemSel.disabled = shown === 0;
        document.getElementById('cvNoItems').classList.toggle('d-none', shown > 0);
    }
    // Branch -> employees: the suggestions are the chosen branch's staff.
    const allPeople = [...document.querySelectorAll('#cvEmployees option[data-branch]')];
    function showPeople() {
        const b = f('branch_id').value;
        allPeople.forEach(o => { o.disabled = !! b && o.dataset.branch !== b; if (o.disabled) o.remove(); else document.getElementById('cvEmployees').append(o); });
    }

    // A changed select applies at once. A parent clears a child that no longer fits; a child sets its parent.
    form.querySelectorAll('.js-auto').forEach(el => el.addEventListener('change', () => {
        if (el.name === 'category_id') { showItems(); }
        if (el.name === 'catalog_item_id' && el.value) { f('category_id').value = el.selectedOptions[0].dataset.cat; }
        if (el.name === 'branch_id') {
            const emp = allPeople.find(o => o.dataset.id === f('employee_id').value);
            if (emp && el.value && emp.dataset.branch !== el.value) { f('employee_id').value = ''; }
        }
        form.requestSubmit();
    }));

    // Employee: a name, "name · branch" or a code from the list -> its id (and its branch); an empty box clears it.
    const lookup = f('employee_lookup'), hidden = f('employee_id');
    lookup.addEventListener('change', () => {
        const v = lookup.value.trim();
        const opt = [...document.querySelectorAll('#cvEmployees option')].find(o => o.value === v || (o.dataset.code && o.dataset.code === v) || o.value.split(' · ')[0] === v);
        if (v === '') { hidden.value = ''; form.requestSubmit(); return; }
        if (! opt) return;
        hidden.value = opt.dataset.id;
        if (opt.dataset.branch) f('branch_id').value = opt.dataset.branch;
        form.requestSubmit();
    });
    lookup.removeAttribute('name');

    showItems(); showPeople();
})();
</script>
@endpush
