@extends('layouts.app')
@section('title', dt('تسجيل مخالفة CCTV', 'Record a CCTV violation'))
@include('dashboard.cctv.partials.style')

@section('content')
@php
    use App\Support\CctvReport;
    use App\Support\ProblemRouting;

    $palette = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    $colour = [];
    foreach ($categories->values() as $i => $c) { $colour[$c->id] = $palette[$i % count($palette)]; }

    $minuteKey = fn ($item) => collect((array) $item->extra_fields)->pluck('key')->first(fn ($k) => in_array($k, ['minutes_late', 'minutes'], true));
    $jsBranches = $branches->map(fn ($b) => ['id' => $b->id, 'name' => CctvReport::storeName($b->branch_name)])->values()->all();
    $jsEmployees = $employees->map(fn ($e) => ['id' => $e->id, 'branch' => $e->branch_id, 'name' => $e->name, 'code' => $e->code, 'job' => $e->job_title])->values()->all();
    $jsCategories = $categories->map(fn ($c) => ['id' => $c->id, 'label' => $c->sheet_label, 'color' => $colour[$c->id], 'dept' => ProblemRouting::defaultFor(null, $c)])->values()->all();
    $jsItems = $items->map(fn ($i) => ['id' => $i->id, 'cat' => $i->category_id, 'label' => $i->label_ar, 'minutes' => $minuteKey($i),
        'dept' => ProblemRouting::defaultFor($i, $categories->firstWhere('id', $i->category_id))])->values()->all();
    $jsOld = [
        'branch' => (string) old('branch_id', request('branch_id')), 'employee' => (string) old('employee_id'),
        'newEmployee' => (string) old('new_employee'), 'category' => (string) old('category_id'), 'item' => (string) old('catalog_item_id'),
        'time' => (string) old('event_time'), 'minutes' => (string) old('minutes'), 'hasDescription' => trim((string) old('description')) !== '',
        'department' => (string) old('department_id'),
    ];
    $jsText = [
        'noBranch' => dt('لسه ما اخترتش الفرع', 'No branch yet'),
        'unknown' => dt('من غير اسم', 'No name'),
        'addNew' => dt('ضيف «%s» موظف جديد في', 'Add "%s" as a new employee at'),
        'pickBranchFirst' => dt('اختار الفرع الأول عشان تضيف موظف جديد', 'Pick the branch first to add a new employee'),
        'noMatch' => dt('مفيش موظف بالاسم ده', 'No employee matches'),
        'other' => dt('أخرى', 'Other'),
        'late' => dt('دقيقة تأخير', 'minutes late'),
        'minutes' => dt('عدد الدقائق', 'Minutes'),
        'describe' => dt('(اكتب المخالفة)', '(describe it)'), 'optional' => dt('(اختياري)', '(optional)'),
        'needs' => dt('ناقص:', 'Missing:'), 'branch' => dt('الفرع', 'branch'), 'type' => dt('النوع', 'type'), 'violation' => dt('المخالفة', 'violation'),
        'clean' => dt('%s سليم، احفظ', '%s is clean, save'),
    ];
    $today = now()->toDateString();
    $yesterday = now()->subDay()->toDateString();
    $date = old('event_date', $day->toDateString());
@endphp

<div class="cv cv-rec">
<form method="POST" action="{{ route('cctv.store') }}" id="recForm" autocomplete="off">
@csrf
<input type="hidden" name="branch_id" id="r-branch">
<input type="hidden" name="employee_id" id="r-employee">
<input type="hidden" name="new_employee" id="r-new">
<input type="hidden" name="category_id" id="r-category">
<input type="hidden" name="catalog_item_id" id="r-item">
<input type="hidden" name="event_time" id="r-time24">
<input type="hidden" name="department_id" id="r-dept">

<div class="rec-head">
    <h4 class="fw-bold mb-0">{{ dt('تسجيل مخالفة', 'Record a violation') }}</h4>
    <a href="{{ route('cctv.index', ['from' => $day->toDateString(), 'to' => $day->toDateString()]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-ul"></i> {{ dt('مخالفات اليوم ده', 'This day\'s violations') }}</a>
</div>

