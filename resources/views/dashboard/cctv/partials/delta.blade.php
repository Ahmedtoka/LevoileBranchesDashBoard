{{-- Change against the previous period. More violations is bad (red, up arrow), fewer is good (green, down arrow); icon + text, never colour alone. --}}
@php $pct = \App\Support\CctvDashboard::change($now, $prev); @endphp
@if($prev === 0 && $now === 0)
    <span class="cv-delta is-flat">—</span>
@elseif($pct === null)
    <span class="cv-delta is-flat" title="{{ dt('ماكانش فيه مخالفات في الفترة السابقة، فمفيش مقارنة', 'none in the previous period, nothing to compare') }}">{{ dt('جديد', 'new') }}</span>
@elseif($pct > 0)
    <span class="cv-delta is-up" title="{{ $prev }} → {{ $now }}"><i class="bi bi-arrow-up-short"></i>{{ $pct }}%</span>
@elseif($pct < 0)
    <span class="cv-delta is-down" title="{{ $prev }} → {{ $now }}"><i class="bi bi-arrow-down-short"></i>{{ abs($pct) }}%</span>
@else
    <span class="cv-delta is-flat" title="{{ $prev }} → {{ $now }}"><i class="bi bi-dash"></i>{{ dt('ثابت', 'same') }}</span>
@endif
