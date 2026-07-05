@extends('layouts.app')
@section('title', $pr->reference)

@section('content')
<a href="{{ route('purchasing.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-{{ app()->getLocale()==='ar' ? 'right' : 'left' }}"></i> {{ dt('طلبات الشراء','Purchase requests') }}</a>

<div class="d-flex flex-wrap justify-content-between align-items-center mt-1 mb-3">
    <div>
        <h4 class="fw-bold mb-0">{{ $pr->reference }} — {{ $pr->item_name }}</h4>
        <div class="text-muted small">
            {{ $pr->requesting_dept_name ?? optional($pr->branch)->branch_name ?? '—' }}
            · {{ $pr->quantity ? rtrim(rtrim(number_format($pr->quantity,2),'0'),'.').' '.$pr->unit : '' }}
            @if($pr->category) · {{ $pr->category }} @endif
        </div>
    </div>
    <div class="text-end">
        <span class="badge text-bg-{{ $pr->statusColor() }} fs-6">{{ $pr->statusAr() }}</span>
        <span class="badge text-bg-{{ $pr->priority==='urgent'?'danger':($pr->priority==='monthly'?'info':'secondary') }}">{{ $pr->priorityAr() }}</span>
        @if($pr->isDelayed())<span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>{{ dt('متأخر','Delayed') }}</span>@endif
    </div>
</div>

{{-- Timing summary --}}
<div class="row g-2 mb-3">
    @php $t = [
        [dt('تاريخ الطلب','Requested'), optional($pr->requested_at)->format('d M Y')],
        [dt('مدة التسعير','Pricing days'), $pr->pricingDays()!==null ? $pr->pricingDays().' '.dt('يوم','d') : '—'],
        [dt('مدة التنفيذ','Execution days'), $pr->executionDays()!==null ? $pr->executionDays().' '.dt('يوم','d') : '—'],
        [dt('فرق أيام من الطلب','Days since request'), $pr->daysSinceRequest()!==null ? $pr->daysSinceRequest().' '.dt('يوم','d') : '—'],
        [dt('ما تم استلامه','Received'), $pr->received_qty ?? '—'],
    ]; @endphp
    @foreach($t as [$l,$v])
        <div class="col"><div class="card stat-card p-2 text-center h-100"><div class="fw-bold text-primary" style="font-size:1.05rem">{{ $v }}</div><div class="text-muted" style="font-size:.7rem">{{ $l }}</div></div></div>
    @endforeach
</div>

@if(session('status'))<div class="alert alert-success py-2 small">{{ session('status') }}</div>@endif

