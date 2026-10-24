@extends('layouts.app')
@section('title', $observation->reference)

@section('content')
@use('App\Support\ObservationLabels')
@php
    // Photo paths come from clients: only a relative storage path becomes a URL, never an absolute one.
    $photoUrl = fn (?string $path) => ObservationLabels::storageUrl($path);
    $isDraft = $observation->visit?->status === 'draft';
    $photoKinds = [
        'before' => dt('قبل', 'Before'),
        'after' => dt('بعد', 'After'),
        'other' => dt('أخرى', 'Other'),
    ];
@endphp

<a href="{{ route('observations.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-right"></i> {{ dt('الملاحظات', 'Observations') }}</a>
<div class="d-flex flex-wrap align-items-center gap-2 mt-1 mb-3">
    <h4 class="fw-bold mb-0">{{ $observation->reference }}</h4>
    @include('dashboard.observations.partials.status-pill', ['status' => $observation->status])
    @include('dashboard.observations.partials.risk-badge', ['risk' => $observation->riskLevel])
    <span class="badge text-bg-light border">{{ ObservationLabels::kind($observation->kind) }}</span>
    @if($isDraft)<span class="badge text-bg-secondary">{{ dt('مسودة', 'Draft') }}</span>@endif
    <span class="text-muted small" dir="auto">{{ $observation->title }}</span>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0 small">
            @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        {{-- Details --}}
        <div class="card p-3 mb-3">
            <h6 class="fw-bold">{{ dt('التفاصيل', 'Details') }}</h6>
            <dl class="row small mb-0">
                <dt class="col-4">{{ dt('الفرع', 'Branch') }}</dt>
                <dd class="col-8">{{ $observation->branch?->branch_name ?? dt('بدون فرع', 'No branch') }}</dd>

                <dt class="col-4">{{ dt('تاريخ الحدث', 'Event date') }}</dt>
                <dd class="col-8">
                    @if($observation->event_date)
                        {{ $observation->event_date->format('Y-m-d') }}
                    @elseif($observation->visit?->visit_date)
                        {{ $observation->visit->visit_date->format('Y-m-d') }} <span class="text-muted">({{ dt('تاريخ غير مؤكد', 'Date not confirmed') }})</span>
                    @else
                        —
                    @endif
                </dd>

                <dt class="col-4">{{ dt('الإدارة المُبلِّغة', 'Reporting department') }}</dt>
                <dd class="col-8">{{ $observation->reportingDepartment?->name ?? '—' }}</dd>

                <dt class="col-4">{{ dt('الإدارة المسؤولة', 'Responsible department') }}</dt>
                <dd class="col-8">{{ $observation->responsibleDepartment?->name ?? '—' }}</dd>

                <dt class="col-4">{{ dt('إدارات للعلم', 'CC departments') }}</dt>
                <dd class="col-8">{{ $ccDepartments->isNotEmpty() ? $ccDepartments->pluck('name')->join('، ') : '—' }}</dd>

                <dt class="col-4">{{ dt('الفئة', 'Category') }}</dt>
                <dd class="col-8">{{ $observation->category ? dt($observation->category->name_ar, $observation->category->name_en ?: $observation->category->name_ar) : '—' }}</dd>

                <dt class="col-4">{{ dt('البند', 'Catalog item') }}</dt>
                <dd class="col-8">{{ $observation->catalogItem ? dt($observation->catalogItem->label_ar, $observation->catalogItem->label_en ?: $observation->catalogItem->label_ar) : '—' }}</dd>

                <dt class="col-4">{{ dt('الوصف', 'Description') }}</dt>
                <dd class="col-8" dir="auto" style="white-space:pre-line">{{ $observation->description }}</dd>

                <dt class="col-4">{{ dt('المنتج / المكان', 'Product / area') }}</dt>
                <dd class="col-8" dir="auto">{{ $observation->product_or_area ?: '—' }}</dd>

                @foreach($extraValues as $label => $value)
                    <dt class="col-4">{{ $label }}</dt>
                    <dd class="col-8" dir="auto">{{ $value }}</dd>
                @endforeach

                @if($observation->rating)
                    <dt class="col-4">{{ dt('التقييم', 'Rating') }}</dt>
                    <dd class="col-8">{{ $observation->rating }}</dd>
                @endif

                <dt class="col-4">{{ dt('مرات التكرار', 'Repeat count') }}</dt>
                <dd class="col-8">{{ $observation->repeat_count }}</dd>

                @if($observation->escalatedFrom)
                    <dt class="col-4">{{ dt('صُعِّدت من', 'Escalated from') }}</dt>
                    <dd class="col-8">@include('dashboard.observations.partials.risk-badge', ['risk' => $observation->escalatedFrom])</dd>
                @endif

                @if($observation->risk_override_reason)
                    <dt class="col-4">{{ dt('سبب تعديل الخطورة', 'Risk change reason') }}</dt>
                    <dd class="col-8" dir="auto">{{ $observation->risk_override_reason }}</dd>
                @endif

                <dt class="col-4">{{ dt('سجّلها', 'Recorded by') }}</dt>
                <dd class="col-8">{{ $observation->creator?->name ?? '—' }} <span class="text-muted">· {{ $observation->created_at?->format('Y-m-d H:i') }}</span></dd>

                <dt class="col-4">{{ dt('الزيارة', 'Visit') }}</dt>
                <dd class="col-8">
                    @if($observation->visit)
                        {{ $observation->visit->code }} <span class="badge text-bg-light border">{{ ObservationLabels::visitType($observation->visit->type) }}</span>
                    @else
                        —
                    @endif
                </dd>

                @if($observation->close_reason || $observation->closed_at)
                    <dt class="col-4">{{ dt('سبب الإغلاق', 'Close reason') }}</dt>
                    <dd class="col-8" dir="auto">{{ $observation->close_reason ?? '—' }}</dd>
                    <dt class="col-4">{{ dt('أغلقها', 'Closed by') }}</dt>
                    <dd class="col-8">{{ $observation->closer?->name ?? '—' }} <span class="text-muted">· {{ $observation->closed_at?->format('Y-m-d H:i') }}</span></dd>
                @endif
            </dl>
        </div>

        {{-- Photos --}}
        <div class="card p-3 mb-3">
            <h6 class="fw-bold">{{ dt('الصور', 'Photos') }}</h6>
            @if($photoGroups->isEmpty())
                <p class="small text-muted mb-0">{{ dt('لا توجد صور.', 'No photos.') }}</p>
            @else
                @foreach($photoKinds as $kind => $kindLabel)
                    @if($photoGroups->has($kind))
                        <div class="small fw-semibold mb-1">{{ $kindLabel }}</div>
                        <div class="d-flex gap-2 flex-wrap mb-2">
                            @foreach($photoGroups[$kind] as $photo)
                                @continue($photoUrl($photo->path) === null)
                                <a href="{{ $photoUrl($photo->path) }}" target="_blank" rel="noopener"><img src="{{ $photoUrl($photo->path) }}" alt="{{ $kindLabel }}" style="height:90px;border-radius:.4rem"></a>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @endif
        </div>

        {{-- Ticket --}}
        <div class="card p-3 mb-3">
            <h6 class="fw-bold">{{ dt('التيكت', 'Ticket') }}</h6>
            @if($ticket)
                <dl class="row small mb-3">
                    <dt class="col-4">{{ dt('المرجع', 'Reference') }}</dt>
                    <dd class="col-8"><a href="{{ route('tickets.show', $ticket) }}">{{ $ticket->reference }}</a></dd>
                    <dt class="col-4">{{ dt('الحالة', 'Status') }}</dt>
                    <dd class="col-8">@include('partials.status-badge', ['status' => $ticket->status])</dd>
                    <dt class="col-4">{{ dt('الأولوية', 'Priority') }}</dt>
                    <dd class="col-8">@include('partials.priority-badge', ['priority' => $ticket->priority])</dd>
                    <dt class="col-4">{{ dt('الإدارة', 'Department') }}</dt>
                    <dd class="col-8">{{ $ticket->department?->name ?? '—' }}</dd>
                    <dt class="col-4">{{ dt('موعد الاستحقاق', 'Due') }}</dt>
                    <dd class="col-8">
                        {{ $ticket->due_at?->format('Y-m-d H:i') ?? '—' }}
                        @if($ticket->isOverdue())<span class="badge text-bg-danger">{{ dt('متأخر', 'Overdue') }}</span>@endif
                    </dd>
                    <dt class="col-4">{{ dt('المسؤول', 'Assignee') }}</dt>
                    <dd class="col-8">{{ $ticket->assignee?->name ?? dt('غير معيّن', 'Unassigned') }}</dd>
                </dl>
            @else
                <p class="small text-muted">{{ dt('لا يوجد تيكت لهذه الملاحظة.', 'This observation has no ticket.') }}</p>
            @endif

            @if($tickets->isNotEmpty())
                <h6 class="fw-bold small">{{ dt('سجل التيكتات', 'Ticket timeline') }}</h6>
                @foreach($tickets as $t)
                    <div class="d-flex align-items-center gap-2 mt-2 mb-2">
                        <a href="{{ route('tickets.show', $t) }}" class="fw-semibold small text-decoration-none">{{ $t->reference }}</a>
                        @include('partials.status-badge', ['status' => $t->status])
                    </div>
                    @include('partials.ticket-timeline', ['updates' => $t->updates, 'relativeEvidenceOnly' => true])
                @endforeach
            @endif
        </div>

        {{-- Verifications --}}
        <div class="card p-3 mb-3">
            <h6 class="fw-bold">{{ dt('التحققات', 'Verifications') }}</h6>
            @forelse($observation->verifications as $v)
                <div class="border-bottom pb-2 mb-2 small">
                    <span class="badge text-bg-{{ ObservationLabels::verificationColor($v->result) }}">{{ ObservationLabels::verificationResult($v->result) }}</span>
                    <span class="text-muted">{{ $v->verifier?->name ?? '—' }} · {{ $v->verified_at?->format('Y-m-d H:i') }}</span>
                    @if($v->notes)<div class="text-muted mt-1" dir="auto">{{ $v->notes }}</div>@endif
                </div>
            @empty
                <p class="small text-muted mb-0">{{ dt('لا توجد تحققات.', 'No verifications.') }}</p>
            @endforelse
        </div>

        {{-- Classification history --}}
        <div class="card p-3 mb-3">
            <h6 class="fw-bold">{{ dt('سجل التصنيف', 'Classification history') }}</h6>
            @if(! $observation->classification)
                <p class="small text-muted mb-0">{{ dt('لا يوجد فحص تصنيف لهذه الملاحظة.', 'No classification check for this observation.') }}</p>
            @else
                <div class="row g-2">
                    @foreach($classificationColumns as $column)
                        <div class="col-md-4">
                            <div class="border rounded p-2 h-100">
                                <div class="fw-semibold small mb-1">{{ $column['title'] }}</div>
                                @if($column['rows'] === null)
                                    <div class="small text-muted">—</div>
                                @else
                                    <dl class="small mb-0">
                                        @foreach($column['rows'] as $label => $value)
                                            <dt class="text-muted fw-normal">{{ $label }}</dt>
                                            <dd class="mb-1" dir="auto">{{ $value }}</dd>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Actions: none on a draft-visit row (its author's next autosave would undo them). --}}
    <div class="col-lg-4">
        @if($isDraft)
            <div class="card p-3 mb-3 small text-muted">
                <span><span class="badge text-bg-secondary">{{ dt('مسودة', 'Draft') }}</span> {{ dt('الزيارة لم تُرسل بعد؛ الإجراءات تظهر بعد الإرسال.', 'The visit is not submitted yet; actions appear once it is submitted.') }}</span>
            </div>
        @else
        @can('reroute', $observation)
            <div class="card p-3 mb-3">
                <h6 class="fw-bold">{{ dt('إعادة توجيه', 'Reroute') }}</h6>
                <form method="POST" action="{{ route('observations.reroute', $observation) }}">
                    @csrf
                    <select name="responsible_department_id" class="form-select form-select-sm mb-2" required>
                        <option value="">{{ dt('اختر الإدارة المسؤولة…', 'Choose the responsible department…') }}</option>
                        @foreach($rerouteDepartments as $d)
                            <option value="{{ $d->id }}" @selected((string) old('responsible_department_id') === (string) $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                    <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="{{ dt('السبب (اختياري)', 'Reason (optional)') }}"></textarea>
                    <button class="btn btn-sm btn-primary w-100">{{ dt('إعادة توجيه', 'Reroute') }}</button>
                </form>
            </div>
        @endcan

        @can('raiseRisk', $observation)
            @if($raiseLevels->isNotEmpty())
                <div class="card p-3 mb-3">
                    <h6 class="fw-bold">{{ dt('رفع درجة الخطورة', 'Raise risk') }}</h6>
                    <form method="POST" action="{{ route('observations.raiseRisk', $observation) }}">
                        @csrf
                        <select name="risk_level_slug" class="form-select form-select-sm mb-2" required>
                            @foreach($raiseLevels as $level)
                                <option value="{{ $level->slug }}" @selected(old('risk_level_slug') === $level->slug)>{{ dt($level->name_ar, $level->name_en) }}</option>
                            @endforeach
                        </select>
                        <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" required placeholder="{{ dt('السبب (مطلوب)', 'Reason (required)') }}"></textarea>
                        <button class="btn btn-sm btn-warning w-100">{{ dt('رفع الخطورة', 'Raise risk') }}</button>
                    </form>
                </div>
            @endif
        @endcan

        @can('closeNoAction', $observation)
            @if($canClose)
                <div class="card p-3 mb-3">
                    <h6 class="fw-bold">{{ dt('إغلاق بدون إجراء', 'Close without action') }}</h6>
                    <p class="small text-muted mb-2">{{ dt('مثلاً: مكررة أو خطأ تسجيل. التيكت المفتوح هيتقفل.', 'E.g. a duplicate or a recording error. An open ticket is closed too.') }}</p>
                    <button type="button" class="btn btn-sm btn-outline-dark w-100" data-bs-toggle="modal" data-bs-target="#closeNoActionModal">{{ dt('إغلاق بدون إجراء', 'Close without action') }}</button>
                </div>

                <div class="modal fade" id="closeNoActionModal" tabindex="-1" aria-labelledby="closeNoActionTitle" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <form method="POST" action="{{ route('observations.closeNoAction', $observation) }}" class="modal-content">
                            @csrf
                            <div class="modal-header">
                                <h5 class="modal-title" id="closeNoActionTitle">{{ dt('إغلاق الملاحظة بدون إجراء؟', 'Close this observation without action?') }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ dt('إغلاق', 'Close') }}"></button>
                            </div>
                            <div class="modal-body small">
                                <p class="text-muted">{{ dt('التيكت المفتوح هيتقفل ولن يُحسب كحل.', 'An open ticket is closed too and does not count as resolved.') }}</p>
                                <label class="form-label" for="closeNoActionReason">{{ dt('السبب (مطلوب)', 'Reason (required)') }}</label>
                                <textarea name="reason" id="closeNoActionReason" class="form-control form-control-sm" rows="3" maxlength="1000" required>{{ old('reason') }}</textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">{{ dt('إلغاء', 'Cancel') }}</button>
                                <button type="submit" class="btn btn-sm btn-dark">{{ dt('إغلاق بدون إجراء', 'Close without action') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endcan
        @endif
    </div>
</div>
@endsection
