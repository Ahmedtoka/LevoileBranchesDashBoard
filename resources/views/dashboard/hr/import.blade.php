@extends('layouts.app')
@section('title', dt('تحديث ملف الموظفين', 'Update the staff file'))
@include('dashboard.cctv.partials.style')

@section('content')
<div class="cv hr-i">
<h4 class="fw-bold mb-1">{{ dt('تحديث ملف الموظفين', 'Update the staff file') }}</h4>
<p class="text-muted small mb-3">{{ dt('ارفع ملف الموظفين اللي بيطلع من سيستم HR (Excel). هتشوف الأول إيه اللي هيتغير، وبعدين تأكد الحفظ.', 'Upload the staff export from the HR system (Excel). You see what would change first, then confirm.') }}</p>

@if($errors->any())<div class="alert alert-danger py-2 mb-3">@foreach($errors->all() as $err)<div>{{ $err }}</div>@endforeach</div>@endif

@if(! $plan)
    <form method="POST" action="{{ route('hr.preview') }}" enctype="multipart/form-data" class="cv-panel hr-i-drop">
        @csrf
        <i class="bi bi-file-earmark-spreadsheet"></i>
        <p class="fw-semibold mb-1">{{ dt('اختار ملف Excel', 'Choose an Excel file') }}</p>
        <p class="small text-muted mb-3">{{ dt('لازم يكون فيه عمود «الكود» وعمود «الاسم». الموظف بيتعرف بالكود، فلو رفعت نفس الملف تاني مش هيتكرر حد.', 'It needs «الكود» and «الاسم» columns. Employees are matched by code, so uploading the same file again duplicates no one.') }}</p>
        <input type="file" name="file" accept=".xlsx,.xls" required class="form-control mb-3">
        <button class="btn btn-primary"><i class="bi bi-eye"></i> {{ dt('اعرض التغييرات', 'Show the changes') }}</button>
    </form>
