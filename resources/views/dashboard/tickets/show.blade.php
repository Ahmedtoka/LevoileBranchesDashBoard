@extends('layouts.app')
@section('title', $ticket->reference)

@section('content')
<a href="{{ route('tickets.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-left"></i> Tickets</a>
<div class="d-flex justify-content-between align-items-center mt-1 mb-3">
    <h4 class="fw-bold mb-0">{{ $ticket->reference }} — {{ $ticket->title }}</h4>
    <div>@include('partials.priority-badge', ['priority' => $ticket->priority]) @include('partials.status-badge', ['status' => $ticket->status])</div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card p-3 mb-3">
            <h6 class="fw-bold">Details</h6>
            <dl class="row small mb-0">
                <dt class="col-4">Branch</dt><dd class="col-8">{{ optional($ticket->branch)->branch_name }}</dd>
                <dt class="col-4">Department</dt><dd class="col-8">{{ optional($ticket->department)->name ?? '—' }}</dd>
                <dt class="col-4">Created by</dt><dd class="col-8">{{ optional($ticket->creator)->name }}</dd>
                <dt class="col-4">Assignee</dt><dd class="col-8">{{ optional($ticket->assignee)->name ?? 'Unassigned' }}</dd>
                <dt class="col-4">From visit</dt><dd class="col-8">@if($ticket->visit_id)<a href="{{ route('visits.show', $ticket->visit_id) }}">#{{ $ticket->visit_id }} {{ optional($ticket->visit->template ?? null)->name }}</a>@else — @endif</dd>
                <dt class="col-4">Checklist item</dt><dd class="col-8">{{ optional($ticket->question)->question_text ?? '—' }}</dd>
                <dt class="col-4">Comment</dt><dd class="col-8" dir="auto">{{ $ticket->description ?? '—' }}</dd>
                <dt class="col-4">Due</dt><dd class="col-8">{{ optional($ticket->due_at)->format('d M Y H:i') ?? '—' }} @if($ticket->isOverdue())<span class="badge text-bg-danger">Overdue</span>@endif</dd>
                <dt class="col-4">Reopened</dt><dd class="col-8">{{ $ticket->reopen_count }}×</dd>
            </dl>
        </div>

        @if($ticket->answer && $ticket->answer->evidence->count())
            <div class="card p-3 mb-3">
                <h6 class="fw-bold">Evidence</h6>
                <div class="d-flex gap-2 flex-wrap">
                    @foreach($ticket->answer->evidence as $e)
                        <a href="{{ $e->url }}" target="_blank"><img src="{{ $e->url }}" style="height:90px;border-radius:.4rem"></a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="card p-3">
            <h6 class="fw-bold">Timeline</h6>
            @include('partials.ticket-timeline', ['updates' => $ticket->updates])
        </div>
    </div>

    <div class="col-lg-5">
        @if($canReply)
        <div class="card p-3 mb-3 border-primary">
            <h6 class="fw-bold">{{ dt('رد الإدارة المسؤولة', 'Department reply') }}</h6>
            <p class="small text-muted mb-2">{{ dt('اكتب ملاحظة ثم اختر: جاري العمل عليها (يُعيّن الطلب لك ويبدأ التنفيذ) أو تم الحل (يُرسل للاعتماد؛ الصورة اختيارية).', 'Write a note then choose: working on it (assigns the ticket to you and starts work) or solved (sent for approval; the photo is optional).') }}</p>
            @error('note')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
            <form method="POST" action="{{ route('tickets.reply', $ticket) }}" enctype="multipart/form-data" id="replyForm">
                @csrf
                <input type="hidden" name="state" id="replyState" value="working">
                <textarea name="note" class="form-control form-control-sm mb-2 @error('note') is-invalid @enderror" rows="2" required minlength="3" placeholder="{{ dt('ملاحظة إجبارية…', 'Mandatory note…') }}">{{ old('note') }}</textarea>
                <input type="file" name="photo" accept="image/*" class="form-control form-control-sm mb-2" title="{{ dt('صورة بعد الحل (اختيارية)', 'After photo (optional)') }}">
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-warning flex-fill" onclick="document.getElementById('replyState').value='working'"><i class="bi bi-tools me-1"></i>{{ dt('جاري العمل عليها', 'Working on it') }}</button>
                    <button class="btn btn-sm btn-success flex-fill" onclick="document.getElementById('replyState').value='solved'"><i class="bi bi-check2-circle me-1"></i>{{ dt('تم الحل', 'Solved') }}</button>
                </div>
            </form>
        </div>
        @endif
        <div class="card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">دورة الحالة</h6>
                @include('partials.status-badge', ['status' => $ticket->status])
            </div>
            @php $actions = \App\Models\Ticket::nextActions($ticket->status); @endphp
            @if(count($actions))
                <div class="small text-muted mb-2">الإجراءات المتاحة (للأمام فقط):</div>
                @foreach($actions as $to => $a)
                    <form method="POST" action="{{ route('tickets.transition', $ticket) }}" class="mb-2">
                        @csrf
                        <input type="hidden" name="status" value="{{ $to }}">
                        @if(in_array($to, ['postponed','not_fixed','rejected']))
                            <textarea name="note" class="form-control form-control-sm mb-1" rows="2" placeholder="السبب / ملاحظة"></textarea>
                        @endif
                        <button class="btn btn-sm btn-{{ $a['color'] }} w-100">{{ $a['label'] }}</button>
                    </form>
                @endforeach
            @else
                <p class="small text-muted mb-0">{{ $ticket->status === 'closed' ? 'التذكرة مقفولة.' : 'لا توجد إجراءات الآن — بانتظار التعيين.' }}</p>
            @endif
        </div>

        @if($ticket->status !== 'closed')
        <div class="card p-3">
            <h6 class="fw-bold">التعيين / إعادة التعيين لفني</h6>
            <form method="POST" action="{{ route('tickets.assign', $ticket) }}">
                @csrf
                <select name="employee_id" class="form-select form-select-sm mb-2" required>
                    <option value="">اختر فني…</option>
                    @foreach($employees as $e)<option value="{{ $e->id }}" @selected($ticket->assigned_to === $e->id)>{{ $e->name }}</option>@endforeach
                </select>
                <div class="row g-2 mb-2">
                    <div class="col-7"><input type="datetime-local" name="scheduled_at" class="form-control form-control-sm" title="ميعاد الذهاب"></div>
                    <div class="col-5">
                        <select name="priority" class="form-select form-select-sm">
                            @foreach(\App\Models\Ticket::PRIORITIES as $p)<option value="{{ $p }}" @selected($ticket->priority===$p)>{{ ucfirst($p) }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <button class="btn btn-sm btn-primary w-100">تعيين</button>
            </form>
        </div>
        @endif
    </div>
</div>
@endsection
