{{-- Change of a compliance rate in points. Higher is better here (green, up arrow); icon + text, never colour alone. --}}
@if($now === null || $prev === null)
    <span class="cv-delta is-flat">—</span>
@elseif($now > $prev)
    <span class="cv-delta is-down" title="{{ $prev }}% → {{ $now }}%"><i class="bi bi-arrow-up-short"></i>{{ $now - $prev }} {{ dt('نقطة', 'pts') }}</span>
@elseif($now < $prev)
    <span class="cv-delta is-up" title="{{ $prev }}% → {{ $now }}%"><i class="bi bi-arrow-down-short"></i>{{ $prev - $now }} {{ dt('نقطة', 'pts') }}</span>
@else
    <span class="cv-delta is-flat"><i class="bi bi-dash"></i>{{ dt('ثابت', 'same') }}</span>
@endif
