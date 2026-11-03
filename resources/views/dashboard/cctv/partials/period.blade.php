{{-- Period bar for the CCTV pages: data weeks (Saturday-Friday) as quick buttons, all dates, or from / to. --}}
@php
    $isAll = ! $from && ! $to;
    $keep = request()->except(['week', 'from', 'to', 'page']);
@endphp
<form method="GET" class="card p-2 mb-3 d-print-none">
    @foreach($keep as $k => $v)
        @if(is_scalar($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
    @endforeach
    <div class="d-flex flex-wrap gap-2 align-items-end">
        <div class="btn-group btn-group-sm flex-wrap">
            <a href="{{ request()->fullUrlWithQuery(['week' => null, 'from' => null, 'to' => null, 'page' => null]) }}" class="btn {{ $isAll ? 'btn-primary' : 'btn-outline-secondary' }}">{{ dt('كل الفترة', 'All dates') }}</a>
            @foreach(($weeks ?? []) as $w)
                @php $on = $from && $from->isSameDay($w['from']) && $to && $to->isSameDay($w['to']); @endphp
                <a href="{{ request()->fullUrlWithQuery(['week' => $w['from']->toDateString(), 'from' => null, 'to' => null, 'page' => null]) }}"
                   class="btn {{ $on ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $w['from']->format('d/m') }} – {{ $w['to']->format('d/m') }}</a>
            @endforeach
        </div>
        <div>
            <label class="form-label small mb-0">{{ dt('من', 'From') }}</label>
            <input type="date" name="from" value="{{ $from?->toDateString() }}" class="form-control form-control-sm">
        </div>
        <div>
            <label class="form-label small mb-0">{{ dt('إلى', 'To') }}</label>
            <input type="date" name="to" value="{{ $to?->toDateString() }}" class="form-control form-control-sm">
        </div>
        <button class="btn btn-sm btn-outline-primary">{{ dt('عرض', 'Show') }}</button>
        <span class="text-muted small ms-auto">
            @if($isAll) {{ dt('كل التواريخ', 'All dates') }} @else {{ $from?->format('d/m/Y') ?? '…' }} – {{ $to?->format('d/m/Y') ?? '…' }} @endif
        </span>
    </div>
</form>
