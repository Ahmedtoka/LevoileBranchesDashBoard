@extends('layouts.app')
@section('title', dt('مهام إدارتي', 'My department\'s tasks'))
@include('dashboard.cctv.partials.style')

@section('content')
@php
    use App\Models\Ticket;
    use App\Support\CctvReport;

    $tabs = [
        'incoming' => [dt('واردة لإدارتي', 'Sent to us'), 'bi-inbox', dt('مشاكل بعتتها إدارات تانية لإدارتك، رد عليها وقول اتحلت.', 'Problems other departments sent to yours: answer, then mark solved.')],
        'approve' => [dt('مستنية اعتمادنا', 'Waiting for our approval'), 'bi-patch-check', dt('مشاكل إدارتك بعتتها، والإدارة المسئولة قالت اتحلت. اعتمد واقفل، أو رجّعها.', 'Problems your department sent that were marked solved: approve and close, or send back.')],
        'closed' => [dt('اتقفلت', 'Closed'), 'bi-check2-all', dt('اللي اتقفل في آخر 30 يوم.', 'Closed in the last 30 days.')],
    ];
    $with = fn (array $c) => route('tasks.index', array_filter(array_merge(request()->query(), $c, ['page' => null]), fn ($v) => $v !== null && $v !== ''));
@endphp

<div class="cv tk">
<div class="tk-head">
    <div>
        <h4 class="fw-bold mb-1">{{ dt('مهام إدارتي', 'My department\'s tasks') }}</h4>
        <p class="tk-sub mb-0">{{ $department->name }}</p>
    </div>
    @if($departments->isNotEmpty())
        <form method="GET" action="{{ route('tasks.index') }}" class="tk-dept">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <label>{{ dt('الإدارة', 'Department') }}
                <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    @foreach($departments as $d)<option value="{{ $d->id }}" @selected($d->id === $department->id)>{{ $d->name }}</option>@endforeach
                </select>
            </label>
        </form>
    @endif
</div>

@if($errors->any())<div class="alert alert-danger py-2 mb-3">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

<nav class="tk-tabs" aria-label="{{ dt('المهام', 'Tasks') }}">
    @foreach($tabs as $key => [$label, $icon])
        <a href="{{ $with(['tab' => $key, 'late' => null]) }}" @class(['on' => $tab === $key])>
            <i class="bi {{ $icon }}"></i><span>{{ $label }}</span><b>{{ $counts[$key] }}</b>
        </a>
    @endforeach
    @if($counts['late'])
        <a href="{{ $with(['tab' => 'incoming', 'late' => '1']) }}" @class(['tk-late', 'on' => $tab === 'incoming' && request('late') === '1'])>
            <i class="bi bi-alarm"></i><span>{{ dt('متأخرة', 'Overdue') }}</span><b>{{ $counts['late'] }}</b>
        </a>
    @endif
</nav>
<p class="tk-hint">{{ $tabs[$tab][2] }}</p>

