@extends('layouts.app')
@section('title', dt('زيارات بانتظار الاعتماد', 'Visits awaiting approval'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-clipboard-check me-2"></i>{{ dt('زيارات بانتظار الاعتماد', 'Visits awaiting approval') }} <span class="text-muted fs-6">({{ $visits->count() }})</span></h4>
</div>
<p class="text-muted small">{{ dt('الزيارات التي أرسلها موظفو الإدارة ولم تُعتمد بعد. افتح الزيارة، اضغط "فحص" ليقترح الإيجينت الإدارة المسؤولة ودرجة الخطورة، راجع النقاط ثم "اعتماد الزيارة" لإنشاء الطلبات في الإدارات المسؤولة.', 'Visits sent by the department\'s staff and not yet approved. Open a visit, press "Check" so the agent suggests the responsible department and risk, review the points, then "Approve visit" to create the tickets.') }}</p>

@if($departments->isNotEmpty())
<form class="card p-2 mb-3" method="GET">
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-1">{{ dt('الإدارة المُبلِّغة', 'Reporting department') }}</label>
            <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">{{ dt('الكل', 'All') }}</option>
                @foreach($departments as $d)<option value="{{ $d->id }}" @selected($departmentId == $d->id)>{{ $d->name }}</option>@endforeach
            </select></div>
    </div>
</form>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th>{{ dt('الكود', 'Code') }}</th>
                <th>{{ dt('الإدارة', 'Department') }}</th>
                <th>{{ dt('الفرع', 'Branch') }}</th>
                <th>{{ dt('الموظف', 'Recorder') }}</th>
                <th>{{ dt('تاريخ الزيارة', 'Visit date') }}</th>
                <th>{{ dt('أُرسلت', 'Submitted') }}</th>
                <th class="text-center">{{ dt('النقاط', 'Points') }}</th>
                <th class="text-center">{{ dt('مشكلات', 'Issues') }}</th>
                <th class="text-center">{{ dt('بلا إدارة مسؤولة', 'Unrouted') }}</th>
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($visits as $v)
                <tr>
                    <td><a href="{{ route('obs.approval.show', $v) }}" class="fw-semibold text-decoration-none">{{ $v->code }}</a>
                        <span class="badge text-bg-light">{{ $v->type === 'field' ? dt('ميدانية', 'Field') : dt('مكتبية', 'Desk') }}</span></td>
                    <td>{{ optional($v->department)->name }}</td>
                    <td>{{ optional($v->branch)->branch_name ?? dt('كل الفروع', 'All branches') }}</td>
                    <td>{{ optional($v->user)->name }}</td>
                    <td>{{ optional($v->visit_date)->format('Y-m-d') }}</td>
                    <td class="small text-muted">{{ optional($v->submitted_at)->format('Y-m-d H:i') }}</td>
                    <td class="text-center">{{ $v->observations_count }}</td>
                    <td class="text-center">{{ $v->issues_count }}</td>
                    <td class="text-center">@if($v->pending_points > 0)<span class="badge text-bg-warning">{{ $v->pending_points }}</span>@else<span class="text-success"><i class="bi bi-check2"></i></span>@endif</td>
                    <td class="text-end"><a href="{{ route('obs.approval.show', $v) }}" class="btn btn-sm btn-primary"><i class="bi bi-eye"></i> {{ dt('مراجعة', 'Review') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted py-4"><i class="bi bi-check2-circle fs-3 d-block mb-2"></i>{{ dt('لا توجد زيارات بانتظار الاعتماد.', 'No visits are waiting for approval.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
