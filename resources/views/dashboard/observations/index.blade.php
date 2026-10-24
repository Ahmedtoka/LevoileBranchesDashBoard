@extends('layouts.app')
@section('title', dt('الملاحظات', 'Observations'))

@section('content')
@php
    $qs = $filters->toQueryString();
    $selected = fn (string $key, $value) => (string) $filters->get($key) === (string) $value;
@endphp

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="fw-bold mb-0">{{ dt('الملاحظات', 'Observations') }}</h4>
    <span class="badge text-bg-light border">{{ $observations->total() }}</span>
    <a href="{{ route('observations.dashboard', array_intersect_key($qs, array_flip(array_merge(['range', 'from', 'to'], \App\Support\ObservationMetrics::FILTER_KEYS)))) }}" class="btn btn-sm btn-outline-primary ms-auto">
        <i class="bi bi-speedometer2"></i> {{ dt('الداشبورد', 'Dashboard') }}
    </a>
    <a href="{{ route('observations.beforeAfter') }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-images"></i> {{ dt('كنا وبقينا', 'Before & after') }}
    </a>
    <a href="{{ route('observations.export', $qs) }}" class="btn btn-sm btn-outline-success">
        <i class="bi bi-filetype-csv"></i> {{ dt('تصدير CSV', 'Export CSV') }}
    </a>
</div>

{{-- Date presets keep every other filter (fullUrlWithQuery); the form below keeps the range. --}}
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <div class="btn-group btn-group-sm">
        @foreach(\App\Support\DateRange::$presets as $key => $label)
            @continue($key === 'custom')
            <a href="{{ request()->fullUrlWithQuery(['range' => $key, 'from' => null, 'to' => null, 'page' => null]) }}"
               class="btn {{ $range->key === $key ? 'btn-primary' : 'btn-outline-secondary' }}">{{ t('range.'.$key, $label) }}</a>
        @endforeach
        <a href="{{ request()->fullUrlWithQuery(['range' => \App\Support\ObservationFilters::ALL, 'from' => null, 'to' => null, 'page' => null]) }}"
           class="btn {{ $range->key === \App\Support\ObservationFilters::ALL ? 'btn-primary' : 'btn-outline-secondary' }}">{{ dt('كل التواريخ', 'All dates') }}</a>
    </div>
    <span class="text-muted small ms-auto">
        @if($range->key === \App\Support\ObservationFilters::ALL)
            {{ dt('كل التواريخ', 'All dates') }}
        @else
            {{ $range->from->format('d M') }} – {{ $range->to->format('d M Y') }}
        @endif
    </span>
</div>