@else
    @php
        $people = collect($plan['people']);
        $cells = [
            [dt('في الملف', 'In the file'), $people->count(), 'main'],
            [dt('جديد', 'New'), count($plan['new']), ''],
            [dt('بياناتهم اتغيرت', 'Changed'), count($plan['changed']), ''],
            [dt('هيبقوا غير نشطين', 'Will become inactive'), count($plan['gone']), count($plan['gone']) ? 'warn' : ''],
            [dt('أسماء الكاميرات اللي هتتربط', 'CCTV names to link'), count($plan['links']) + count($plan['merges']), ''],
        ];
    @endphp
    <section class="cv-panel">
        <p class="small text-muted mb-2"><i class="bi bi-file-earmark-spreadsheet"></i> {{ $fileName }}</p>
        <div class="hr-i-cells">
            @foreach($cells as [$label, $n, $tone])<div @class(['hr-i-cell', 'is-'.$tone => $tone])><span>{{ $label }}</span><b>{{ $n }}</b></div>@endforeach
        </div>
        <p class="small text-muted mt-2 mb-0">{{ $people->whereNotNull('branch_id')->count() }} {{ dt('في الفروع', 'in branches') }} ·
            {{ collect($plan['places'])->map(fn ($n, $p) => trim($p).' '.$n)->implode(' · ') }}</p>
    </section>

    @if($plan['changed'])
        <section class="cv-panel mt-3">
            <h2 class="cv-h">{{ dt('اللي بياناتهم اتغيرت', 'Changed') }} <small class="text-muted fw-normal">{{ count($plan['changed']) }}</small></h2>
            <ul class="hr-i-list">
                @foreach(array_slice($plan['changed'], 0, 40) as $code)
                    @php
                        $now = $existing[$code] ?? null; $p = $plan['people'][$code];
                        $diff = collect(['name' => dt('الاسم', 'name'), 'job_title' => dt('الوظيفة', 'job'), 'section' => dt('القسم', 'section'), 'department' => dt('الإدارة', 'department'), 'work_location' => dt('مكان العمل', 'place'), 'manager_code' => dt('المدير', 'manager'), 'phone' => dt('التليفون', 'phone')])
                            ->filter(fn ($l, $f) => $now && array_key_exists($f, $p) && (string) $now->{$f} !== (string) $p[$f])
                            ->map(fn ($l, $f) => $l.': '.($now->{$f} ?: '—').' ← '.($p[$f] ?: '—'));
                        if ($now && ! $now->active) { $diff->push(dt('رجع نشط', 'active again')); }
                    @endphp
                    <li><b>{{ $p['name'] }}</b> <small class="text-muted">{{ $code }}</small><span>{{ $diff->implode(' · ') }}</span></li>
                @endforeach
            </ul>
            @if(count($plan['changed']) > 40)<p class="small text-muted mb-0">{{ dt('و', 'and') }} {{ count($plan['changed']) - 40 }} {{ dt('كمان', 'more') }}</p>@endif
        </section>
    @endif

    @if($plan['gone'])
        <section class="cv-panel mt-3">
            <h2 class="cv-h">{{ dt('مش موجودين في الملف، هيبقوا غير نشطين', 'Not in the file: will become inactive') }} <small class="text-muted fw-normal">{{ count($plan['gone']) }}</small></h2>
            <ul class="hr-i-list">
                @foreach(array_slice($plan['gone'], 0, 40) as $code)<li><b>{{ $existing[$code]?->name }}</b> <small class="text-muted">{{ $code }} · {{ $existing[$code]?->job_title }}</small></li>@endforeach
            </ul>
        </section>
    @endif

    @if($plan['new'])
        <section class="cv-panel mt-3">
            <h2 class="cv-h">{{ dt('جديد', 'New') }} <small class="text-muted fw-normal">{{ count($plan['new']) }}</small></h2>
            <ul class="hr-i-list hr-i-cols">
                @foreach(array_slice($plan['new'], 0, 60) as $code)<li><b>{{ $plan['people'][$code]['name'] }}</b> <small class="text-muted">{{ $code }} · {{ $plan['people'][$code]['job_title'] ?? '' }}</small></li>@endforeach
            </ul>
            @if(count($plan['new']) > 60)<p class="small text-muted mb-0">{{ dt('و', 'and') }} {{ count($plan['new']) - 60 }} {{ dt('كمان', 'more') }}</p>@endif
        </section>
    @endif

    <div class="hr-i-actions">
        <a href="{{ route('hr.import') }}" class="btn btn-light">{{ dt('ملف تاني', 'Another file') }}</a>
        <form method="POST" action="{{ route('hr.apply') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button class="btn btn-primary" @disabled(! $plan['new'] && ! $plan['changed'] && ! $plan['gone'] && ! $plan['links'] && ! $plan['merges'])>
                <i class="bi bi-check2"></i> {{ dt('احفظ التغييرات', 'Save the changes') }}</button>
        </form>
    </div>
    @if(! $plan['new'] && ! $plan['changed'] && ! $plan['gone'] && ! $plan['links'] && ! $plan['merges'])
        <p class="text-muted small text-end">{{ dt('الملف ده زي اللي في السيستم بالظبط، مفيش حاجة تتحفظ.', 'This file matches the system exactly: nothing to save.') }}</p>
    @endif
@endif
</div>
@endsection

@push('head')
<style>
    .hr-i { max-inline-size: 60rem; }
    .hr-i-drop { text-align: center; padding: 2rem 1.5rem; }
    .hr-i-drop > i { font-size: 2.4rem; color: var(--brand); }
    .hr-i-drop input { max-inline-size: 26rem; margin-inline: auto; }
    .hr-i-cells { display: grid; grid-template-columns: repeat(5, 1fr); gap: .5rem; }
    .hr-i-cell { background: #faf7f9; border-radius: 11px; padding: .6rem .8rem; display: flex; flex-direction: column; gap: .15rem; }
    .hr-i-cell span { font-size: .75rem; color: var(--muted); }
    .hr-i-cell b { font-size: 1.5rem; font-weight: 800; }
    .hr-i-cell.is-main { background: var(--brand); color: #fff; }
    .hr-i-cell.is-main span { color: #f3dbe9; }
    .hr-i-cell.is-warn { background: #fdf3dc; }
    .hr-i-list { list-style: none; margin: 0; padding: 0; font-size: .85rem; }
    .hr-i-list li { padding: .35rem 0; border-bottom: 1px solid var(--line); }
    .hr-i-list li span { display: block; color: var(--ink-2); font-size: .78rem; }
    .hr-i-cols { columns: 2; column-gap: 2rem; }
    .hr-i-cols li { break-inside: avoid; }
    .hr-i-actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }
    @media (max-width: 767.98px) { .hr-i-cells { grid-template-columns: repeat(2, 1fr); } .hr-i-cols { columns: 1; } }
</style>
@endpush
