@extends('layouts.app')
@section('title', 'Daily compliance')

@section('content')
<a href="{{ route('kpis.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-{{ app()->getLocale()==='ar' ? 'right' : 'left' }}"></i> KPIs</a>
<h4 class="fw-bold mt-1 mb-1"><i class="bi bi-calendar-check text-success me-2"></i>{{ dt('التزام مديري الفروع بالشيك ليست اليومية','Store-manager daily checklist compliance') }}</h4>
<p class="text-muted small">{{ dt('لكل مدير فرع: عمل الشيك ليست في كام يوم من أيام الفترة، وكل ليست بتاخد وقت قد ايه.','For each store manager: how many days they completed the daily checklist, and how long each one takes.') }}</p>

@include('partials.filterbar', ['range' => $range])

<div class="row g-2 mb-3">
    @php $boxes = [
        [dt('عدد المديرين','Managers'), $overall['managers'], 'secondary'],
        [dt('أيام متوقعة','Expected days'), $overall['expected'], 'info'],
        [dt('متوسط الالتزام','Avg compliance'), $overall['avg_compliance'] !== null ? $overall['avg_compliance'].'%' : '—', ($overall['avg_compliance'] !== null && $overall['avg_compliance'] >= 80) ? 'success' : 'warning'],
        [dt('متوسط مدة الليست','Avg checklist time'), $overall['avg_duration'] !== null ? $overall['avg_duration'].' '.dt('دقيقة','min') : '—', 'primary'],
    ]; @endphp
    @foreach($boxes as [$l,$v,$c])
        <div class="col"><div class="card stat-card p-2 text-center h-100"><div class="value text-{{ $c }}" style="font-size:1.4rem">{{ $v }}</div><div class="text-muted" style="font-size:.72rem">{{ $l }}</div></div></div>
    @endforeach
</div>

<div class="card p-3 mb-3">
    <h6 class="fw-bold mb-2">{{ dt('ملخص الالتزام','Compliance summary') }}</h6>
    <table class="table table-sm table-hover align-middle mb-0 js-table">
        <thead><tr><th>{{ dt('المدير','Manager') }}</th><th>{{ dt('الفرع','Branch') }}</th><th class="text-center">{{ dt('تم','Done') }}</th><th class="text-center">{{ dt('متوقع','Expected') }}</th><th class="text-center">{{ dt('الالتزام','Compliance') }}</th><th class="text-center">{{ dt('متوسط المدة','Avg time') }}</th></tr></thead>
        <tbody>
        @forelse($rows as $r)
            <tr class="{{ $r['compliance'] < 60 ? 'table-warning' : '' }}">
                <td class="small fw-semibold">{{ $r['name'] }}</td>
                <td class="small">{{ $r['branch'] }}</td>
                <td class="text-center">{{ $r['done'] }}</td>
                <td class="text-center text-muted">{{ $r['expected'] }}</td>
                <td class="text-center"><span class="badge text-bg-{{ $r['compliance'] >= 80 ? 'success' : ($r['compliance'] >= 60 ? 'warning' : 'danger') }}">{{ $r['compliance'] }}%</span></td>
                <td class="text-center small">{{ $r['avg_duration'] !== null ? $r['avg_duration'].' '.dt('د','m') : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-muted small p-3">{{ dt('لا يوجد مديرو فروع.','No store managers.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Day-by-day heatmap --}}
<div class="card p-3">
    <h6 class="fw-bold mb-1">{{ dt('سجل يومي','Day-by-day') }}</h6>
    <p class="text-muted small mb-2">
        <span class="d-inline-block rounded" style="width:12px;height:12px;background:#198754"></span> {{ dt('تم','Done') }}
        &nbsp;<span class="d-inline-block rounded" style="width:12px;height:12px;background:#f1d4d4;border:1px solid #dc3545"></span> {{ dt('لم يتم','Missed') }}
        &nbsp;— {{ dt('مرّر فوق المربع لرؤية مدة الليست.','Hover a cell to see the checklist duration.') }}
    </p>
    <div style="overflow-x:auto">
        <table class="table table-sm table-bordered align-middle mb-0" style="white-space:nowrap">
            <thead>
                <tr>
                    <th style="position:sticky;{{ app()->getLocale()==='ar' ? 'right' : 'left' }}:0;background:#fff;z-index:1">{{ dt('المدير','Manager') }}</th>
                    @foreach($days as $day)
                        <th class="text-center px-1" style="font-size:.65rem">{{ \Illuminate\Support\Carbon::parse($day)->format('d/m') }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
            @foreach($rows as $r)
                <tr>
                    <td class="small fw-semibold" style="position:sticky;{{ app()->getLocale()==='ar' ? 'right' : 'left' }}:0;background:#fff;z-index:1">{{ $r['name'] }}</td>
                    @foreach($days as $day)
                        @php $dur = $r['grid'][$day] ?? null; @endphp
                        @if($dur !== null)
                            <td class="text-center p-0" title="{{ $dur }} {{ dt('دقيقة','min') }}" style="background:#198754;color:#fff;font-size:.6rem;width:26px">{{ $dur }}</td>
                        @else
                            <td class="text-center p-0" style="background:#f1d4d4;width:26px">·</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
