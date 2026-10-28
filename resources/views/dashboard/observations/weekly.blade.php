@extends('layouts.app')
@section('title', dt('تقرير الأسبوع', 'Weekly report'))
@push('head')
<style>
    .wk-matrix td, .wk-matrix th { white-space: nowrap; font-size: .8rem; }
    .wk-cell { display:inline-block; min-width: 1.8rem; padding: .1rem .35rem; border-radius: .35rem; font-weight: 600; }
    .wk-ok { background:#dcfce7; color:#166534; }
    .wk-warn { background:#fef3c7; color:#92400e; }
    .wk-bad { background:#fee2e2; color:#991b1b; }
    .wk-status-stable { background:#dcfce7; color:#166534; }
    .wk-status-follow_up { background:#fef3c7; color:#92400e; }
    .wk-status-critical { background:#fee2e2; color:#991b1b; }
    @media print {
        .sidebar, .topbar, nav, .d-print-none, .btn { display: none !important; }
        .main, .content, main { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        .card { box-shadow: none !important; border: 1px solid #ddd !important; break-inside: avoid; }
        body { background: #fff !important; font-size: 11pt; }
        a { text-decoration: none; color: inherit; }
    }
</style>
@endpush

@section('content')
@php
    use App\Support\ObservationWeeklyReport as W;
    $statusLabel = [W::STABLE => dt('مستقر', 'Stable'), W::FOLLOW_UP => dt('يحتاج متابعة', 'Needs follow-up'), W::CRITICAL => dt('حرج', 'Critical')];
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    $cellClass = fn (array $c) => $c['issues'] === 0 ? ($c['points'] > 0 ? 'wk-ok' : '') : (in_array($c['worst'], ['critical', 'high'], true) ? 'wk-bad' : 'wk-warn');
    $ratingLabel = ['good' => dt('جيد', 'Good'), 'average' => dt('متوسط', 'Average'), 'poor' => dt('ضعيف', 'Poor')];
@endphp

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-calendar-week text-primary me-1"></i> {{ dt('تقرير الأسبوع', 'Weekly report') }}
        @if($department)<span class="text-muted fw-normal">· {{ $department->name }}</span>@endif
    </h4>
    @if($report)
        <span class="badge text-bg-light border">{{ $report['from']->format('d M') }} – {{ $report['to']->format('d M Y') }}</span>
    @endif
    <div class="ms-auto d-flex gap-2 d-print-none">
        <a href="{{ route('observations.dashboard', ['reporting_department_id' => $department?->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-speedometer2"></i> {{ dt('الداشبورد', 'Dashboard') }}</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> {{ dt('طباعة / PDF', 'Print / PDF') }}</button>
    </div>
</div>

<form class="card p-3 mb-3 d-print-none" method="GET" action="{{ route('observations.weekly') }}">
    <div class="row g-2 align-items-end">
        <div class="col-md-4 col-12">
            <label class="form-label small mb-1">{{ dt('الإدارة', 'Department') }}</label>
            <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach($departments as $d)<option value="{{ $d->id }}" @selected($department && $department->id === $d->id)>{{ $d->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3 col-8">
            <label class="form-label small mb-1">{{ dt('أي يوم في الأسبوع (السبت → الجمعة)', 'Any day of the week (Sat → Fri)') }}</label>
            <input type="date" name="date" value="{{ $date }}" class="form-control form-control-sm" onchange="this.form.submit()">
        </div>
        <div class="col-auto">
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary" href="{{ route('observations.weekly', ['department_id' => $department?->id, 'date' => $prevDate]) }}" title="{{ dt('الأسبوع السابق', 'Previous week') }}"><i class="bi bi-chevron-right"></i> {{ dt('السابق', 'Previous') }}</a>
                <a class="btn btn-outline-secondary" href="{{ route('observations.weekly', ['department_id' => $department?->id]) }}">{{ dt('هذا الأسبوع', 'This week') }}</a>
                <a class="btn btn-outline-secondary" href="{{ route('observations.weekly', ['department_id' => $department?->id, 'date' => $nextDate]) }}" title="{{ dt('الأسبوع التالي', 'Next week') }}">{{ dt('التالي', 'Next') }} <i class="bi bi-chevron-left"></i></a>
            </div>
        </div>
        <div class="col-auto"><button class="btn btn-sm btn-primary">{{ dt('عرض', 'Show') }}</button></div>
    </div>
</form>

@if(! $report)
    <div class="card p-4 text-center text-muted">{{ dt('لا توجد إدارة مُبلِّغة متاحة لحسابك.', 'No reporting department is available to your account.') }}</div>
@else
    @php $k = $report['kpis']; @endphp

    {{-- Header block as the legacy Word report: department, week, compliance, trend --}}
    <div class="row g-2 mb-3">
        @foreach([
            ['compliance', dt('نسبة الالتزام', 'Compliance'), 'bi-shield-check', $k['compliance'] === null ? 'secondary' : ($k['compliance'] >= 75 ? 'success' : ($k['compliance'] >= 50 ? 'warning' : 'danger')), $pct($k['compliance'])],
            ['branches', dt('فروع مغطّاة', 'Branches covered'), 'bi-shop', 'info', $k['branches']],
            ['visits', dt('زيارات', 'Visits'), 'bi-geo-alt', 'primary', $k['visits']],
            ['issues', dt('مشاكل', 'Issues'), 'bi-exclamation-circle', 'warning', $k['issues']],
            ['solved', dt('اتحلّت', 'Solved'), 'bi-check2-circle', 'success', $k['solved']],
            ['overdue', dt('متأخرة', 'Overdue'), 'bi-alarm', 'danger', $k['overdue']],
        ] as [$key, $label, $icon, $colour, $value])
            <div class="col-lg-2 col-md-4 col-6">
                <div class="card stat-card p-2 text-center h-100">
                    <div class="value text-{{ $colour }}" style="font-size:1.5rem">{{ $value }}</div>
                    <div class="text-muted" style="font-size:.75rem"><i class="bi {{ $icon }}"></i> {{ $label }}</div>
                    @if($key === 'compliance')
                        <div class="text-muted" style="font-size:.65rem">
                            {{ dt('الأسبوع السابق', 'Previous week') }}: {{ $pct($k['previous_compliance']) }}
                            @if($k['compliance'] !== null && $k['previous_compliance'] !== null)
                                @php $d = round($k['compliance'] - $k['previous_compliance'], 1); @endphp
                                <span class="{{ $d > 0 ? 'text-success' : ($d < 0 ? 'text-danger' : '') }}">({{ $d > 0 ? '+' : '' }}{{ $d }})</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="card p-3 mb-3">
        <div class="d-flex flex-wrap align-items-center gap-3 small">
            <span class="fw-bold">{{ dt('تصنيف الفروع', 'Branch classification') }}:</span>
            <span><span class="badge wk-status-stable">{{ $k['stable'] }}</span> {{ $statusLabel[W::STABLE] }} <span class="text-muted">({{ dt('بدون مشاكل', 'no issues') }})</span></span>
            <span><span class="badge wk-status-follow_up">{{ $k['follow_up'] }}</span> {{ $statusLabel[W::FOLLOW_UP] }} <span class="text-muted">({{ dt('مشاكل متوسطة / منخفضة', 'medium / low issues') }})</span></span>
            <span><span class="badge wk-status-critical">{{ $k['critical'] }}</span> {{ $statusLabel[W::CRITICAL] }} <span class="text-muted">({{ dt('مشكلة عالية / حرجة أو متأخرة', 'a high / critical or overdue issue') }})</span></span>
            <span class="ms-auto text-muted">{{ dt('نسبة الالتزام = الفروع المستقرة ÷ الفروع المغطّاة', 'Compliance = stable branches ÷ branches covered') }}</span>
        </div>
    </div>

    {{-- Branch x category matrix --}}
    <div class="card p-0 mb-3">
        <div class="p-3 pb-0"><h6 class="fw-bold mb-0">{{ dt('الفروع × أنواع الملاحظات', 'Branches × observation types') }}</h6>
            <div class="text-muted small">{{ dt('الرقم = عدد المشاكل في الخانة. أخضر: نقاط بدون مشاكل، أصفر: متوسط/منخفض، أحمر: عالي/حرج.', 'Number = issues in the cell. Green: points without issues, yellow: medium/low, red: high/critical.') }}</div></div>
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 wk-matrix">
            <thead><tr>
                <th>{{ t('common.branch', 'الفرع') }}</th>
                <th class="text-center">{{ dt('زيارات', 'Visits') }}</th>
                @foreach($report['categories'] as $c)<th class="text-center">{{ dt($c->name_ar, $c->name_en ?: $c->name_ar) }}</th>@endforeach
                <th class="text-center">{{ dt('مشاكل', 'Issues') }}</th>
                <th class="text-center">{{ dt('مفتوحة', 'Open') }}</th>
                <th class="text-center">{{ dt('الحالة', 'Status') }}</th>
            </tr></thead>
            <tbody>
            @forelse($report['matrix'] as $row)
                <tr>
                    <td class="fw-semibold"><a href="{{ route('observations.index', ['range' => 'custom', 'from' => $report['from']->toDateString(), 'to' => $report['to']->toDateString(), 'branch_id' => $row['branch_id'], 'reporting_department_id' => $department->id]) }}" class="text-decoration-none">{{ $row['branch'] }}</a></td>
                    <td class="text-center">{{ $row['visits'] ?: '—' }}</td>
                    @foreach($report['categories'] as $c)
                        @php $cell = $row['cells'][$c->id]; @endphp
                        <td class="text-center">
                            @if($cell['points'] === 0)
                                <span class="text-muted">·</span>
                            @else
                                <span class="wk-cell {{ $cellClass($cell) }}" title="{{ $cell['points'] }} {{ dt('نقطة', 'points') }}">{{ $cell['issues'] > 0 ? $cell['issues'] : ($cell['rating'] ? ($ratingLabel[$cell['rating']] ?? $cell['rating']) : '✓') }}</span>
                            @endif
                        </td>
                    @endforeach
                    <td class="text-center fw-bold">{{ $row['issues'] }}</td>
                    <td class="text-center">@if($row['open'])<span class="badge text-bg-warning">{{ $row['open'] }}</span>@else 0 @endif</td>
                    <td class="text-center"><span class="badge wk-status-{{ $row['status'] }}">{{ $statusLabel[$row['status']] }}</span></td>
                </tr>
            @empty
                <tr><td colspan="{{ 5 + $report['categories']->count() }}" class="text-muted small p-3">{{ dt('لا توجد زيارات أو ملاحظات لهذه الإدارة في هذا الأسبوع.', 'No visits or points for this department in this week.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card p-3 h-100">
                <h6 class="fw-bold">{{ dt('المشاكل المتكررة', 'Recurring problems') }}</h6>
                @if(empty($report['recurring']))
                    <p class="small text-muted mb-0">{{ dt('لا توجد مشاكل متكررة هذا الأسبوع.', 'No recurring problems this week.') }}</p>
                @else
                    <table class="table table-sm small mb-0">
                        <thead><tr><th>{{ t('common.branch', 'الفرع') }}</th><th>{{ dt('المشكلة', 'Problem') }}</th><th class="text-center">{{ dt('مرات', 'Times') }}</th><th class="text-center">{{ dt('مفتوحة', 'Open') }}</th><th>{{ dt('الأسبوع السابق', 'Last week') }}</th></tr></thead>
                        <tbody>
                        @foreach($report['recurring'] as $r)
                            <tr><td>{{ $r['branch'] }}</td><td dir="auto">{{ $r['item'] }}</td><td class="text-center fw-bold">{{ $r['count'] }}</td><td class="text-center">{{ $r['open'] }}</td><td>{{ $r['last_week'] ? dt('نعم، تكررت', 'Yes, again') : '—' }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card p-3 h-100">
                <h6 class="fw-bold">{{ dt('المشاكل حسب الإدارة المسؤولة', 'Issues by responsible department') }}</h6>
                @if($report['by_responsible']->isEmpty())
                    <p class="small text-muted mb-0">—</p>
                @else
                    <table class="table table-sm small mb-0">
                        <thead><tr><th>{{ t('common.department', 'الإدارة') }}</th><th class="text-center">{{ dt('مشاكل', 'Issues') }}</th><th class="text-center">{{ dt('مفتوحة', 'Open') }}</th></tr></thead>
                        <tbody>
                        @foreach($report['by_responsible'] as $name => $n)
                            <tr><td>{{ $name }}</td><td class="text-center fw-bold">{{ $n['total'] }}</td><td class="text-center">{{ $n['open'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    {{-- Issue list per branch: the "قائمة المشاكل والإجراء" of the legacy report --}}
    <div class="card p-3">
        <h6 class="fw-bold">{{ dt('قائمة المشاكل والإجراء', 'Issues and actions') }} <span class="text-muted">({{ $k['issues'] }})</span></h6>
        @if($report['issues_by_branch']->isEmpty())
            <p class="small text-muted mb-0">{{ dt('لا توجد مشاكل في هذا الأسبوع.', 'No issues this week.') }}</p>
        @else
            @foreach($report['issues_by_branch'] as $branchName => $items)
                <div class="fw-semibold mt-2 mb-1">{{ $branchName }} <span class="text-muted small">({{ $items->count() }})</span></div>
                <table class="table table-sm small mb-2">
                    <thead><tr><th>{{ dt('المرجع', 'Ref') }}</th><th>{{ dt('المشكلة', 'Issue') }}</th><th>{{ dt('الريسك', 'Risk') }}</th><th>{{ dt('المسؤولة', 'Responsible') }}</th><th>{{ dt('الإجراء / الحالة', 'Action / status') }}</th><th>{{ dt('الموظف', 'Employee') }}</th><th>{{ dt('الجزاء', 'Penalty') }}</th><th>{{ dt('المتابعة', 'Follow-up') }}</th></tr></thead>
                    <tbody>
                    @foreach($items as $o)
                        <tr>
                            <td class="text-nowrap"><a href="{{ route('observations.show', $o) }}" class="text-decoration-none">{{ $o->reference }}</a></td>
                            <td dir="auto">{{ \Illuminate\Support\Str::limit($o->title, 70) }}</td>
                            <td>@include('dashboard.observations.partials.risk-badge', ['risk' => $o->riskLevel])</td>
                            <td>{{ $o->responsibleDepartment?->name ?? '—' }}</td>
                            <td>@include('dashboard.observations.partials.status-pill', ['status' => $o->status]) @if($o->ticket?->isOverdue())<span class="badge text-bg-danger">{{ dt('متأخر', 'Overdue') }}</span>@endif</td>
                            <td dir="auto">{{ $o->employee_name ?: '—' }}</td>
                            <td dir="auto">{{ $o->penalty ?: '—' }}</td>
                            <td class="text-nowrap">{{ $o->follow_up_at?->format('Y-m-d') ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endforeach
        @endif
    </div>
@endif
@endsection