@if(session('status'))<div class="rec-flash" role="status"><i class="bi bi-check-circle-fill"></i> {{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2 mb-3">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

<div class="rec-grid">
    <section class="rec-left">
        {{-- 1. When: the day, then the time --}}
        <div class="rec-when">
            <div class="rec-seg" role="group" aria-label="{{ dt('اليوم', 'Day') }}">
                <button type="button" data-date="{{ $today }}" @class(['on' => $date === $today])>{{ dt('النهارده', 'Today') }}</button>
                <button type="button" data-date="{{ $yesterday }}" @class(['on' => $date === $yesterday])>{{ dt('امبارح', 'Yesterday') }}</button>
            </div>
            <input type="date" name="event_date" id="r-date" value="{{ $date }}" max="{{ $today }}" class="form-control rec-date" aria-label="{{ dt('تاريخ المخالفة', 'Date') }}">
            <span class="rec-when-sep" aria-hidden="true"></span>
            <button type="button" class="rec-now" id="r-now"><i class="bi bi-clock"></i> {{ dt('دلوقتي', 'Now') }}</button>
            <input type="text" id="r-time" inputmode="numeric" maxlength="5" placeholder="--:--" class="form-control rec-time-in" aria-label="{{ dt('الساعة', 'Time') }}">
            <div class="rec-seg rec-ampm" role="group" aria-label="AM / PM">
                <button type="button" data-ampm="AM" class="on">AM</button>
                <button type="button" data-ampm="PM">PM</button>
            </div>
        </div>

        {{-- 2. Where: find, then the branch boxes --}}
        <div class="rec-search"><i class="bi bi-search"></i><input type="search" id="r-find" class="form-control" placeholder="{{ dt('دوّر على فرع…', 'Find a branch…') }}"></div>
        <div class="rec-tiles" id="r-tiles">
            @foreach($branches as $b)
                @php $area = str_contains($b->branch_name, ' - ') ? \Illuminate\Support\Str::before($b->branch_name, ' - ') : ''; @endphp
                @php $isClean = in_array($b->id, $dayClean, true); @endphp
                <button type="button" @class(['rec-tile', 'is-clean' => $isClean]) data-id="{{ $b->id }}" data-name="{{ CctvReport::storeName($b->branch_name) }} {{ $b->branch_name }}">
                    <span class="rec-tile-name">{{ CctvReport::storeName($b->branch_name) }}</span>
                    @if($area)<span class="rec-tile-area">{{ $area }}</span>@endif
                    @if($dayCounts[$b->id] ?? 0)<span class="rec-tile-n" title="{{ dt('مخالفات', 'Violations on') }} {{ $day->format('j/n') }}">{{ $dayCounts[$b->id] }}</span>
                    @elseif($isClean)<span class="rec-tile-ok" title="{{ dt('سليم', 'Clean') }} {{ $day->format('j/n') }}"><i class="bi bi-check-lg"></i></span>@endif
                </button>
            @endforeach
        </div>
        <p class="rec-legend"><i class="rec-legend-n">2</i>{{ dt('مخالفات اليوم ده', 'Violations that day') }} · <i class="rec-legend-ok"><i class="bi bi-check-lg"></i></i>{{ dt('اتراقب وسليم', 'Watched, clean') }}</p>
    </section>

    {{-- 3. Who and what --}}
    <section class="rec-panel" aria-live="polite">
        <button type="submit" form="cleanForm" class="rec-clean d-none" id="r-clean"><i class="bi bi-check-circle"></i> <span id="r-clean-label"></span></button>
        <div class="rec-emp">
            <label for="r-emp-q" class="rec-label">{{ dt('الموظف', 'Employee') }} <small>{{ dt('اكتب 3 حروف من الاسم أو الكود', 'Type 3 letters of the name, or the code') }}</small></label>
            <div class="rec-emp-box">
                <i class="bi bi-person"></i>
                <input type="search" id="r-emp-q" class="form-control" placeholder="{{ dt('اسم الموظف أو الكود…', 'Employee name or code…') }}" role="combobox" aria-expanded="false" aria-controls="r-emp-list" aria-autocomplete="list">
                <button type="button" class="rec-emp-clear d-none" id="r-emp-clear" aria-label="{{ dt('شيل الموظف', 'Clear') }}"><i class="bi bi-x-lg"></i></button>
                <ul class="rec-emp-list d-none" id="r-emp-list" role="listbox"></ul>
            </div>
            <div class="rec-picked" id="r-picked"></div>
        </div>

        <fieldset class="rec-step">
            <legend>{{ dt('نوع المخالفة', 'Type') }}</legend>
            <div class="rec-chips" id="r-types"></div>
        </fieldset>

        <fieldset class="rec-step">
            <legend>{{ dt('المخالفة', 'Violation') }}</legend>
            <div class="rec-chips rec-chips-col" id="r-items"><span class="text-muted small">{{ dt('اختار النوع الأول', 'Pick the type first') }}</span></div>
            <div class="rec-minutes d-none" id="r-minutes-wrap">
                <span class="rec-minutes-label" id="r-minutes-label"></span>
                <div class="rec-chips">
                    @foreach([5, 10, 15, 20, 30, 45, 60] as $m)<button type="button" class="rec-chip rec-chip-sm" data-min="{{ $m }}">{{ $m }}</button>@endforeach
                    <input type="number" name="minutes" id="r-minutes" min="1" max="1440" class="form-control form-control-sm rec-min-input" placeholder="{{ dt('دقايق', 'min') }}">
                </div>
                <label class="rec-opening d-none" id="r-opening-wrap">{{ dt('ميعاد فتح الفرع', 'Branch opens at') }}
                    <input type="time" id="r-opening" value="10:00" class="form-control form-control-sm">
                </label>
            </div>
            <label class="rec-route"><i class="bi bi-send"></i> {{ dt('توجيه لـ', 'Send to') }}
                <select id="r-dept-pick" class="form-select form-select-sm">
                    @foreach($departments as $d)<option value="{{ $d->id }}" @selected($d->slug === 'operation')>{{ $d->name }}</option>@endforeach
                </select>
            </label>
        </fieldset>

        <fieldset class="rec-step">
            <legend>{{ dt('تفاصيل', 'Details') }} <small id="r-desc-hint">{{ dt('(اختياري)', '(optional)') }}</small></legend>
            <textarea name="description" id="r-desc" rows="2" class="form-control form-control-sm" placeholder="{{ dt('اللي شفته في الكاميرا، لو محتاج توضيح', 'What the camera showed, if it needs saying') }}">{{ old('description') }}</textarea>
            <details class="rec-extra" @if(old('reply') || old('penalty')) open @endif>
                <summary>{{ dt('رد الفرع والجزاء', 'Branch reply and penalty') }}</summary>
                <textarea name="reply" rows="2" class="form-control form-control-sm mt-2" placeholder="{{ dt('رد الفرع', 'Branch reply') }}">{{ old('reply') }}</textarea>
                <input name="penalty" value="{{ old('penalty') }}" class="form-control form-control-sm mt-2" placeholder="{{ dt('الجزاء', 'Penalty') }}">
            </details>
        </fieldset>

        <div class="rec-save">
            <div class="rec-preview" id="r-preview"></div>
            <button class="btn btn-primary rec-save-btn" id="r-save" disabled><i class="bi bi-check2"></i> {{ dt('حفظ المخالفة', 'Save') }} <kbd>Ctrl ↵</kbd></button>
        </div>
    </section>
</div>
</form>
<form method="POST" action="{{ route('cctv.clean') }}" id="cleanForm" class="d-none">
    @csrf
    <input type="hidden" name="branch_id" id="c-branch">
    <input type="hidden" name="event_date" id="c-date">
</form>

@if($recent->isNotEmpty())
<section class="cv-panel mt-3">
    <h2 class="cv-h">{{ dt('آخر اللي سجلته', 'Your latest entries') }}</h2>
    <ul class="rec-recent">
        @foreach($recent as $o)
            @php $extra = (array) ($o->extra_values ?? []); $mins = $extra['minutes_late'] ?? $extra['minutes'] ?? null; @endphp
            <li>
                <span class="rec-recent-when">{{ $o->event_date?->format('j/n') }}@if(! empty($extra['time'])) · {{ \Illuminate\Support\Carbon::createFromFormat('H:i', $extra['time'])->format('g:i A') }}@endif</span>
                <b>{{ CctvReport::storeName($o->branch?->branch_name) }}</b>
                <span>{{ $o->employee?->name ?? $o->employee_name ?? dt('بدون اسم', 'No name') }}</span>
                <span class="rec-recent-vio"><i class="cv-dot" @style(['--c: '.($colour[$o->category_id] ?? '#898781')])></i>{{ $o->catalogItem?->label_ar ?? $o->title }}@if($mins) · {{ $mins }} {{ dt('د', 'm') }}@endif</span>
                <a href="{{ route('observations.show', $o) }}" class="cv-ref">{{ $o->reference }}</a>
            </li>
        @endforeach
    </ul>
</section>
@endif
</div>
@endsection

@push('head')
<style>
    .rec-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: .9rem; }
    .rec-flash { background: var(--down-bg); color: var(--down); border-radius: 10px; padding: .55rem .9rem; margin-bottom: .9rem; font-weight: 600; animation: rec-in .5s cubic-bezier(.16, 1, .3, 1); }
    @keyframes rec-in { from { opacity: 0; transform: translateY(-6px); } }

    .rec-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(22rem, 29rem); gap: 1rem; align-items: start; }
    .rec-left { background: var(--surface); border-radius: 14px; padding: .9rem; box-shadow: 0 1px 2px rgba(74,15,51,.05); }

    /* When */
    .rec-when { display: flex; flex-wrap: wrap; align-items: center; gap: .45rem; padding-bottom: .8rem; margin-bottom: .8rem; border-bottom: 1px solid var(--line); }
    .rec-seg { display: inline-flex; background: #f4eff2; border-radius: 11px; padding: 3px; }
    .rec-seg button { border: 0; background: transparent; border-radius: 9px; padding: .4rem .85rem; font-size: .88rem; font-weight: 700; color: var(--ink-2); transition: background .15s, color .15s; }
    .rec-seg button.on { background: var(--brand); color: #fff; box-shadow: 0 2px 6px -2px rgba(156,30,110,.6); }
    .rec-date { inline-size: 10rem; font-weight: 700; }
    .rec-when-sep { inline-size: 1px; block-size: 1.8rem; background: var(--line); margin: 0 .35rem; }
    .rec-now { border: 1.5px solid var(--brand); color: var(--brand); background: #fff; border-radius: 11px; padding: .38rem .8rem; font-weight: 700; font-size: .88rem; }
    .rec-now:hover { background: var(--brand-soft); }
    .rec-time-in { inline-size: 6.2rem; text-align: center; font-size: 1.2rem; font-weight: 800; letter-spacing: .04em; font-variant-numeric: tabular-nums; direction: ltr; }

    /* Where */
    .rec-search { position: relative; margin-bottom: .7rem; }
    .rec-search i { position: absolute; inset-inline-start: .7rem; top: 50%; transform: translateY(-50%); color: var(--muted); }
    .rec-search input { padding-inline-start: 2.1rem; }
    .rec-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(8.2rem, 1fr)); gap: .5rem; }
    .rec-tile { position: relative; background: #faf7f9; border: 1.5px solid var(--line); border-radius: 12px; padding: .7rem .5rem 1.15rem; min-block-size: 4.6rem; display: flex; align-items: center; justify-content: center; text-align: center; transition: border-color .12s, background .12s, transform .12s; }
    .rec-tile:hover { border-color: var(--brand); background: #fff; }
    .rec-tile:active { transform: scale(.98); }
    .rec-tile.on { background: var(--brand); border-color: var(--brand); color: #fff; box-shadow: 0 6px 16px -8px rgba(156,30,110,.7); }
    .rec-tile-name { font-weight: 700; font-size: .84rem; line-height: 1.25; }
    .rec-tile-area { position: absolute; bottom: .3rem; inset-inline-end: .5rem; font-size: .64rem; color: var(--muted); line-height: 1; }
    .rec-tile.on .rec-tile-area { color: #f3dbe9; }
    .rec-tile-n { position: absolute; top: .35rem; inset-inline-start: .4rem; min-inline-size: 1.25rem; block-size: 1.25rem; padding: 0 .3rem; border-radius: 7px; background: #fdf3dc; color: #7a5300; font-size: .7rem; font-weight: 800; display: grid; place-items: center; }
    .rec-tile.on .rec-tile-n { background: rgba(255,255,255,.22); color: #fff; }
    .rec-tile[hidden] { display: none; }
    .rec-tile.is-clean { background: #eef7ee; border-color: #c4e3c4; }
    .rec-tile.on.is-clean { background: var(--brand); border-color: var(--brand); }
    .rec-tile-ok { position: absolute; top: .35rem; inset-inline-start: .4rem; inline-size: 1.25rem; block-size: 1.25rem; border-radius: 7px; background: #0ca30c; color: #fff; font-size: .74rem; display: grid; place-items: center; }
    .rec-legend { font-size: .74rem; color: var(--muted); margin: .7rem 0 0; display: flex; align-items: center; gap: .3rem; }
    .rec-legend > i { display: inline-grid; place-items: center; inline-size: 1rem; block-size: 1rem; border-radius: 5px; font-style: normal; font-size: .62rem; font-weight: 800; }
    .rec-legend-n { background: #fdf3dc; color: #7a5300; }
    .rec-legend-ok { background: #0ca30c; color: #fff; }
    .rec-clean { inline-size: 100%; border: 1.5px solid #0ca30c; background: #eef7ee; color: #006300; border-radius: 11px; padding: .55rem; font-weight: 800; font-size: .95rem; margin-bottom: .8rem; transition: background .15s; }
    .rec-clean:hover { background: #dff0df; }
    .rec-route { display: flex; align-items: center; gap: .4rem; font-size: .8rem; font-weight: 700; color: var(--brand); margin: .7rem 0 0; }
    .rec-route select { inline-size: auto; min-inline-size: 10rem; font-weight: 600; }

    /* Who and what */
    .rec-panel { position: sticky; top: 1rem; background: var(--surface); border-radius: 14px; padding: 1rem 1.1rem; box-shadow: 0 1px 2px rgba(74,15,51,.05), 0 10px 30px -18px rgba(74,15,51,.35); max-block-size: calc(100vh - 2rem); overflow: auto; scrollbar-width: thin; }
    .rec-label { display: block; font-size: .8rem; font-weight: 700; color: var(--ink-2); margin-bottom: .35rem; }
    .rec-label small { font-weight: 400; color: var(--muted); }
    .rec-emp { padding-bottom: .7rem; }
    .rec-emp-box { position: relative; }
    .rec-emp-box > i { position: absolute; inset-inline-start: .75rem; top: 50%; transform: translateY(-50%); color: var(--muted); }
    .rec-emp-box input { padding-inline: 2.2rem; font-size: 1rem; block-size: 2.7rem; border-radius: 11px; }
    .rec-emp-clear { position: absolute; inset-inline-end: .4rem; top: 50%; transform: translateY(-50%); border: 0; background: none; color: var(--muted); padding: .3rem .45rem; }
    .rec-emp-list { position: absolute; inset-inline: 0; top: calc(100% + 4px); z-index: 5; list-style: none; margin: 0; padding: .3rem; background: #fff; border-radius: 11px; box-shadow: 0 12px 32px -10px rgba(74,15,51,.35), 0 0 0 1px var(--line); max-block-size: 18rem; overflow: auto; }
    .rec-emp-list li { display: flex; align-items: center; gap: .5rem; padding: .45rem .6rem; border-radius: 8px; cursor: pointer; font-size: .9rem; }
    .rec-emp-list li small { color: var(--muted); font-size: .76rem; }
    .rec-emp-who { display: flex; flex-direction: column; line-height: 1.3; min-inline-size: 0; }
    .rec-emp-list li .rec-code { margin-inline-start: auto; font-size: .74rem; color: var(--ink-2); background: #f4eff2; border-radius: 6px; padding: 0 .35rem; direction: ltr; }
    .rec-emp-list li.on, .rec-emp-list li:hover { background: var(--brand-soft); }
    .rec-emp-list li.rec-add { color: var(--brand); font-weight: 600; }
    .rec-emp-list li.rec-none { color: var(--muted); cursor: default; background: none; }
    .rec-picked { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .5rem; font-size: .82rem; color: var(--ink-2); }
    .rec-picked span { background: #f6f3f5; border-radius: 8px; padding: .2rem .55rem; }
    .rec-picked b { color: var(--ink); }
    .rec-picked .is-missing { color: var(--muted); }
    .rec-step { border: 0; padding: .6rem 0; margin: 0; border-top: 1px solid var(--line); min-inline-size: 0; }
    .rec-step legend { float: none; font-size: .8rem; font-weight: 700; color: var(--ink-2); margin-bottom: .4rem; padding: 0; }
    .rec-step legend small { font-weight: 400; color: var(--muted); }
    .rec-chips { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; }
    .rec-chips-col { flex-direction: column; align-items: stretch; }
    .rec-chip { border: 1.5px solid var(--line); background: #fff; border-radius: 10px; padding: .38rem .7rem; font-size: .86rem; color: var(--ink); text-align: start; display: inline-flex; align-items: center; gap: .4rem; transition: border-color .12s, background .12s; }
    .rec-chip:hover { border-color: var(--brand); }
    .rec-chip.on { background: var(--brand-soft); border-color: var(--brand); color: var(--brand); font-weight: 700; }
    .rec-chip-sm { padding: .25rem .55rem; font-size: .8rem; min-inline-size: 2.4rem; justify-content: center; }
    .rec-chip-ghost { border-style: dashed; color: var(--ink-2); }
    .rec-minutes { margin-top: .6rem; padding: .6rem; background: #fbf8fa; border-radius: 10px; }
    .rec-minutes-label { display: block; font-size: .8rem; color: var(--ink-2); margin-bottom: .35rem; }
    .rec-minutes-label b { color: var(--up); font-size: 1rem; }
    .rec-min-input { inline-size: 5.5rem; }
    .rec-opening { display: flex; align-items: center; gap: .4rem; font-size: .75rem; color: var(--muted); margin: .5rem 0 0; }
    .rec-opening input { inline-size: 7.5rem; }
    .rec-extra summary { cursor: pointer; font-size: .8rem; color: var(--brand); margin-top: .5rem; }
    .rec-save { position: sticky; bottom: -1rem; background: var(--surface); padding: .7rem 0 .2rem; border-top: 1px solid var(--line); margin-top: .3rem; }
    .rec-preview { font-size: .8rem; color: var(--ink-2); margin-bottom: .45rem; min-block-size: 1.2em; }
    .rec-preview b { color: var(--ink); }
    .rec-save-btn { inline-size: 100%; padding: .6rem; font-weight: 800; font-size: 1rem; border-radius: 11px; }
    .rec-save-btn:disabled { background: var(--brand); border-color: var(--brand); opacity: .4; }
    .rec-save-btn kbd { background: rgba(255,255,255,.2); font-size: .68rem; margin-inline-start: .4rem; }
    .rec-recent { list-style: none; margin: 0; padding: 0; }
    .rec-recent li { display: grid; grid-template-columns: 7.5rem 9rem 9rem 1fr auto; gap: .6rem; align-items: center; padding: .4rem 0; border-bottom: 1px solid var(--line); font-size: .84rem; }
    .rec-recent li:last-child { border-bottom: 0; }
    .rec-recent-when { color: var(--muted); font-variant-numeric: tabular-nums; }
    .rec-recent-vio { display: flex; align-items: center; gap: .35rem; }
    @media (max-width: 991.98px) {
        .rec-grid { grid-template-columns: 1fr; }
        .rec-panel { position: static; max-block-size: none; }
        .rec-recent li { grid-template-columns: 1fr 1fr; }
    }
</style>
@endpush

@push('scripts')
<script>
(() => {
    const branches = @json($jsBranches), employees = @json($jsEmployees), categories = @json($jsCategories), items = @json($jsItems);
    const old = @json($jsOld), text = @json($jsText);
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const fold = s => String(s ?? '').trim().toLowerCase().replace(/[إأآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/\s+/g, '');
    const pad = n => String(n).padStart(2, '0');
    const state = { branch: null, employee: null, newName: '', category: null, item: null, ampm: 'AM' };

    /* ---------- Date ---------- */
    document.querySelectorAll('[data-date]').forEach(b => b.addEventListener('click', () => { $('r-date').value = b.dataset.date; markDate(); }));
    const markDate = () => document.querySelectorAll('[data-date]').forEach(b => b.classList.toggle('on', b.dataset.date === $('r-date').value));
    $('r-date').addEventListener('change', markDate);

    /* ---------- Time: 12-hour box + AM/PM, sent as 24-hour ---------- */
    function setAmPm(v) { state.ampm = v; document.querySelectorAll('[data-ampm]').forEach(b => b.classList.toggle('on', b.dataset.ampm === v)); syncTime(); }
    function syncTime() {
        const m = $('r-time').value.match(/^(\d{1,2}):(\d{2})$/);
        let out = '';
        if (m && +m[1] >= 1 && +m[1] <= 12 && +m[2] < 60) {
            let h = +m[1] % 12; if (state.ampm === 'PM') h += 12;
            out = `${pad(h)}:${m[2]}`;
        }
        $('r-time24').value = out;
        $('r-time').classList.toggle('is-invalid', $('r-time').value !== '' && ! out);
        lateFromTime(); update();
    }
    // Typing "1025" gives "10:25"; "925" gives "9:25".
    $('r-time').addEventListener('input', () => {
        const d = $('r-time').value.replace(/\D/g, '').slice(0, 4);
        $('r-time').value = d.length >= 3 ? `${d.slice(0, d.length - 2)}:${d.slice(-2)}` : d;
        syncTime();
    });
    function setTime24(t) {
        const [h, m] = t.split(':').map(Number);
        $('r-time').value = `${h % 12 || 12}:${pad(m)}`;
        setAmPm(h >= 12 ? 'PM' : 'AM');
    }
    $('r-now').addEventListener('click', () => { const d = new Date(); setTime24(`${d.getHours()}:${d.getMinutes()}`); });
    document.querySelectorAll('[data-ampm]').forEach(b => b.addEventListener('click', () => setAmPm(b.dataset.ampm)));

    /* ---------- Branch ---------- */
    function pickBranch(id, keepEmployee) {
        state.branch = id;
        document.querySelectorAll('.rec-tile').forEach(t => t.classList.toggle('on', Number(t.dataset.id) === id));
        $('r-branch').value = id ?? '';
        // "Clean" only for a branch with no violation that day.
        const tile = document.querySelector(`.rec-tile[data-id="${id}"]`), b = branches.find(x => x.id === id);
        $('r-clean').classList.toggle('d-none', ! b || !! tile?.querySelector('.rec-tile-n'));
        if (b) { $('r-clean-label').textContent = text.clean.replace('%s', b.name); $('c-branch').value = id; }
        if (! keepEmployee && state.employee && employees.find(e => e.id === state.employee)?.branch !== id) clearEmployee();
        update();
    }
    $('r-tiles').addEventListener('click', e => { const t = e.target.closest('.rec-tile'); if (t) pickBranch(Number(t.dataset.id)); });
    $('r-find').addEventListener('input', () => {
        const q = fold($('r-find').value);
        document.querySelectorAll('.rec-tile').forEach(t => t.hidden = q !== '' && ! fold(t.dataset.name).includes(q));
    });
    $('r-find').addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const first = [...document.querySelectorAll('.rec-tile')].find(t => ! t.hidden);
        if (first) pickBranch(Number(first.dataset.id));
    });

    /* ---------- Employee: search by 3 letters of the name, or the code; picking one also picks the branch ---------- */
    const list = $('r-emp-list'), q = $('r-emp-q');
    let options = [], active = -1;
    function search() {
        const raw = q.value.trim(), f = fold(raw);
        const byCode = /^\d+$/.test(raw);
        if ((! byCode && f.length < 3) || raw === '') { closeList(); return; }
        const hits = employees.filter(e => byCode ? String(e.code ?? '').startsWith(raw) : fold(e.name).includes(f))
            .sort((a, b) => (b.branch === state.branch) - (a.branch === state.branch) || a.name.localeCompare(b.name, 'ar'))
            .slice(0, 12);
        options = hits.map(e => ({ type: 'emp', e }));
        if (! byCode) options.push({ type: 'new' });
        active = options.length ? 0 : -1;
        const branchName = id => branches.find(b => b.id === id)?.name ?? '';
        list.innerHTML = options.map((o, i) => o.type === 'emp'
            ? `<li role="option" data-i="${i}" class="${i === active ? 'on' : ''}"><span class="rec-emp-who"><span>${esc(o.e.name)}</span><small>${esc(branchName(o.e.branch))}${o.e.job ? ` · ${esc(o.e.job)}` : ''}</small></span>${o.e.code ? `<span class="rec-code">${esc(o.e.code)}</span>` : ''}</li>`
            : (state.branch
                ? `<li role="option" data-i="${i}" class="rec-add ${i === active ? 'on' : ''}"><i class="bi bi-person-plus"></i> ${esc(text.addNew.replace('%s', raw))} ${esc(branchName(state.branch))}</li>`
                : `<li class="rec-none">${esc(text.pickBranchFirst)}</li>`)
        ).join('') || `<li class="rec-none">${esc(text.noMatch)}</li>`;
        if (! hits.length && byCode) list.innerHTML = `<li class="rec-none">${esc(text.noMatch)}</li>`;
        list.classList.remove('d-none'); q.setAttribute('aria-expanded', 'true');
    }
    function closeList() { list.classList.add('d-none'); q.setAttribute('aria-expanded', 'false'); options = []; active = -1; }
    function choose(i) {
        const o = options[i]; if (! o) return;
        if (o.type === 'emp') {
            state.employee = o.e.id; state.newName = '';
            q.value = o.e.name;
            pickBranch(o.e.branch, true);
        } else if (state.branch) {
            state.employee = null; state.newName = q.value.trim();
        } else return;
        closeList(); renderEmployee(); update();
    }
    function clearEmployee() { state.employee = null; state.newName = ''; q.value = ''; renderEmployee(); }
    function renderEmployee() {
        $('r-employee').value = state.employee ?? '';
        $('r-new').value = state.newName;
        $('r-emp-clear').classList.toggle('d-none', ! state.employee && ! state.newName);
    }
    q.addEventListener('input', () => { if (state.employee || state.newName) { state.employee = null; state.newName = ''; renderEmployee(); update(); } search(); });
    q.addEventListener('keydown', e => {
        if (list.classList.contains('d-none')) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            list.querySelectorAll('[data-i]').forEach(li => li.classList.toggle('on', Number(li.dataset.i) === active));
        } else if (e.key === 'Enter') { e.preventDefault(); choose(active); }
        else if (e.key === 'Escape') closeList();
    });
    list.addEventListener('mousedown', e => { const li = e.target.closest('[data-i]'); if (li) { e.preventDefault(); choose(Number(li.dataset.i)); } });
    q.addEventListener('blur', () => setTimeout(closeList, 120));
    $('r-emp-clear').addEventListener('click', () => { clearEmployee(); update(); q.focus(); });

    /* ---------- Type and violation ---------- */
    const chip = (attrs, html, on, ghost) => `<button type="button" class="rec-chip${ghost ? ' rec-chip-ghost' : ''}${on ? ' on' : ''}" ${attrs}>${html}</button>`;
    function renderTypes() {
        $('r-types').innerHTML = categories.map(c => chip(`data-cat="${c.id}"`, `<i class="cv-dot" style="--c:${c.color}"></i>${esc(c.label)}`, c.id === state.category)).join('');
    }
    function renderItems() {
        if (! state.category) return;
        $('r-items').innerHTML = items.filter(i => i.cat === state.category).map(i => chip(`data-item="${i.id}"`, esc(i.label), i.id === state.item)).join('')
            + chip('data-item=""', text.other, state.item === 0, true);
    }
    const currentItem = () => items.find(i => i.id === state.item) || null;
    // The department follows the violation's default until it is changed by hand.
    let deptManual = false;
    function followDept() {
        if (deptManual) return;
        const d = (currentItem() || categories.find(c => c.id === state.category))?.dept;
        if (d && $('r-dept-pick').querySelector(`option[value="${d}"]`)) $('r-dept-pick').value = d;
    }
    $('r-dept-pick').addEventListener('change', () => { deptManual = true; update(); });
    $('r-types').addEventListener('click', e => {
        const c = e.target.closest('[data-cat]'); if (! c) return;
        state.category = Number(c.dataset.cat); state.item = null;
        renderTypes(); renderItems(); followDept(); update();
    });
    $('r-items').addEventListener('click', e => {
        const c = e.target.closest('[data-item]'); if (! c) return;
        state.item = c.dataset.item === '' ? 0 : Number(c.dataset.item);
        $('r-minutes').dataset.manual = '';
        renderItems(); followDept(); lateFromTime(); update();
        if (state.item === 0) $('r-desc').focus();
    });

    /* ---------- Minutes: late opening is worked out from the time and the opening time ---------- */
    try { const saved = localStorage.getItem('cctv.opening'); if (saved) $('r-opening').value = saved; } catch (e) {}
    $('r-opening').addEventListener('change', () => { try { localStorage.setItem('cctv.opening', $('r-opening').value); } catch (e) {} $('r-minutes').dataset.manual = ''; lateFromTime(); update(); });
    function lateFromTime() {
        const item = currentItem(), isLate = item?.minutes === 'minutes_late';
        $('r-minutes-wrap').classList.toggle('d-none', ! item?.minutes);
        $('r-opening-wrap').classList.toggle('d-none', ! isLate);
        $('r-minutes-label').textContent = isLate ? text.late : text.minutes;
        const t = $('r-time24').value, o = $('r-opening').value;
        if (isLate && t && o && $('r-minutes').dataset.manual !== '1') {
            const diff = (+t.slice(0, 2) * 60 + +t.slice(3)) - (+o.slice(0, 2) * 60 + +o.slice(3));
            if (diff > 0) {
                $('r-minutes').value = diff;
                $('r-minutes-label').innerHTML = `<b>${diff}</b> ${esc(text.late)}`;
            }
        }
        document.querySelectorAll('[data-min]').forEach(b => b.classList.toggle('on', b.dataset.min === $('r-minutes').value));
    }
    document.querySelectorAll('[data-min]').forEach(b => b.addEventListener('click', () => { $('r-minutes').value = b.dataset.min; $('r-minutes').dataset.manual = '1'; lateFromTime(); update(); }));
    $('r-minutes').addEventListener('input', () => { $('r-minutes').dataset.manual = '1'; lateFromTime(); update(); });
    $('r-desc').addEventListener('input', update);

    /* ---------- What will be saved ---------- */
    function update() {
        const item = currentItem(), b = branches.find(x => x.id === state.branch);
        $('r-category').value = state.category ?? '';
        $('r-item').value = item ? item.id : '';
        $('r-dept').value = $('r-dept-pick').value;
        $('r-desc-hint').textContent = state.item === 0 ? text.describe : text.optional;
        $('r-desc').required = state.item === 0;
        const emp = state.employee ? employees.find(e => e.id === state.employee)?.name : (state.newName || text.unknown);
        const vio = item ? item.label : (state.item === 0 ? text.other : null);
        const missing = [! b && text.branch, ! state.category && text.type, state.category && state.item === null && text.violation].filter(Boolean);
        $('r-picked').innerHTML = `<span>${b ? `<b>${esc(b.name)}</b>` : `<span class="is-missing">${esc(text.noBranch)}</span>`}</span>`
            + (state.employee || state.newName ? `<span><b>${esc(emp)}</b>${state.newName ? ' +' : ''}</span>` : '');
        const ready = b && state.category && (item || (state.item === 0 && $('r-desc').value.trim()));
        $('r-save').disabled = ! ready;
        $('r-preview').innerHTML = ready
            ? `<b>${esc(b.name)}</b> · ${esc(emp)} · ${esc(vio)}${$('r-minutes').value && item?.minutes ? ` · <b>${esc($('r-minutes').value)}</b> د` : ''}${$('r-time').value ? ` · ${esc($('r-time').value)} ${state.ampm}` : ''}`
            : (missing.length ? `${esc(text.needs)} ${missing.map(esc).join('، ')}` : '');
    }

    document.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && ! $('r-save').disabled) $('recForm').requestSubmit(); });
    $('recForm').addEventListener('submit', () => { $('r-save').disabled = true; });
    $('cleanForm').addEventListener('submit', () => { $('c-date').value = $('r-date').value; $('r-clean').disabled = true; });

    /* ---------- Start: what a failed save sent back, or the branch kept after a save ---------- */
    renderTypes();
    if (old.branch) pickBranch(Number(old.branch));
    if (old.employee) { const e = employees.find(x => String(x.id) === old.employee); if (e) { state.employee = e.id; q.value = e.name; } }
    if (old.newEmployee) { state.newName = old.newEmployee; q.value = old.newEmployee; }
    renderEmployee();
    if (old.category) { state.category = Number(old.category); renderTypes(); renderItems(); }
    if (old.item) { state.item = Number(old.item); renderItems(); } else if (old.category && old.hasDescription) { state.item = 0; renderItems(); }
    followDept();
    if (old.department) { $('r-dept-pick').value = old.department; deptManual = true; }
    if (old.time) setTime24(old.time);
    if (old.minutes) { $('r-minutes').value = old.minutes; $('r-minutes').dataset.manual = '1'; }
    lateFromTime(); update();
    if (old.branch) document.querySelector('.rec-tile.on')?.scrollIntoView({ block: 'nearest' });
    q.focus();
})();
</script>
@endpush
