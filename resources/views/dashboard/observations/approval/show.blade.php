@extends('layouts.app')
@section('title', $visit->code)

@section('content')
@php
    $en = app()->getLocale() === 'en';
    $kindLabels = ['issue' => dt('مشكلة', 'Issue'), 'activity' => dt('نشاط', 'Activity'), 'assessment' => dt('تقييم', 'Assessment'), 'positive' => dt('إيجابية', 'Positive')];
    $unrouted = $observations->filter(fn ($o) => $o->kind === 'issue' && $o->responsible_department_id === null)->count();
    // Precomputed: @json breaks on the comma inside dt().
    $confirmApprove = dt('اعتماد الزيارة وإنشاء طلب لكل مشكلة؟', 'Approve the visit and open a ticket for every issue?');
@endphp
<a href="{{ route('obs.approval.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left"></i> {{ dt('زيارات بانتظار الاعتماد', 'Visits awaiting approval') }}</a>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1 mb-3">
    <h4 class="fw-bold mb-0">{{ $visit->code }}
        <span class="badge {{ $visit->status === 'approved' ? 'text-bg-success' : 'text-bg-warning' }}">{{ $visit->status === 'approved' ? dt('معتمدة', 'Approved') : dt('بانتظار الاعتماد', 'Awaiting approval') }}</span>
    </h4>
    @if($editable)
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('obs.approval.classify', $visit) }}">@csrf
            <button class="btn btn-outline-primary"><i class="bi bi-robot me-1"></i>{{ dt('فحص', 'Check') }}</button>
        </form>
        <form method="POST" action="{{ route('obs.approval.approve', $visit) }}" onsubmit='return confirm(@json($confirmApprove))'>@csrf
            <button class="btn btn-success" @if($unrouted > 0) disabled title="{{ dt('حدد الإدارة المسؤولة لكل مشكلة أولاً', 'Set the responsible department of every issue first') }}" @endif><i class="bi bi-check2-all me-1"></i>{{ dt('اعتماد الزيارة', 'Approve visit') }}</button>
        </form>
    </div>
    @endif
</div>

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="card p-3 mb-3">
    <dl class="row small mb-0">
        <dt class="col-sm-2">{{ dt('الإدارة المُبلِّغة', 'Reporting department') }}</dt><dd class="col-sm-4">{{ optional($visit->department)->name }}</dd>
        <dt class="col-sm-2">{{ dt('الفرع', 'Branch') }}</dt><dd class="col-sm-4">{{ optional($visit->branch)->branch_name ?? dt('كل الفروع', 'All branches') }}</dd>
        <dt class="col-sm-2">{{ dt('الموظف', 'Recorder') }}</dt><dd class="col-sm-4">{{ optional($visit->user)->name }}</dd>
        <dt class="col-sm-2">{{ dt('تاريخ الزيارة', 'Visit date') }}</dt><dd class="col-sm-4">{{ optional($visit->visit_date)->format('Y-m-d') }} · {{ $visit->type === 'field' ? dt('ميدانية', 'Field') : dt('مكتبية', 'Desk') }}</dd>
        <dt class="col-sm-2">{{ dt('أُرسلت', 'Submitted') }}</dt><dd class="col-sm-4">{{ optional($visit->submitted_at)->format('Y-m-d H:i') }}</dd>
        @if($visit->status === 'approved')
            <dt class="col-sm-2">{{ dt('اعتُمدت', 'Approved') }}</dt><dd class="col-sm-4">{{ optional($visit->approved_at)->format('Y-m-d H:i') }} · {{ optional($visit->approver)->name }}</dd>
        @else
            <dt class="col-sm-2">{{ dt('بلا إدارة مسؤولة', 'Unrouted issues') }}</dt><dd class="col-sm-4">@if($unrouted)<span class="badge text-bg-warning">{{ $unrouted }}</span>@else<span class="text-success">{{ dt('كل المشكلات موجّهة', 'Every issue is routed') }}</span>@endif</dd>
        @endif
        @if($visit->general_notes)<dt class="col-sm-2">{{ dt('ملاحظات عامة', 'General notes') }}</dt><dd class="col-sm-10" dir="auto">{{ $visit->general_notes }}</dd>@endif
    </dl>
</div>

