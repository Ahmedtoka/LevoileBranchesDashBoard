@extends('layouts.app')
@section('title', dt('مراجعة الفروع المالية', 'Finance branch reviews'))
@include('dashboard.cctv.partials.style')

@section('content')
@php
    use App\Support\CctvDashboard;
    use App\Support\CctvReport;
    use App\Support\ProblemRouting;

    $palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    $colour = [];
    foreach ($categories->values() as $i => $c) { $colour[$c->id] = $palette[$i % count($palette)]; }

    $monthKey = $month->format('Y-m');
    $reviewedCount = $state->count();
    $cleanCount = $state->filter(fn ($s) => $s['problems'] === [])->count();
    $defaultDate = now()->between($from, $to->copy()->endOfDay()) ? now()->toDateString() : $from->toDateString();

    $jsBranches = $branches->map(fn ($b) => ['id' => $b->id, 'name' => CctvReport::storeName($b->branch_name)])->values()->all();
    $jsCategories = $categories->map(fn ($c) => ['id' => $c->id, 'label' => $c->name_ar, 'color' => $colour[$c->id], 'dept' => ProblemRouting::defaultFor(null, $c)])->values()->all();
    $jsItems = $items->map(fn ($i) => ['id' => $i->id, 'cat' => $i->category_id, 'label' => $i->label_ar,
        'amount' => collect((array) $i->extra_fields)->contains(fn ($f) => ($f['key'] ?? null) === 'amount'),
        'dept' => ProblemRouting::defaultFor($i, $categories->firstWhere('id', $i->category_id))])->values()->all();
    $jsDepartments = $departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->all();
    $jsState = $state->map(fn ($s) => $s['problems'])->all();
    $jsOld = ['branch' => (string) old('branch_id', request('branch_id')), 'problems' => array_values((array) old('problems', [])), 'notes' => (string) old('notes')];
    $jsText = [
        'pick' => dt('اختار فرع من البوكسات', 'Pick a branch from the boxes'),
        'already' => dt('اتسجل عليه الأسبوع ده:', 'Already recorded this week:'),
        'cleanBefore' => dt('اتراجع الأسبوع ده وكان سليم.', 'Reviewed this week: clean.'),
        'other' => dt('أخرى', 'Other'), 'describe' => dt('اكتب المشكلة', 'Describe the problem'),
        'details' => dt('تفاصيل (اختياري)', 'Details (optional)'), 'amount' => dt('المبلغ', 'Amount'),
        'remove' => dt('شيل', 'Remove'),
        'save' => dt('احفظ المراجعة', 'Save the review'), 'problems' => dt('مشكلة', 'problems'),
        'egp' => dt('ج', 'EGP'), 'route' => dt('توجيه لـ', 'Send to'),
    ];
@endphp

<div class="cv cv-rec">
<form method="POST" action="{{ route('finance.store') }}" id="finForm" autocomplete="off">
@csrf
<input type="hidden" name="branch_id" id="f-branch">

<div class="rec-head">
    <h4 class="fw-bold mb-0">{{ dt('مراجعة الفروع', 'Branch reviews') }}</h4>
    <a href="{{ route('finance.summary', ['month' => $monthKey, 'week' => $week]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-cash-coin"></i> {{ dt('ملخص الأسبوع ده', 'This week\'s summary') }}</a>
</div>

