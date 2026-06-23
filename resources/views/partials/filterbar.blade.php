@php
    $range = $range ?? \App\Support\DateRange::fromRequest(request());
    $searchable = $searchable ?? false;
    $searchPlaceholder = $searchPlaceholder ?? 'Search…';
@endphp
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <div class="btn-group btn-group-sm">
        @foreach(\App\Support\DateRange::$presets as $key => $label)
            @if($key === 'custom')
                <button class="btn {{ $range->key==='custom' ? 'btn-primary' : 'btn-outline-secondary' }}" data-bs-toggle="collapse" data-bs-target="#customRange">{{ $label }}</button>
            @else
                <a href="{{ request()->fullUrlWithQuery(['range'=>$key,'from'=>null,'to'=>null]) }}"
                   class="btn {{ $range->key===$key ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
            @endif
        @endforeach
    </div>

    @if($searchable)
        <form class="d-flex" method="GET">
            <input type="hidden" name="range" value="{{ $range->key }}">
            @if($range->key==='custom')
                <input type="hidden" name="from" value="{{ $range->customFrom }}">
                <input type="hidden" name="to" value="{{ $range->customTo }}">
            @endif
            <div class="input-group input-group-sm">
                <input name="q" value="{{ request('q') }}" class="form-control" placeholder="{{ $searchPlaceholder }}" style="min-width:230px">
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            </div>
        </form>
    @endif

    <span class="text-muted small ms-auto">{{ $range->from->format('d M') }} – {{ $range->to->format('d M Y') }}</span>
</div>

<div class="collapse {{ $range->key==='custom' ? 'show' : '' }}" id="customRange">
    <form class="card card-body p-2 mb-3" method="GET">
        <input type="hidden" name="range" value="custom">
        @if($searchable && request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
        <div class="d-flex gap-2 align-items-end flex-wrap">
            <div><label class="form-label small mb-0">From</label><input type="date" name="from" value="{{ $range->customFrom }}" class="form-control form-control-sm"></div>
            <div><label class="form-label small mb-0">To</label><input type="date" name="to" value="{{ $range->customTo }}" class="form-control form-control-sm"></div>
            <button class="btn btn-sm btn-primary">Apply</button>
        </div>
    </form>
</div>