<div class="row g-3">
    {{-- LEFT: details + timeline --}}
    <div class="col-lg-7">
        <div class="card p-3 mb-3">
            <h6 class="fw-bold mb-2">{{ dt('تفاصيل الطلب','Request details') }}</h6>
            <table class="table table-sm mb-0">
                <tr><td class="text-muted small" style="width:40%">{{ dt('مقدّم الطلب','Requester') }}</td><td class="small">{{ optional($pr->requester)->name ?? $pr->requester_name ?? '—' }}</td></tr>
                <tr><td class="text-muted small">{{ dt('القائم بالشراء','Buyer') }}</td><td class="small">{{ optional($pr->buyer)->name ?? $pr->buyer_name ?? '—' }}</td></tr>
                <tr><td class="text-muted small">{{ dt('المورد المعتمد','Selected vendor') }}</td><td class="small">{{ optional($pr->selectedVendor)->name ?? '—' }}</td></tr>
                <tr><td class="text-muted small">{{ dt('سعر الوحدة / التنفيذ','Unit / total price') }}</td><td class="small">{{ $pr->unit_price ? number_format($pr->unit_price,2) : '—' }} / {{ $pr->execution_price ? number_format($pr->execution_price,2) : '—' }}</td></tr>
                @if($pr->finance_approved_at)<tr><td class="text-muted small">{{ dt('موافقة المالية','Finance approval') }}</td><td class="small text-success">{{ $pr->finance_approved_at->format('d M Y') }} · {{ optional($pr->financeApprover)->name }}</td></tr>@endif
                @if($pr->notes)<tr><td class="text-muted small">{{ dt('ملاحظات','Notes') }}</td><td class="small">{{ $pr->notes }}</td></tr>@endif
            </table>
        </div>

        {{-- Quotations --}}
        <div class="card p-3 mb-3">
            <h6 class="fw-bold mb-2"><i class="bi bi-tags me-1"></i>{{ dt('عروض الأسعار','Quotations') }} ({{ $pr->quotations->count() }})</h6>
            @if($pr->quotations->isEmpty())
                <p class="text-muted small mb-0">{{ dt('لا توجد عروض بعد.','No quotations yet.') }}</p>
            @else
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>{{ dt('المورد','Vendor') }}</th><th class="text-center">{{ dt('سعر الوحدة','Unit') }}</th><th class="text-center">{{ dt('الإجمالي','Total') }}</th><th>{{ dt('التوريد','Supply') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach($pr->quotations as $q)
                        <tr class="{{ $q->is_selected ? 'table-success' : '' }}">
                            <td class="small fw-semibold">{{ $q->vendorLabel() }}</td>
                            <td class="text-center small">{{ $q->unit_price ? number_format($q->unit_price,2) : '—' }}</td>
                            <td class="text-center small">{{ $q->total_price ? number_format($q->total_price,2) : '—' }}</td>
                            <td class="small">{{ $q->supply_duration ?? '—' }}</td>
                            <td class="text-end">
                                @if($q->is_selected)
                                    <span class="badge text-bg-success">{{ dt('معتمد','Selected') }}</span>
                                @elseif($can['purchasing'] && in_array($pr->status,['pricing','new']))
                                    <form method="POST" action="{{ route('purchasing.selectQuotation', $pr) }}" onsubmit="return confirm('{{ dt('اعتماد هذا العرض وإرساله للمالية؟','Select and send to finance?') }}')">
                                        @csrf<input type="hidden" name="quotation_id" value="{{ $q->id }}">
                                        <button class="btn btn-sm btn-outline-primary py-0">{{ dt('اعتماد','Select') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Purchase orders (single + combined) --}}
        @php $allPos = $pr->orders->merge($pr->batchOrders)->unique('id'); @endphp
        @if($allPos->isNotEmpty())
        <div class="card p-3 mb-3">
            <h6 class="fw-bold mb-2"><i class="bi bi-receipt me-1"></i>{{ dt('أوامر التوريد','Purchase orders') }}</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>PO</th><th>{{ dt('المورد','Vendor') }}</th><th class="text-center">{{ dt('القيمة','Amount') }}</th><th>{{ dt('التوريد','Supply') }}</th><th>{{ dt('السداد','Payment') }}</th></tr></thead>
                <tbody>
                @foreach($allPos as $o)
                    <tr><td class="small fw-semibold">{{ $o->po_number }}</td><td class="small">{{ $o->vendorLabel() }}</td>
                        <td class="text-center small">{{ $o->amount ? number_format($o->amount,2) : '—' }}</td>
                        <td class="small">{{ $o->supplyAr() }}</td><td class="small">{{ $o->paymentAr() }}</td></tr>
                    @if($o->relationLoaded('requests') && $o->requests->count() > 1)
                        <tr><td colspan="5" class="small text-muted bg-light">
                            <i class="bi bi-collection me-1"></i>{{ dt('أمر توريد مجمّع يضم','Combined PO covering') }} {{ $o->requests->count() }} {{ dt('طلب:','requests:') }}
                            @foreach($o->requests as $sib)<a href="{{ route('purchasing.show', $sib->id) }}" class="badge text-bg-light border text-decoration-none">{{ $sib->reference }}</a> @endforeach
                        </td></tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Timeline --}}
        <div class="card p-3">
            <h6 class="fw-bold mb-2">{{ dt('السجل الزمني','Timeline') }}</h6>
            @forelse($pr->updates as $u)
                <div class="d-flex gap-2 border-bottom py-2">
                    <i class="bi bi-dot text-primary"></i>
                    <div class="small">
                        <div>{{ $u->note ?? $u->action }}</div>
                        <div class="text-muted" style="font-size:.72rem">{{ $u->created_at->format('d M Y, g:i A') }} @if($u->user)· {{ $u->user->name }}@endif</div>
                    </div>
                </div>
            @empty
                <p class="text-muted small mb-0">{{ dt('لا يوجد سجل (طلب مستورد).','No timeline (imported record).') }}</p>
            @endforelse
        </div>
    </div>

    {{-- RIGHT: actions --}}
    <div class="col-lg-5">
        <div class="card p-3 mb-3">
            <h6 class="fw-bold mb-3">{{ dt('الإجراءات','Actions') }}</h6>

            {{-- new → assign buyer --}}
            @if($pr->status==='new' && $can['purchasing'])
                <form method="POST" action="{{ route('purchasing.assignBuyer', $pr) }}" class="mb-3">
                    @csrf
                    <label class="form-label small fw-semibold">{{ dt('تعيين القائم بالشراء وبدء التسعير','Assign buyer & start pricing') }}</label>
                    <div class="input-group input-group-sm">
                        <select name="buyer_id" class="form-select" required><option value="">{{ dt('اختر…','Select…') }}</option>@foreach($buyers as $b)<option value="{{ $b->id }}">{{ $b->name }}{{ $b->job_title ? ' · '.$b->job_title : '' }}</option>@endforeach</select>
                        <button class="btn btn-primary">{{ dt('بدء','Start') }}</button>
                    </div>
                </form>
            @endif

            {{-- pricing → add quotation --}}
            @if(in_array($pr->status,['new','pricing']) && $can['purchasing'])
                <form method="POST" action="{{ route('purchasing.quotation', $pr) }}" class="mb-2 border rounded p-2">
                    @csrf
                    <label class="form-label small fw-semibold">{{ dt('إضافة عرض سعر','Add quotation') }}</label>
                    <select name="vendor_id" class="form-select form-select-sm mb-1"><option value="">{{ dt('المورد…','Vendor…') }}</option>@foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach</select>
                    <div class="row g-1">
                        <div class="col"><input name="unit_price" type="number" step="any" class="form-control form-control-sm" placeholder="{{ dt('سعر الوحدة','Unit price') }}"></div>
                        <div class="col"><input name="total_price" type="number" step="any" class="form-control form-control-sm" placeholder="{{ dt('الإجمالي','Total') }}"></div>
                    </div>
                    <input name="supply_duration" class="form-control form-control-sm mt-1" placeholder="{{ dt('مدة التوريد','Supply duration') }}">
                    <button class="btn btn-sm btn-outline-primary w-100 mt-2">{{ dt('إضافة العرض','Add quotation') }}</button>
                </form>
                <p class="text-muted" style="font-size:.72rem">{{ dt('أضف عروض الموردين ثم اعتمد الأفضل من جدول العروض لإرساله للمالية.','Add vendor quotes, then select the best from the quotations table to send to finance.') }}</p>
            @endif

            {{-- finance_review → approve/reject --}}
            @if($pr->status==='finance_review')
                @if($can['finance'])
                    <form method="POST" action="{{ route('purchasing.finance', $pr) }}" class="mb-2">
                        @csrf
                        <label class="form-label small fw-semibold">{{ dt('قرار المالية','Finance decision') }}</label>
                        <textarea name="note" class="form-control form-control-sm mb-2" rows="2" placeholder="{{ dt('ملاحظة (اختياري)','Note (optional)') }}"></textarea>
                        <div class="d-flex gap-2">
                            <button name="decision" value="approve" class="btn btn-success btn-sm flex-fill">{{ dt('موافقة','Approve') }}</button>
                            <button name="decision" value="reject" class="btn btn-outline-danger btn-sm flex-fill">{{ dt('رفض','Reject') }}</button>
                        </div>
                    </form>
                @else
                    <div class="alert alert-warning py-2 small mb-2">{{ dt('بانتظار موافقة الإدارة المالية.','Awaiting finance approval.') }}</div>
                @endif
            @endif

            {{-- po_issued → issue PO / start execution --}}
            @if($pr->status==='po_issued' && $can['purchasing'])
                @if($pr->orders->isEmpty())
                    <form method="POST" action="{{ route('purchasing.po', $pr) }}" class="mb-2 border rounded p-2">
                        @csrf
                        <label class="form-label small fw-semibold">{{ dt('إصدار أمر توريد','Issue purchase order') }}</label>
                        <input name="amount" type="number" step="any" class="form-control form-control-sm mb-1" placeholder="{{ dt('قيمة الأمر','Amount') }}" value="{{ $pr->execution_price }}">
                        <input name="expected_delivery_at" type="date" class="form-control form-control-sm mb-1">
                        <select name="payment_status" class="form-select form-select-sm mb-2"><option value="">{{ dt('حالة السداد','Payment') }}</option><option value="cash">{{ dt('نقدي','Cash') }}</option><option value="cheque">{{ dt('شيك','Cheque') }}</option><option value="credit">{{ dt('آجل','Credit') }}</option></select>
                        <button class="btn btn-sm btn-primary w-100">{{ dt('إصدار PO','Issue PO') }}</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('purchasing.execute', $pr) }}">@csrf<button class="btn btn-sm btn-primary w-100">{{ dt('بدء التنفيذ','Start execution') }}</button></form>
            @endif

            {{-- executing → receive --}}
            @if($pr->status==='executing' && $can['purchasing'])
                <form method="POST" action="{{ route('purchasing.receive', $pr) }}" class="border rounded p-2">
                    @csrf
                    <label class="form-label small fw-semibold">{{ dt('تسجيل الاستلام','Mark received') }}</label>
                    <input name="received_qty" class="form-control form-control-sm mb-1" placeholder="{{ dt('ما تم استلامه','Received qty') }}">
                    <select name="supply_status" class="form-select form-select-sm mb-2"><option value="full">{{ dt('توريد كامل','Full') }}</option><option value="partial">{{ dt('جزئي','Partial') }}</option><option value="over">{{ dt('بالزيادة','Over') }}</option></select>
                    <button class="btn btn-sm btn-success w-100">{{ dt('تم الاستلام والشراء','Received & purchased') }}</button>
                </form>
            @endif

            @if($pr->status==='purchased')
                <div class="alert alert-success py-2 small mb-0"><i class="bi bi-check-circle me-1"></i>{{ dt('اكتمل الطلب وتم الشراء.','Completed & purchased.') }}</div>
            @endif

            {{-- terminal actions --}}
            @if($pr->isOpen() && ($can['purchasing'] || $can['requester']))
                <form method="POST" action="{{ route('purchasing.status', $pr) }}" class="mt-3 pt-2 border-top">
                    @csrf
                    <label class="form-label small text-muted">{{ dt('إجراء آخر','Other action') }}</label>
                    <div class="input-group input-group-sm">
                        <select name="status" class="form-select">
                            <option value="cancelled">{{ dt('إلغاء','Cancel') }}</option>
                            <option value="postponed">{{ dt('تأجيل','Postpone') }}</option>
                            <option value="sufficed">{{ dt('تم الاكتفاء','Sufficed') }}</option>
                            <option value="rejected">{{ dt('رفض','Reject') }}</option>
                        </select>
                        <button class="btn btn-outline-secondary">{{ dt('تطبيق','Apply') }}</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