<ol class="tk-list">
@forelse($tasks as $t)
    @php
        $o = $t->observation;
        $x = (array) ($o?->extra_values ?? []);
        $late = $t->isOverdue();
        $last = $t->updates->first();
        $from = $o?->reportingDepartment?->name;
    @endphp
    <li @class(['tk-card', 'is-late' => $late && $tab === 'incoming', 'is-done' => $tab === 'closed'])>
        <div class="tk-main">
            <div class="tk-meta">
                @if($tab === 'incoming')
                    <span class="tk-from"><i class="bi bi-box-arrow-in-down-left"></i> {{ dt('من', 'From') }} {{ $from }}</span>
                @else
                    <span class="tk-from"><i class="bi bi-box-arrow-up-right"></i> {{ dt('عند', 'With') }} {{ $t->department?->name }}</span>
                @endif
                <span>{{ CctvReport::storeName($t->branch?->branch_name) }}</span>
                <span>{{ ($o?->event_date ?? $t->created_at)->format('j/n') }}</span>
                <a href="{{ route('tickets.show', $t) }}" class="cv-ref">{{ $t->reference }}</a>
            </div>
            <div class="tk-title">
                @if($o?->category)<small>{{ $o->category->name_ar }}</small>@endif
                <b>{{ $o?->catalogItem?->label_ar ?? $t->title }}</b>
            </div>
            @if($o && $o->description !== $o->catalogItem?->label_ar)<p class="tk-desc">{{ $o->description }}</p>@endif
            <div class="tk-facts">
                @if(isset($x['amount']))<span class="tk-fact"><i class="bi bi-cash"></i> {{ number_format((float) $x['amount']) }} {{ dt('ج', 'EGP') }}</span>@endif
                @if($o?->employee || $o?->employee_name)<span class="tk-fact"><i class="bi bi-person"></i> {{ $o->employee?->name ?? $o->employee_name }}</span>@endif
                @php $mins = $x['minutes_late'] ?? $x['minutes'] ?? null; @endphp
                @if($mins)<span class="tk-fact"><i class="bi bi-clock"></i> {{ $mins }} {{ dt('دقيقة', 'min') }}</span>@endif
                @if(! empty($x['lp_referred']))<span class="tk-fact"><i class="bi bi-shield-exclamation"></i> {{ dt('منع الخسائر', 'Loss prevention') }}</span>@endif
            </div>
            @if($last)
                <div class="tk-last"><b>{{ $last->user?->name ?? dt('السيستم', 'System') }}:</b> {{ $last->note }} <small>{{ $last->created_at->diffForHumans() }}</small></div>
            @endif
        </div>

        <div class="tk-side">
            <div class="tk-status">
                <span class="badge rounded-pill text-bg-{{ $t->status === 'closed' ? 'success' : ($t->status === 'waiting_approval' ? 'info' : ($t->status === 'rejected' ? 'danger' : 'warning')) }}">{{ Ticket::STATUS_AR[$t->status] ?? $t->status }}</span>
                @if($tab === 'closed')
                    <small>{{ dt('اتقفلت', 'Closed') }} {{ $t->closed_at?->diffForHumans() }}</small>
                @elseif($t->due_at)
                    <small @class(['tk-due', 'is-late' => $late])>{{ $late ? dt('متأخرة', 'Overdue') : dt('المطلوب', 'Due') }} {{ $t->due_at->diffForHumans() }}</small>
                @endif
            </div>

            @if($tab === 'incoming' && $canReply($t))
                <form method="POST" action="{{ route('tickets.reply', $t) }}" class="tk-act">
                    @csrf
                    <textarea name="note" rows="2" class="form-control form-control-sm" required minlength="3" placeholder="{{ dt('ردك: اتعمل إيه؟', 'Your reply: what was done?') }}"></textarea>
                    <div class="tk-btns">
                        <button name="state" value="working" class="btn btn-sm btn-outline-primary">{{ $t->status === 'in_progress' ? dt('سجّل متابعة', 'Add an update') : dt('شغالين عليها', 'Working on it') }}</button>
                        <button name="state" value="solved" class="btn btn-sm btn-success"><i class="bi bi-check2"></i> {{ dt('اتحلت', 'Solved') }}</button>
                    </div>
                </form>
            @elseif($tab === 'approve' && $canClose($t))
                <form method="POST" action="{{ route('tickets.transition', $t) }}" class="tk-act">
                    @csrf
                    <textarea name="note" rows="2" class="form-control form-control-sm" placeholder="{{ dt('ملاحظة (لازم لو هترجّعها)', 'Note (needed to send back)') }}"></textarea>
                    <div class="tk-btns">
                        <button name="status" value="rejected" class="btn btn-sm btn-outline-danger" data-needs-note>{{ dt('رجّعها', 'Send back') }}</button>
                        <button name="status" value="closed" class="btn btn-sm btn-success"><i class="bi bi-check2-all"></i> {{ dt('اعتمد واقفل', 'Approve and close') }}</button>
                    </div>
                </form>
            @elseif($tab !== 'closed')
                <a href="{{ route('tickets.show', $t) }}" class="btn btn-sm btn-light">{{ dt('التفاصيل', 'Details') }}</a>
            @endif
        </div>
    </li>
@empty
    <li class="tk-empty">
        <i class="bi {{ $tabs[$tab][1] }}"></i>
        <p class="mb-0 fw-semibold">{{ $tab === 'closed' ? dt('مفيش حاجة اتقفلت في آخر 30 يوم.', 'Nothing closed in the last 30 days.') : dt('مفيش مهام هنا دلوقتي.', 'Nothing here right now.') }}</p>
    </li>