<form class="card p-3 mb-3" method="GET" action="{{ route('observations.index') }}">
    <input type="hidden" name="range" id="obsRange" value="{{ $range->key }}">
    <div class="row g-2 align-items-end">
        <div class="col-md-3 col-6">
            <label class="form-label small mb-1">{{ dt('بحث', 'Search') }}</label>
            <input name="q" value="{{ $filters->get('q') }}" class="form-control form-control-sm" placeholder="{{ dt('المرجع / العنوان / الوصف', 'Reference / title / description') }}">
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ t('range.from', 'من') }}</label>
            <input type="date" name="from" value="{{ $range->customFrom }}" class="form-control form-control-sm js-custom-date">
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ t('range.to', 'إلى') }}</label>
            <input type="date" name="to" value="{{ $range->customTo }}" class="form-control form-control-sm js-custom-date">
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ dt('الإدارة المُبلِّغة', 'Reporting dept') }}</label>
            <select name="reporting_department_id" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($reportingDepartments as $d)<option value="{{ $d->id }}" @selected($selected('reporting_department_id', $d->id))>{{ $d->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3 col-6">
            <label class="form-label small mb-1">{{ dt('الإدارة المسؤولة', 'Responsible dept') }}</label>
            <select name="responsible_department_id" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($departments as $d)<option value="{{ $d->id }}" @selected($selected('responsible_department_id', $d->id))>{{ $d->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ dt('المنطقة', 'Area') }}</label>
            <select name="area_id" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($areas as $a)<option value="{{ $a->id }}" @selected($selected('area_id', $a->id))>{{ dt($a->name_ar ?: $a->name, $a->name) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ t('common.branch', 'الفرع') }}</label>
            <select name="branch_id" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected($selected('branch_id', $b->id))>{{ $b->branch_name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ dt('الفئة', 'Category') }}</label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($categories as $c)<option value="{{ $c->id }}" @selected($selected('category_id', $c->id))>{{ dt($c->name_ar, $c->name_en) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ dt('الريسك', 'Risk') }}</label>
            <select name="risk" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($riskLevels as $r)<option value="{{ $r->slug }}" @selected($selected('risk', $r->slug))>{{ dt($r->name_ar, $r->name_en) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ t('tk.status', 'الحالة') }}</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach(\App\Models\Observation::STATUSES as $s)<option value="{{ $s }}" @selected($selected('status', $s))>{{ \App\Support\ObservationLabels::status($s) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ dt('النوع', 'Kind') }}</label>
            <select name="kind" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach(\App\Models\Observation::KINDS as $k)<option value="{{ $k }}" @selected($selected('kind', $k))>{{ \App\Support\ObservationLabels::kind($k) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">{{ dt('حالة التصنيف', 'Classification') }}</label>
            <select name="classification_status" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach(\App\Models\Observation::CLASSIFICATION as $c)<option value="{{ $c }}" @selected($selected('classification_status', $c))>{{ \App\Support\ObservationLabels::classification($c) }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2 col-6">
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" name="outside_catalog" value="1" id="outsideCatalog" @checked($filters->get('outside_catalog'))>
                <label class="form-check-label small" for="outsideCatalog">{{ dt('خارج الكتالوج', 'Outside catalog') }}</label>
            </div>
        </div>
        <div class="col-md-3 col-12">
            <button class="btn btn-sm btn-primary">{{ t('common.apply', 'تطبيق') }}</button>
            <a href="{{ route('observations.index') }}" class="btn btn-sm btn-outline-secondary">{{ t('common.reset', 'مسح') }}</a>
        </div>
    </div>
</form>

<div class="card p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr>
            <th>{{ dt('المرجع', 'Reference') }}</th>
            <th>{{ dt('التاريخ', 'Date') }}</th>
            <th>{{ t('common.branch', 'الفرع') }}</th>
            <th>{{ t('common.department', 'الإدارة') }}</th>
            <th>{{ dt('الفئة / البند', 'Category / item') }}</th>
            <th>{{ dt('الملاحظة', 'Observation') }}</th>
            <th>{{ dt('الريسك', 'Risk') }}</th>
            <th>{{ dt('المسؤولة', 'Responsible') }}</th>
            <th>{{ t('tk.status', 'الحالة') }}</th>
            <th>{{ dt('التيكت', 'Ticket') }}</th>
            <th>{{ dt('متأخر؟', 'Overdue?') }}</th>
        </tr></thead>
        <tbody>
        @forelse($observations as $o)
            <tr style="cursor:pointer" onclick="if (!event.target.closest('a')) location.href='{{ route('observations.show', $o) }}'">
                <td class="fw-semibold text-nowrap"><a href="{{ route('observations.show', $o) }}" class="text-decoration-none">{{ $o->reference }}</a></td>
                <td class="text-nowrap">
                    @if($o->event_date)
                        {{ $o->event_date->format('Y-m-d') }}
                    @elseif($o->visit?->visit_date)
                        {{ $o->visit->visit_date->format('Y-m-d') }}
                        <div class="text-muted" style="font-size:.7rem">{{ dt('تاريخ غير مؤكد', 'Date not confirmed') }}</div>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td>{{ $o->branch?->branch_name ?? '—' }}</td>
                <td>{{ $o->reportingDepartment?->name ?? '—' }}</td>
                <td>
                    @if($o->catalogItem)
                        {{ dt($o->catalogItem->label_ar, $o->catalogItem->label_en) }}
                    @elseif($o->category)
                        {{ dt($o->category->name_ar, $o->category->name_en) }}
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td>{{ \Illuminate\Support\Str::limit($o->title, 50) }}</td>
                <td>@include('dashboard.observations.partials.risk-badge', ['risk' => $o->riskLevel])</td>
                <td>{{ $o->responsibleDepartment?->name ?? '—' }}</td>
                <td>@include('dashboard.observations.partials.status-pill', ['status' => $o->status])</td>
                <td class="text-nowrap">
                    @if($o->ticket)
                        <a href="{{ route('tickets.show', $o->ticket) }}" class="text-decoration-none">{{ $o->ticket->reference }}</a>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td>@if($o->ticket?->isOverdue())<span class="badge text-bg-danger">{{ dt('متأخر', 'Overdue') }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="11" class="text-muted small p-3">{{ dt('لا توجد ملاحظات في الفترة.', 'No observations in this period.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-3">{{ $observations->links() }}</div>
@endsection

@push('scripts')
<script>
    // Picking a date by hand switches the preset to a custom range.
    document.querySelectorAll('.js-custom-date').forEach(function (el) {
        el.addEventListener('change', function () { document.getElementById('obsRange').value = 'custom'; });
    });
</script>
@endpush
