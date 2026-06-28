@extends('layouts.app')
@section('title', 'KPIs')

@section('content')
<h4 class="fw-bold mb-1"><i class="bi bi-speedometer2 text-primary me-2"></i>{{ dt('مؤشرات الأداء والتقارير','KPIs & Reports') }}</h4>
<p class="text-muted small">{{ dt('قياس دورة حياة التذاكر، أداء الزيارات، ونزاهة الشيك ليست اليومية.','Ticket lifecycle, visit performance, and daily checklist integrity.') }}</p>

@include('partials.filterbar', ['range' => $range])

{{-- Headline numbers --}}
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 text-center h-100">
            <div class="value text-primary">{{ $kpi['avg_resolution_h'] ?? '—' }}<small class="fs-6 text-muted">h</small></div>
            <div class="text-muted small">{{ dt('متوسط زمن الحل','Avg resolution time') }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 text-center h-100">
            <div class="value text-info">{{ $kpi['avg_assign_min'] ?? '—' }}<small class="fs-6 text-muted">m</small></div>
            <div class="text-muted small">{{ dt('متوسط زمن التعيين','Avg time to assign') }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card p-3 text-center h-100">
            <div class="value text-success">{{ $kpi['closed'] }}</div>
            <div class="text-muted small">{{ dt('تذاكر مُغلقة','Closed tickets') }}</div>
        </div>
    </div>
    @if($ops && $visitKpi)
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 text-center h-100">
                <div class="value {{ ($visitKpi['flagged'] ?? 0) > 0 ? 'text-danger' : 'text-secondary' }}">{{ $visitKpi['flagged'] }}</div>
                <div class="text-muted small">{{ dt('زيارات تحتاج مراجعة','Visits to review') }}</div>
            </div>
        </div>
    @endif
</div>

{{-- Report cards --}}
<div class="row g-3">
    <div class="col-md-6 col-lg-3">
        <a href="{{ route('kpis.tickets', ['range' => $range->key]) }}" class="text-decoration-none">
            <div class="card p-3 h-100">
                <div class="d-flex align-items-center mb-2"><i class="bi bi-hourglass-split fs-3 text-primary me-2"></i>
                    <h6 class="fw-bold mb-0">{{ dt('دورة حياة التذاكر / SLA','Ticket lifecycle / SLA') }}</h6></div>
                <p class="text-muted small mb-0">{{ dt('فُتحت ← عُيّنت ← قُبلت ← بدأ العمل ← أُصلحت ← أُغلقت، والأزمنة بين المراحل.','Opened → assigned → accepted → started → fixed → closed, and the time between each stage.') }}</p>
            </div>
        </a>
    </div>

    @if($ops)
        <div class="col-md-6 col-lg-3">
            <a href="{{ route('kpis.visits', ['range' => $range->key]) }}" class="text-decoration-none">
                <div class="card p-3 h-100">
                    <div class="d-flex align-items-center mb-2"><i class="bi bi-clock-history fs-3 text-info me-2"></i>
                        <h6 class="fw-bold mb-0">{{ dt('أداء الزيارات','Visit performance') }}</h6></div>
                    <p class="text-muted small mb-0">{{ dt('هل وصل الأريا مانجر في معاده؟ ومدة الزيارة من الحضور حتى الإغلاق.','Did the area manager arrive on time? And the duration from check-in to close.') }}</p>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a href="{{ route('kpis.integrity', ['range' => $range->key]) }}" class="text-decoration-none">
                <div class="card p-3 h-100">
                    <div class="d-flex align-items-center mb-2"><i class="bi bi-shield-check fs-3 text-danger me-2"></i>
                        <h6 class="fw-bold mb-0">{{ dt('نزاهة الشيك ليست','Checklist integrity') }}</h6></div>
                    <p class="text-muted small mb-0">{{ dt('هل المدير بيعمل اتشيك حقيقي ولا «طخ طخ»؟ الوقت بين كل سؤال والثاني ونسبة الصح.','Real check or rushing? Time between answers and pass rate.') }}</p>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a href="{{ route('kpis.compliance', ['range' => $range->key]) }}" class="text-decoration-none">
                <div class="card p-3 h-100">
                    <div class="d-flex align-items-center mb-2"><i class="bi bi-calendar-check fs-3 text-success me-2"></i>
                        <h6 class="fw-bold mb-0">{{ dt('التزام مديري الفروع','Daily compliance') }}</h6></div>
                    <p class="text-muted small mb-0">{{ dt('التزام مدير الفرع بعمل الشيك ليست اليومية وكل ليست بتاخد وقت قد ايه.','Daily checklist completion per store manager, and how long each one takes.') }}</p>
                </div>
            </a>
        </div>
    @endif
</div>
@endsection