@endforelse
</ol>

@if($tasks->hasPages())<div class="cv-pager">{{ $tasks->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection

@push('head')
<style>
    .tk-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .8rem; margin-bottom: 1rem; }
    .tk-sub { color: var(--muted); font-size: .9rem; }
    .tk-dept label { display: flex; align-items: center; gap: .4rem; font-size: .8rem; color: var(--ink-2); margin: 0; }
    .tk-dept select { inline-size: auto; min-inline-size: 11rem; }
    .tk-tabs { display: flex; flex-wrap: wrap; gap: .5rem; }
    .tk-tabs a { display: flex; align-items: center; gap: .5rem; padding: .55rem .9rem; border-radius: 12px; background: var(--surface); color: var(--ink-2); font-weight: 600; font-size: .9rem; box-shadow: 0 1px 2px rgba(74,15,51,.06); border: 1.5px solid transparent; transition: border-color .15s, background .15s; }
    .tk-tabs a b { background: #f4eff2; color: var(--ink); border-radius: 8px; padding: 0 .5rem; font-size: .85rem; min-inline-size: 1.6rem; text-align: center; }
    .tk-tabs a:hover { border-color: var(--brand); }
    .tk-tabs a.on { background: var(--brand); color: #fff; }
    .tk-tabs a.on b { background: rgba(255,255,255,.22); color: #fff; }
    .tk-tabs a.tk-late { color: var(--up); }
    .tk-tabs a.tk-late b { background: var(--up-bg); color: var(--up); }
    .tk-tabs a.tk-late.on { background: var(--up); color: #fff; }
    .tk-hint { font-size: .8rem; color: var(--muted); margin: .6rem 0 .9rem; }

    .tk-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .6rem; }
    .tk-card { display: grid; grid-template-columns: minmax(0, 1fr) minmax(16rem, 22rem); gap: 1rem; background: var(--surface); border-radius: 14px; padding: .9rem 1rem; box-shadow: 0 1px 2px rgba(74,15,51,.05); border-inline-start: 4px solid var(--line); }
    .tk-card.is-late { border-inline-start-color: var(--up); }
    .tk-card.is-done { opacity: .85; }
    .tk-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .3rem .8rem; font-size: .76rem; color: var(--muted); }
    .tk-from { color: var(--brand); font-weight: 700; }
    .tk-title { margin-top: .35rem; display: flex; flex-direction: column; }
    .tk-title small { font-size: .72rem; color: var(--muted); }
    .tk-title b { font-size: 1rem; font-weight: 700; }
    .tk-desc { font-size: .84rem; color: var(--ink-2); margin: .25rem 0 0; }
    .tk-facts { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .45rem; }
    .tk-fact { display: inline-flex; align-items: center; gap: .3rem; background: #f6f3f5; border-radius: 8px; padding: .12rem .5rem; font-size: .76rem; color: var(--ink-2); }
    .tk-last { margin-top: .55rem; padding: .45rem .6rem; background: #fbf8fa; border-radius: 9px; font-size: .8rem; color: var(--ink-2); }
    .tk-last small { color: var(--muted); margin-inline-start: .3rem; }
    .tk-side { display: flex; flex-direction: column; gap: .5rem; }
    .tk-status { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
    .tk-status small { font-size: .74rem; color: var(--muted); }
    .tk-due.is-late { color: var(--up); font-weight: 700; }
    .tk-act { display: flex; flex-direction: column; gap: .4rem; }
    .tk-btns { display: flex; gap: .4rem; justify-content: flex-end; }
    .tk-btns .btn { font-weight: 700; }
    .tk-empty { background: var(--surface); border-radius: 14px; text-align: center; padding: 3rem 1rem; color: var(--ink-2); }
    .tk-empty i { font-size: 1.8rem; color: var(--muted); display: block; margin-bottom: .4rem; }
    @media (max-width: 767.98px) { .tk-card { grid-template-columns: 1fr; } }
</style>
@endpush

@push('scripts')
<script>
// Sending back needs a reason.
document.querySelectorAll('[data-needs-note]').forEach(b => b.addEventListener('click', e => {
    const note = b.form.querySelector('[name=note]');
    if (note.value.trim().length < 3) { e.preventDefault(); note.required = true; note.reportValidity(); }
}));
</script>
@endpush