<h6 class="fw-bold">{{ dt('النقاط', 'Points') }} <span class="text-muted">({{ $observations->count() }})</span></h6>
@forelse($observations as $o)
    @php $s = $o->classification?->suggested ?? []; @endphp
    <div class="card p-3 mb-2 {{ $o->kind === 'issue' && $o->responsible_department_id === null && $editable ? 'border-warning' : '' }}">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <span class="fw-bold">{{ $o->reference }}</span>
                <span class="badge text-bg-light ms-1">{{ $kindLabels[$o->kind] ?? $o->kind }}</span>
                @if($o->catalogItem)<code class="ms-1">{{ $o->catalogItem->code }}</code>@else<span class="badge text-bg-secondary ms-1">{{ dt('حرة', 'Free text') }}</span>@endif
                @if($o->riskLevel)<span class="badge ms-1" style="background:{{ $o->riskLevel->color }}">{{ $en ? $o->riskLevel->name_en : $o->riskLevel->name_ar }}</span>@endif
                @if($o->ticket)
                    <a href="{{ route('tickets.show', $o->ticket) }}" class="badge text-bg-{{ $o->ticket->status === 'closed' ? 'success' : 'info' }} text-decoration-none ms-1">{{ $o->ticket->reference }} · {{ $o->ticket->statusAr() }}</a>
                @endif
            </div>
            @if($o->photos->isNotEmpty())
                <div class="d-flex gap-1">
                    @foreach($o->photos as $p)<a href="{{ asset('storage/'.$p->path) }}" target="_blank"><img src="{{ asset('storage/'.$p->path) }}" style="height:48px;border-radius:.3rem" alt=""></a>@endforeach
                </div>
            @endif
        </div>
        <div class="mt-2" dir="auto">{{ $o->description }}</div>
        @if(!empty($s))
            <div class="small text-muted mt-1"><i class="bi bi-robot me-1"></i>{{ dt('اقتراح الإيجينت', 'Agent suggestion') }}:
                {{ ($s['risk_level'] ?? null) ? (($rl = $riskLevels->firstWhere('slug', $s['risk_level'])) ? ($en ? $rl->name_en : $rl->name_ar) : $s['risk_level']) : '—' }}
                · {{ ($s['responsible_department_id'] ?? null) ? (optional($allDepartments->firstWhere('id', $s['responsible_department_id']))->name ?? '—') : '—' }}
                @if(isset($s['confidence']))<span class="badge {{ $s['confidence'] >= 0.75 ? 'text-bg-success' : 'text-bg-secondary' }}">{{ round($s['confidence'] * 100) }}%</span>@endif
                @if(!empty($s['reason']))<span class="fst-italic">— {{ $s['reason'] }}</span>@endif
            </div>
        @endif

        @if($editable)
        <form method="POST" action="{{ route('obs.approval.observation', [$visit, $o]) }}" class="row g-2 align-items-end mt-2">
            @csrf @method('PUT')
            <div class="col-md-3"><label class="form-label small mb-0">{{ dt('الإدارة المسؤولة', 'Responsible department') }}</label>
                <select name="responsible_department_id" class="form-select form-select-sm {{ $o->kind === 'issue' && $o->responsible_department_id === null ? 'is-invalid' : '' }}">
                    <option value="">—</option>
                    @foreach($allDepartments as $d)<option value="{{ $d->id }}" @selected($o->responsible_department_id == $d->id)>{{ $d->name }}</option>@endforeach
                </select></div>
            <div class="col-md-2"><label class="form-label small mb-0">{{ dt('درجة الخطورة', 'Risk') }}</label>
                <select name="risk_level_slug" class="form-select form-select-sm">
                    <option value="">—</option>
                    @foreach($riskLevels as $r)<option value="{{ $r->slug }}" @selected($o->risk_level_id == $r->id)>{{ $en ? $r->name_en : $r->name_ar }}</option>@endforeach
                </select></div>
            <div class="col-md-3"><label class="form-label small mb-0">{{ dt('الفئة', 'Category') }}</label>
                <select name="category_id" class="form-select form-select-sm">
                    <option value="">—</option>
                    @foreach($categories as $c)<option value="{{ $c->id }}" @selected($o->category_id == $c->id)>{{ $en ? $c->name_en : $c->name_ar }}</option>@endforeach
                </select></div>
            <div class="col-md-2"><label class="form-label small mb-0">{{ dt('النوع', 'Kind') }}</label>
                <select name="kind" class="form-select form-select-sm">
                    @foreach($kinds as $k)<option value="{{ $k }}" @selected($o->kind === $k)>{{ $kindLabels[$k] ?? $k }}</option>@endforeach
                </select></div>
            <div class="col-md-3"><label class="form-label small mb-0">{{ dt('الموظف (اختياري)', 'Employee (optional)') }}</label>
                <input type="text" name="employee_name" value="{{ $o->employee_name }}" class="form-control form-control-sm" dir="auto" maxlength="191" placeholder="{{ dt('اسم الموظف المعني', 'Employee concerned') }}"></div>
            <div class="col-md-5"><label class="form-label small mb-0">{{ dt('الجزاء (اختياري)', 'Penalty (optional)') }}</label>
                <input type="text" name="penalty" value="{{ $o->penalty }}" class="form-control form-control-sm" dir="auto" maxlength="500" placeholder="{{ dt('مثال: إنذار شفهي / خصم يوم', 'e.g. verbal warning / one-day deduction') }}"></div>
            <div class="col-md-2"><label class="form-label small mb-0">{{ dt('تاريخ المتابعة', 'Follow-up date') }}</label>
                <input type="date" name="follow_up_at" value="{{ optional($o->follow_up_at)->format('Y-m-d') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-save"></i> {{ dt('حفظ', 'Save') }}</button></div>
        </form>
        @else
            <div class="small text-muted mt-2">{{ dt('الإدارة المسؤولة', 'Responsible department') }}: <strong>{{ optional($o->responsibleDepartment)->name ?? '—' }}</strong>
                · {{ dt('الفئة', 'Category') }}: {{ $o->category ? ($en ? $o->category->name_en : $o->category->name_ar) : '—' }}
                @if($o->employee_name) · {{ dt('الموظف', 'Employee') }}: {{ $o->employee_name }} @endif
                @if($o->penalty) · {{ dt('الجزاء', 'Penalty') }}: {{ $o->penalty }} @endif
                @if($o->follow_up_at) · {{ dt('المتابعة', 'Follow-up') }}: {{ $o->follow_up_at->format('Y-m-d') }} @endif</div>
        @endif
    </div>
@empty
    <div class="card p-4 text-center text-muted">{{ dt('لا توجد نقاط في هذه الزيارة.', 'This visit has no points.') }}</div>
@endforelse
@endsection
