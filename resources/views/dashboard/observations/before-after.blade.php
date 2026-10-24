@extends('layouts.app')
@section('title', dt('كنا وبقينا', 'Before & after'))

@section('content')
@php
    $storageUrl = fn (?string $path) => \App\Support\ObservationLabels::storageUrl($path); // relative storage paths only
@endphp

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h4 class="fw-bold mb-0">{{ dt('كنا وبقينا', 'Before & after') }}</h4>
    @if($observations)<span class="badge text-bg-light border">{{ $observations->total() }}</span>@endif
    <a href="{{ $branchId ? route('observations.index', ['branch_id' => $branchId]) : route('observations.index') }}" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-list-ul"></i> {{ dt('سجل الملاحظات', 'Observations register') }}
    </a>
</div>

<form class="card p-3 mb-3" method="GET" action="{{ route('observations.beforeAfter') }}">
    <div class="row g-2 align-items-end">
        <div class="col-md-4 col-12">
            <label class="form-label small mb-1">{{ t('common.branch', 'الفرع') }}</label>
            <select name="branch_id" class="form-select form-select-sm" required>
                <option value="">{{ dt('اختر الفرع', 'Choose a branch') }}</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected((int) $branchId === (int) $b->id)>{{ $b->branch_name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3 col-6">
            <label class="form-label small mb-1">{{ t('tk.status', 'الحالة') }}</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">{{ t('common.all', 'الكل') }}</option>
                @foreach($statuses as $s)<option value="{{ $s }}" @selected($status === $s)>{{ \App\Support\ObservationLabels::status($s) }}</option>@endforeach
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> {{ dt('عرض', 'Show') }}</button>
        </div>
    </div>
</form>

@if($observations === null)
    <div class="card p-4 text-center text-muted">
        <i class="bi bi-images fs-3 d-block mb-2"></i>
        {{ dt('اختر فرعًا لعرض الملاحظات المفتوحة والمتحقق منها بصور قبل وبعد.', 'Choose a branch to see its open and verified observations with before and after photos.') }}
    </div>
@else
<div class="card">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr>
            <th>{{ dt('المرجع', 'Reference') }}</th>
            <th>{{ dt('البند', 'Item') }}</th>
            <th>{{ dt('صورة قبل', 'Before photo') }}</th>
            <th>{{ dt('كنا', 'Before') }}</th>
            <th>{{ dt('بقينا', 'After') }}</th>
            <th>{{ dt('صورة بعد', 'After photo') }}</th>
            <th>{{ t('tk.status', 'الحالة') }}</th>
            <th>{{ dt('تاريخ التحقق', 'Verified on') }}</th>
        </tr></thead>
        <tbody>
        @forelse($observations as $o)
            @php
                $updates = $o->ticket ? $o->ticket->updates->sortByDesc('id')->values() : collect();
                $verification = $o->verifications->sortByDesc(fn ($v) => [$v->verified_at?->getTimestamp() ?? 0, $v->id])->first();
                $beforePhoto = $storageUrl($o->photos->where('kind', 'before')->sortBy('id')->first()?->path);
                $afterPhoto = $storageUrl(
                    $o->photos->where('kind', 'after')->sortBy('id')->first()?->path
                    ?? $updates->first(fn ($u) => $u->evidence_kind === 'after' && filled($u->evidence_path))?->evidence_path
                );
                $ticketNote = $updates->first(fn ($u) => filled($u->note))?->note;
                $item = $o->catalogItem ? dt($o->catalogItem->label_ar, $o->catalogItem->label_en)
                    : ($o->category ? dt($o->category->name_ar, $o->category->name_en) : $o->title);
            @endphp
            <tr>
                <td class="fw-semibold text-nowrap">
                    <a href="{{ route('observations.show', $o) }}" class="text-decoration-none">{{ $o->reference }}</a>
                    @if($o->riskLevel)<div>@include('dashboard.observations.partials.risk-badge', ['risk' => $o->riskLevel])</div>@endif
                </td>
                <td>{{ $item ?: '—' }}</td>
                <td>
                    @if($beforePhoto)
                        <a href="{{ $beforePhoto }}" target="_blank" rel="noopener"><img src="{{ $beforePhoto }}" alt="{{ dt('صورة قبل', 'Before photo') }}" class="rounded border" style="width:72px;height:72px;object-fit:cover" loading="lazy"></a>
                    @else <span class="text-muted">—</span> @endif
                </td>
                <td>{{ \Illuminate\Support\Str::limit((string) $o->description, 140) ?: '—' }}</td>
                <td>
                    @if($ticketNote)<div>{{ \Illuminate\Support\Str::limit($ticketNote, 140) }}</div>@endif
                    @if($verification && filled($verification->notes))
                        <div class="small text-muted"><i class="bi bi-patch-check"></i> {{ \Illuminate\Support\Str::limit($verification->notes, 140) }}</div>
                    @endif
                    @if(! $ticketNote && ! ($verification && filled($verification->notes)))<span class="text-muted">—</span>@endif
                </td>
                <td>
                    @if($afterPhoto)
                        <a href="{{ $afterPhoto }}" target="_blank" rel="noopener"><img src="{{ $afterPhoto }}" alt="{{ dt('صورة بعد', 'After photo') }}" class="rounded border" style="width:72px;height:72px;object-fit:cover" loading="lazy"></a>
                    @else <span class="text-muted">—</span> @endif
                </td>
                <td>@include('dashboard.observations.partials.status-pill', ['status' => $o->status])</td>
                <td class="text-nowrap">{{ $verification?->verified_at?->format('Y-m-d') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-muted small p-3">{{ dt('لا توجد ملاحظات لهذا الفرع.', 'No observations for this branch.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-3">{{ $observations->links() }}</div>
@endif
@endsection
