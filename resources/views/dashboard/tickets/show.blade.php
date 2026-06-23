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
            <ul class="list-unstyled mb-0">
                @forelse($ticket->updates as $u)
                    <li class="border-start ps-3 pb-3 position-relative">
                        <span class="position-absolute bg-primary rounded-circle" style="width:9px;height:9px;left:-5px;top:4px"></span>
                        <div class="small">
                            <strong>{{ ucwords(str_replace('_',' ',$u->action)) }}</strong>
                            @if($u->to_status) → <em>{{ str_replace('_',' ',$u->to_status) }}</em> @endif
                        </div>
                        @if($u->note)<div class="small text-muted" dir="auto">{{ $u->note }}</div>@endif
                        @if($u->evidence_path)<a href="{{ str_starts_with($u->evidence_path,'http') ? $u->evidence_path : asset('storage/'.$u->evidence_path) }}" target="_blank" class="small">View {{ $u->evidence_kind }} photo</a>@endif
                        <div class="text-muted" style="font-size:.72rem">{{ optional($u->user)->name }} · {{ $u->created_at->format('d M H:i') }}</div>
                    </li>
                @empty
                    <li class="text-muted small">No activity yet.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="col-lg-5">
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
                <p class="small text-muted mb-0">{{ $ticket->status === 'closed' ? 'التذكرة مقفولة.' : 'لا توجد إجراءات الآن — بانتظار الإسناد.' }}</p>
            @endif
        </div>

        @if(in_array($ticket->status, ['open','assigned','postponed','not_fixed','rejected']))
        <div class="card p-3">
            <h6 class="fw-bold">الإسناد لفني</h6>
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
                <button class="btn btn-sm btn-primary w-100">إسناد</button>
            </form>
        </div>
        @endif
    </div>
</div>
@endsection