@if(session('status'))<div class="rec-flash" role="status"><i class="bi bi-check-circle-fill"></i> {{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2 mb-3">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

<div class="rec-grid">
    <section class="rec-left">
        {{-- The week under review --}}
        <div class="rec-when">
            <div class="cv-step">
                <a class="cv-arrow" href="{{ route('finance.create', ['month' => $month->copy()->subMonthNoOverflow()->format('Y-m'), 'week' => 4]) }}" title="{{ dt('الشهر السابق', 'Previous month') }}"><i class="bi bi-chevron-right"></i></a>
                <span class="cv-month">{{ CctvDashboard::monthName($month) }}</span>
                <a class="cv-arrow" href="{{ route('finance.create', ['month' => $month->copy()->addMonthNoOverflow()->format('Y-m'), 'week' => 1]) }}" title="{{ dt('الشهر التالي', 'Next month') }}"><i class="bi bi-chevron-left"></i></a>
            </div>
            <div class="rec-seg" role="group" aria-label="{{ dt('الأسبوع', 'Week') }}">
                @for($n = 1; $n <= CctvDashboard::WEEKS; $n++)
                    @php [$wf, $wt] = CctvDashboard::weekRange($month, $n); @endphp
                    <a href="{{ route('finance.create', ['month' => $monthKey, 'week' => $n]) }}" @class(['on' => $week === $n]) title="{{ $wf->format('j/n') }} – {{ $wt->format('j/n') }}">{{ dt('أسبوع', 'Week') }} {{ $n }}</a>
                @endfor
            </div>
            <label class="fin-date">{{ dt('تاريخ المراجعة', 'Review date') }}
                <input type="date" name="review_date" value="{{ old('review_date', $defaultDate) }}" min="{{ $from->toDateString() }}" max="{{ min($to->toDateString(), now()->toDateString()) }}" class="form-control form-control-sm">
            </label>
        </div>
        <div class="fin-progress">
            <span><b>{{ $reviewedCount }}</b> {{ dt('من', 'of') }} {{ $branches->count() }} {{ dt('فرع اتراجعوا', 'branches reviewed') }}</span>
            <span class="fin-progress-bar"><i style="inline-size: {{ $branches->count() ? round($reviewedCount * 100 / $branches->count()) : 0 }}%"></i></span>
            <span><i class="fin-dot is-ok"></i>{{ $cleanCount }} {{ dt('سليم', 'clean') }} · <i class="fin-dot is-bad"></i>{{ $reviewedCount - $cleanCount }} {{ dt('عليهم مشاكل', 'with problems') }}</span>
        </div>

        <div class="rec-search"><i class="bi bi-search"></i><input type="search" id="f-find" class="form-control" placeholder="{{ dt('دوّر على فرع…', 'Find a branch…') }}"></div>
        <div class="rec-tiles" id="f-tiles">
            @foreach($branches as $b)
                @php $s = $state[$b->id] ?? null; $area = str_contains($b->branch_name, ' - ') ? \Illuminate\Support\Str::before($b->branch_name, ' - ') : ''; @endphp
                <button type="button" @class(['rec-tile', 'is-clean' => $s && $s['problems'] === [], 'is-bad' => $s && $s['problems'] !== []]) data-id="{{ $b->id }}" data-name="{{ CctvReport::storeName($b->branch_name) }} {{ $b->branch_name }}">
                    <span class="rec-tile-name">{{ CctvReport::storeName($b->branch_name) }}</span>
                    @if($area)<span class="rec-tile-area">{{ $area }}</span>@endif
                    @if($s && $s['problems'] === [])<span class="fin-tile-state is-ok" title="{{ dt('سليم', 'Clean') }}"><i class="bi bi-check-lg"></i></span>
                    @elseif($s)<span class="fin-tile-state is-bad" title="{{ dt('مشاكل', 'Problems') }}">{{ count($s['problems']) }}</span>@endif
                </button>
            @endforeach
        </div>
        <p class="fin-legend"><i class="fin-dot"></i>{{ dt('لسه ماتراجعش', 'Not reviewed') }} · <i class="fin-dot is-ok"></i>{{ dt('سليم', 'Clean') }} · <i class="fin-dot is-bad"></i>{{ dt('عليه مشاكل', 'Has problems') }}</p>
    </section>

    <section class="rec-panel" aria-live="polite">
        <div class="rec-branch-title" id="f-title">{{ dt('اختار فرع من البوكسات', 'Pick a branch from the boxes') }}</div>
        <div class="fin-existing d-none" id="f-existing"></div>

        <div id="f-body" class="fin-body is-off">
            <button type="button" class="fin-clean" id="f-clean"><i class="bi bi-check-circle"></i> {{ dt('الفرع سليم، احفظ', 'Branch is clean, save') }}</button>
            <div class="fin-or"><span>{{ dt('أو سجّل المشاكل', 'or record the problems') }}</span></div>

            <div class="fin-controls-list" id="f-controls"></div>
            <ol class="fin-basket" id="f-basket"></ol>

            <details class="rec-extra" @if(old('notes')) open @endif>
                <summary>{{ dt('ملاحظة على الفرع', 'A note on the branch') }}</summary>
                <textarea name="notes" id="f-notes" rows="2" class="form-control form-control-sm mt-2">{{ old('notes') }}</textarea>
            </details>

            <div class="rec-save">
                <button class="btn btn-primary rec-save-btn" id="f-save" disabled><i class="bi bi-check2"></i> <span id="f-save-label">{{ dt('احفظ المراجعة', 'Save the review') }}</span> <kbd>Ctrl ↵</kbd></button>
            </div>
        </div>
    </section>
</div>
<div id="f-hidden"></div>
</form>
</div>
@endsection

@push('head')
<style>
    .rec-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: .9rem; }
    .rec-flash { background: var(--down-bg); color: var(--down); border-radius: 10px; padding: .55rem .9rem; margin-bottom: .9rem; font-weight: 600; animation: rec-in .5s cubic-bezier(.16, 1, .3, 1); }
    @keyframes rec-in { from { opacity: 0; transform: translateY(-6px); } }
    .rec-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(22rem, 30rem); gap: 1rem; align-items: start; }
    .rec-left { background: var(--surface); border-radius: 14px; padding: .9rem; box-shadow: 0 1px 2px rgba(74,15,51,.05); }
    .rec-when { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem 1rem; padding-bottom: .8rem; margin-bottom: .7rem; border-bottom: 1px solid var(--line); }
    .rec-seg { display: inline-flex; background: #f4eff2; border-radius: 11px; padding: 3px; }
    .rec-seg a { border-radius: 9px; padding: .4rem .8rem; font-size: .86rem; font-weight: 700; color: var(--ink-2); }
    .rec-seg a.on { background: var(--brand); color: #fff; box-shadow: 0 2px 6px -2px rgba(156,30,110,.6); }
    .fin-date { display: flex; align-items: center; gap: .4rem; font-size: .78rem; color: var(--ink-2); margin: 0 0 0 auto; }
    .fin-date input { inline-size: 10rem; }
    .fin-progress { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: .8rem; font-size: .82rem; color: var(--ink-2); margin-bottom: .8rem; }
    .fin-progress b { color: var(--ink); font-size: 1rem; }
    .fin-progress-bar { block-size: 8px; background: #f1edf0; border-radius: 5px; overflow: hidden; }
    .fin-progress-bar i { display: block; block-size: 100%; background: var(--brand); border-radius: 5px; }
    .fin-dot { display: inline-block; inline-size: .55rem; block-size: .55rem; border-radius: 50%; background: #d8d0d5; margin-inline-end: .3rem; }
    .fin-dot.is-ok { background: #0ca30c; } .fin-dot.is-bad { background: #d03b3b; }
    .fin-legend { font-size: .74rem; color: var(--muted); margin: .7rem 0 0; }

    .rec-search { position: relative; margin-bottom: .7rem; }
    .rec-search i { position: absolute; inset-inline-start: .7rem; top: 50%; transform: translateY(-50%); color: var(--muted); }
    .rec-search input { padding-inline-start: 2.1rem; }
    .rec-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(8.2rem, 1fr)); gap: .5rem; }
    .rec-tile { position: relative; background: #faf7f9; border: 1.5px solid var(--line); border-radius: 12px; padding: .7rem .5rem 1.15rem; min-block-size: 4.6rem; display: flex; align-items: center; justify-content: center; text-align: center; transition: border-color .12s, background .12s, transform .12s; }
    .rec-tile:hover { border-color: var(--brand); background: #fff; }
    .rec-tile:active { transform: scale(.98); }
    .rec-tile.is-clean { background: #eef7ee; border-color: #c4e3c4; }
    .rec-tile.is-bad { background: #fdf0f0; border-color: #f1c9c9; }
    .rec-tile.on { background: var(--brand); border-color: var(--brand); color: #fff; box-shadow: 0 6px 16px -8px rgba(156,30,110,.7); }
    .rec-tile-name { font-weight: 700; font-size: .84rem; line-height: 1.25; }
    .rec-tile-area { position: absolute; bottom: .3rem; inset-inline-end: .5rem; font-size: .64rem; color: var(--muted); line-height: 1; }
    .rec-tile.on .rec-tile-area { color: #f3dbe9; }
    .rec-tile[hidden] { display: none; }
    .fin-tile-state { position: absolute; top: .35rem; inset-inline-start: .4rem; min-inline-size: 1.3rem; block-size: 1.3rem; padding: 0 .3rem; border-radius: 7px; font-size: .72rem; font-weight: 800; display: grid; place-items: center; color: #fff; }
    .fin-tile-state.is-ok { background: #0ca30c; } .fin-tile-state.is-bad { background: #d03b3b; }

    .rec-panel { position: sticky; top: 1rem; background: var(--surface); border-radius: 14px; padding: 1rem 1.1rem; box-shadow: 0 1px 2px rgba(74,15,51,.05), 0 10px 30px -18px rgba(74,15,51,.35); max-block-size: calc(100vh - 2rem); overflow: auto; scrollbar-width: thin; }
    .rec-branch-title { font-size: 1.25rem; font-weight: 800; letter-spacing: -.01em; margin-bottom: .6rem; color: var(--muted); }
    .rec-branch-title.on { color: var(--brand); }
    .fin-existing { background: #fbf8fa; border-radius: 10px; padding: .55rem .7rem; font-size: .8rem; color: var(--ink-2); margin-bottom: .7rem; }
    .fin-existing ul { margin: .3rem 0 0; padding-inline-start: 1.1rem; }
    .fin-body.is-off { opacity: .4; pointer-events: none; }
    .fin-clean { inline-size: 100%; border: 1.5px solid #0ca30c; background: #eef7ee; color: #006300; border-radius: 11px; padding: .65rem; font-weight: 800; font-size: 1rem; transition: background .15s; }
    .fin-clean:hover { background: #dff0df; }
    .fin-or { display: flex; align-items: center; gap: .6rem; margin: .8rem 0 .6rem; font-size: .76rem; color: var(--muted); }
    .fin-or::before, .fin-or::after { content: ''; flex: 1; block-size: 1px; background: var(--line); }
    .fin-controls-list { display: flex; flex-direction: column; gap: .3rem; }
    .fin-control > button { inline-size: 100%; display: flex; align-items: center; gap: .5rem; border: 1px solid var(--line); background: #fff; border-radius: 10px; padding: .5rem .7rem; font-size: .86rem; font-weight: 600; color: var(--ink); text-align: start; }
    .fin-control > button:hover { border-color: var(--brand); }
    .fin-control > button .fin-count { margin-inline-start: auto; background: var(--brand); color: #fff; border-radius: 7px; font-size: .72rem; padding: 0 .4rem; }
    .fin-control.open > button { border-color: var(--brand); background: var(--brand-soft); }
    .fin-items { display: none; flex-wrap: wrap; gap: .3rem; padding: .45rem .2rem .2rem; }
    .fin-control.open .fin-items { display: flex; }
    .rec-chip { border: 1.5px solid var(--line); background: #fff; border-radius: 10px; padding: .32rem .65rem; font-size: .82rem; color: var(--ink); display: inline-flex; align-items: center; gap: .35rem; }
    .rec-chip:hover { border-color: var(--brand); }
    .rec-chip-ghost { border-style: dashed; color: var(--ink-2); }
    .fin-basket { list-style: none; margin: .7rem 0 0; padding: 0; display: flex; flex-direction: column; gap: .45rem; counter-reset: p; }
    .fin-basket li { border: 1px solid var(--line); border-radius: 11px; padding: .55rem .65rem; background: #fff; animation: rec-in .35s cubic-bezier(.16, 1, .3, 1); }
    .fin-basket-head { display: flex; align-items: center; gap: .45rem; font-size: .86rem; font-weight: 700; }
    .fin-basket-head small { font-weight: 400; color: var(--muted); }
    .fin-basket-head button { margin-inline-start: auto; border: 0; background: none; color: var(--muted); padding: .1rem .3rem; }
    .fin-basket-head button:hover { color: var(--up); }
    .fin-basket-fields { display: grid; grid-template-columns: 1fr 7rem; gap: .35rem; margin-top: .4rem; }
    .fin-basket-fields .fin-wide { grid-column: 1 / -1; }
    .fin-basket-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .4rem .8rem; margin-top: .4rem; }
    .fin-route { display: flex; align-items: center; gap: .35rem; font-size: .78rem; font-weight: 700; color: var(--brand); margin: 0; }
    .fin-route select { inline-size: auto; min-inline-size: 9rem; font-weight: 600; }
    .rec-extra summary { cursor: pointer; font-size: .8rem; color: var(--brand); margin-top: .7rem; }
    .rec-save { position: sticky; bottom: -1rem; background: var(--surface); padding: .7rem 0 .2rem; margin-top: .5rem; border-top: 1px solid var(--line); }
    .rec-save-btn { inline-size: 100%; padding: .6rem; font-weight: 800; font-size: 1rem; border-radius: 11px; }
    .rec-save-btn:disabled { background: var(--brand); border-color: var(--brand); opacity: .4; }
    .rec-save-btn kbd { background: rgba(255,255,255,.2); font-size: .68rem; margin-inline-start: .4rem; }
    @media (max-width: 991.98px) { .rec-grid { grid-template-columns: 1fr; } .rec-panel { position: static; max-block-size: none; } .fin-date { margin: 0; } }
</style>
@endpush

@push('scripts')
<script>
(() => {
    const branches = @json($jsBranches), categories = @json($jsCategories), items = @json($jsItems), departments = @json($jsDepartments);
    const state = @json($jsState), old = @json($jsOld), text = @json($jsText);
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const fold = s => String(s ?? '').toLowerCase().replace(/[إأآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/\s+/g, '');
    let branch = null, basket = [], openCat = null, seq = 0;

    function pickBranch(id) {
        branch = id;
        document.querySelectorAll('.rec-tile').forEach(t => t.classList.toggle('on', Number(t.dataset.id) === id));
        $('f-branch').value = id ?? '';
        const b = branches.find(x => x.id === id);
        $('f-title').textContent = b ? b.name : text.pick;
        $('f-title').classList.toggle('on', !! b);
        $('f-body').classList.toggle('is-off', ! b);
        const before = state[id];
        $('f-existing').classList.toggle('d-none', ! before);
        if (before) {
            $('f-existing').innerHTML = before.length
                ? `${esc(text.already)}<ul>${before.map(p => `<li>${esc(p.label)}${p.amount ? ` · ${esc(p.amount)} ${esc(text.egp)}` : ''} <small>${esc(p.ref)}</small></li>`).join('')}</ul>`
                : esc(text.cleanBefore);
        }
        basket = []; render();
    }

    function render() {
        const count = cat => basket.filter(p => p.cat === cat).length;
        $('f-controls').innerHTML = categories.map(c => `
            <div class="fin-control${openCat === c.id ? ' open' : ''}" data-cat="${c.id}">
                <button type="button" data-toggle="${c.id}"><i class="cv-dot" style="--c:${c.color}"></i>${esc(c.label)}${count(c.id) ? `<span class="fin-count">${count(c.id)}</span>` : ''}</button>
                <div class="fin-items">${items.filter(i => i.cat === c.id).map(i => `<button type="button" class="rec-chip" data-add="${i.id}">${esc(i.label)}</button>`).join('')}
                    <button type="button" class="rec-chip rec-chip-ghost" data-add-other="${c.id}"><i class="bi bi-plus"></i>${esc(text.other)}</button></div>
            </div>`).join('');
        $('f-basket').innerHTML = basket.map(p => {
            const c = categories.find(x => x.id === p.cat), it = items.find(x => x.id === p.item);
            return `<li data-key="${p.key}">
                <div class="fin-basket-head"><i class="cv-dot" style="--c:${c.color}"></i>${esc(it ? it.label : text.other)} <small>${esc(c.label)}</small>
                    <button type="button" data-remove="${p.key}" title="${esc(text.remove)}"><i class="bi bi-x-lg"></i></button></div>
                <div class="fin-basket-fields">
                    <input class="form-control form-control-sm${it?.amount ? '' : ' fin-wide'}" data-field="description" value="${esc(p.description)}" placeholder="${esc(it ? text.details : text.describe)}" ${it ? '' : 'required'}>
                    ${it?.amount ? `<input type="number" min="0" step="1" class="form-control form-control-sm" data-field="amount" value="${esc(p.amount)}" placeholder="${esc(text.amount)}">` : ''}
                </div>
                <div class="fin-basket-foot">
                    <label class="fin-route"><i class="bi bi-send"></i> ${esc(text.route)}
                        <select class="form-select form-select-sm" data-field="dept">${departments.map(d => `<option value="${d.id}"${d.id === p.dept ? ' selected' : ''}>${esc(d.name)}</option>`).join('')}</select></label>
                </div>
            </li>`;
        }).join('');
        // The fields the server reads.
        $('f-hidden').innerHTML = basket.map((p, i) => [
            ['category_id', p.cat], ['catalog_item_id', p.item ?? ''], ['description', p.description], ['amount', p.amount], ['department_id', p.dept ?? ''],
        ].map(([k, v]) => `<input type="hidden" name="problems[${i}][${k}]" value="${esc(v)}">`).join('')).join('');
        const ok = branch && basket.every(p => p.item || p.description.trim());
        $('f-save').disabled = ! (branch && basket.length && ok);
        $('f-save-label').textContent = basket.length ? `${text.save} (${basket.length} ${text.problems})` : text.save;
    }

    const defaultDept = (cat, item) => (items.find(i => i.id === item) || categories.find(c => c.id === cat))?.dept ?? departments[0]?.id;
    function add(cat, item) {
        basket.push({ key: ++seq, cat, item, description: '', amount: '', dept: defaultDept(cat, item) });
        render();
        const last = $('f-basket').lastElementChild?.querySelector(item ? '[data-field=amount]' : '[data-field=description]');
        last?.focus();
    }

    $('f-tiles').addEventListener('click', e => { const t = e.target.closest('.rec-tile'); if (t) pickBranch(Number(t.dataset.id)); });
    $('f-find').addEventListener('input', () => {
        const q = fold($('f-find').value);
        document.querySelectorAll('.rec-tile').forEach(t => t.hidden = q !== '' && ! fold(t.dataset.name).includes(q));
    });
    $('f-find').addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const first = [...document.querySelectorAll('.rec-tile')].find(t => ! t.hidden);
        if (first) pickBranch(Number(first.dataset.id));
    });
    $('f-controls').addEventListener('click', e => {
        const t = e.target.closest('[data-toggle]'), a = e.target.closest('[data-add]'), o = e.target.closest('[data-add-other]');
        if (t) { openCat = openCat === Number(t.dataset.toggle) ? null : Number(t.dataset.toggle); render(); }
        else if (a) { const it = items.find(i => i.id === Number(a.dataset.add)); add(it.cat, it.id); }
        else if (o) { add(Number(o.dataset.addOther), null); }
    });
    $('f-basket').addEventListener('click', e => { const r = e.target.closest('[data-remove]'); if (r) { basket = basket.filter(p => p.key !== Number(r.dataset.remove)); render(); } });
    $('f-basket').addEventListener('input', e => {
        const li = e.target.closest('li'), f = e.target.dataset.field; if (! li || ! f) return;
        const p = basket.find(x => x.key === Number(li.dataset.key));
        p[f] = f === 'dept' ? Number(e.target.value) : e.target.value;
        // Update the posted fields and the button without redrawing the inputs being typed in.
        const at = basket.indexOf(p), h = document.querySelector(`[name="problems[${at}][${f === 'dept' ? 'department_id' : f}]"]`);
        if (h) h.value = p[f];
        $('f-save').disabled = ! (branch && basket.length && basket.every(x => x.item || x.description.trim()));
    });
    $('f-clean').addEventListener('click', () => { basket = []; render(); $('finForm').requestSubmit(); });
    document.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && ! $('f-save').disabled) $('finForm').requestSubmit(); });
    $('finForm').addEventListener('submit', () => { $('f-save').disabled = true; $('f-clean').disabled = true; });

    // A failed save comes back with its branch and problems.
    if (old.branch) pickBranch(Number(old.branch));
    if (old.problems.length) {
        basket = old.problems.map(p => ({ key: ++seq, cat: Number(p.category_id), item: p.catalog_item_id ? Number(p.catalog_item_id) : null, description: p.description ?? '', amount: p.amount ?? '',
            dept: p.department_id ? Number(p.department_id) : defaultDept(Number(p.category_id), p.catalog_item_id ? Number(p.catalog_item_id) : null) }));
        render();
    }
})();
</script>
@endpush
