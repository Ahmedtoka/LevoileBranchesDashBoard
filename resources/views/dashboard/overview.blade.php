@extends('layouts.app')
@section('title', 'نظرة عامة')

@section('content')
<h4 class="fw-bold mb-3">نظرة عامة</h4>

@include('partials.filterbar', ['range' => $range])

@php
    $rk = ['range' => $range->key, 'from' => $range->customFrom, 'to' => $range->customTo];
    $cards = [
        ['الفروع', $stats['branches'], 'geo-alt', 'primary', route('branches.index', $rk)],
        ['الزيارات', $stats['visits_total'], 'clipboard', 'secondary', route('visits.index', $rk)],
        ['زيارات مكتملة', $stats['visits_completed'], 'clipboard-check', 'success', route('visits.index', $rk + ['status' => 'completed'])],
        ['زيارات مفتوحة', $stats['visits_open'], 'hourglass', 'info', route('visits.index', $rk + ['status' => 'open'])],
        ['إجمالي التذاكر', $stats['tickets_total'], 'ticket-detailed', 'dark', route('tickets.index', $rk)],
        ['تذاكر مفتوحة', $stats['tickets_open'], 'exclamation-circle', 'warning', route('tickets.index', $rk + ['status' => 'open'])],
        ['بانتظار الموافقة', $stats['waiting_approval'], 'hourglass-split', 'info', route('tickets.index', $rk + ['status' => 'waiting_approval'])],
        ['متأخرة', $stats['overdue'], 'exclamation-triangle', 'danger', route('tickets.index', $rk + ['status' => 'over_1_day'])],
    ];
@endphp
<div class="row g-3 mb-4">
    @foreach($cards as [$label, $value, $icon, $color, $link])
        <div class="col-md-3 col-6">
            <a href="{{ $link }}" class="text-decoration-none">
                <div class="card stat-card p-3 h-100" style="cursor:pointer">
                    <div class="text-muted small"><i class="bi bi-{{ $icon }} text-{{ $color }} me-1"></i>{{ $label }}</div>
                    <div class="value text-dark">{{ $value }}</div>
                </div>
            </a>
        </div>
    @endforeach
</div>

{{-- Tickets by source --}}
<h6 class="fw-bold mb-2">التذاكر حسب المصدر</h6>
<div class="row g-3 mb-4">
    @php
        $sources = [
            ['شيك ليست مدير الفرع', $bySource['store'] ?? 0, 'clipboard-check', '#6366f1', ['source' => 'store']],
            ['زيارات الأريا مانجر', $bySource['area'] ?? 0, 'binoculars', '#0d9488', ['source' => 'area']],
            ['طلبات الصيانة', $bySource['maintenance'] ?? 0, 'tools', '#9C1E6E', ['source' => 'maintenance']],
        ];
    @endphp
    @foreach($sources as [$label, $val, $icon, $clr, $q])
        <div class="col-md-4">
            <a href="{{ route('tickets.index', $rk + $q) }}" class="text-decoration-none">
                <div class="card p-3 h-100 d-flex flex-row align-items-center" style="cursor:pointer">
                    <div class="rounded-circle d-flex align-items-center justify-content-center me-3"
                         style="width:46px;height:46px;background:{{ $clr }}1a;color:{{ $clr }}"><i class="bi bi-{{ $icon }} fs-5"></i></div>
                    <div class="flex-grow-1"><div class="text-muted small">{{ $label }}</div>
                        <div class="fw-bold fs-4 text-dark">{{ $val }}</div></div>
                    <i class="bi bi-chevron-left text-muted"></i>
                </div>
            </a>
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">التذاكر حسب الإدارة <span class="text-muted small">({{ $range->label }})</span></h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>الإدارة</th><th class="text-center">الكود</th><th class="text-center">الإجمالي</th><th class="text-center">مفتوحة</th><th class="text-center">مقفولة</th><th></th></tr></thead>
                <tbody>
                @foreach($ticketsByDept as $d)
                    <tr>
                        <td><span class="badge rounded-circle p-0" style="width:10px;height:10px;background:{{ $d->color }}"></span> {{ $d->name }}</td>
                        <td class="text-center"><code class="small">{{ $d->ticket_prefix ?? '—' }}</code></td>
                        <td class="text-center">{{ $d->total_count }}</td>
                        <td class="text-center"><span class="text-warning fw-semibold">{{ $d->open_count }}</span></td>
                        <td class="text-center text-success">{{ $d->closed_count }}</td>
                        <td class="text-end"><a href="{{ route('departments.board', $d) }}" class="btn btn-sm btn-outline-secondary">اللوحة</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">طلبات متكررة</h6>
            @forelse($repeated as $row)
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span><span class="badge text-bg-light text-capitalize">{{ $row->category }}</span> {{ optional($row->branch)->branch_name }}</span>
                    <strong>{{ $row->total }}×</strong>
                </div>
            @empty
                <p class="text-muted small mb-0">لا توجد طلبات متكررة في الفترة.</p>
            @endforelse
        </div>
    </div>

    <div class="col-12">
        <div class="card p-3">
            <h6 class="fw-bold mb-3">أحدث التذاكر</h6>
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>الكود</th><th>العنوان</th><th>الفرع</th><th>الإدارة</th><th>الأولوية</th><th>الحالة</th></tr></thead>
                <tbody>
                @forelse($recentTickets as $t)
                    <tr onclick="window.location='{{ route('tickets.show', $t) }}'" style="cursor:pointer">
                        <td class="fw-semibold">{{ $t->reference }}</td>
                        <td>{{ Str::limit($t->title, 50) }}</td>
                        <td>{{ optional($t->branch)->branch_name }}</td>
                        <td>{{ optional($t->department)->name ?? '—' }}</td>
                        <td>@include('partials.priority-badge', ['priority' => $t->priority])</td>
                        <td>@include('partials.status-badge', ['status' => $t->status])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted small">لا توجد تذاكر في الفترة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
