@extends('layouts.app')
@section('title', 'Checklist integrity')

@section('content')
<a href="{{ route('kpis.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-{{ app()->getLocale()==='ar' ? 'right' : 'left' }}"></i> KPIs</a>
<h4 class="fw-bold mt-1 mb-1"><i class="bi bi-shield-check text-danger me-2"></i>{{ dt('نزاهة الشيك ليست','Checklist integrity') }}</h4>
<p class="text-muted small">{{ dt('بنقيس الوقت بين كل سؤال والثاني. الزيارة «تحتاج مراجعة» لو الوقت قصير جدًا (أقل من 8 ثوانٍ بين الأسئلة) مع نسبة نجاح عالية — مؤشر على «طخ طخ» بدون اتشيك حقيقي.','We measure the time between consecutive answers. A visit is "flagged" when the median gap is very short (under 8 seconds) with a high pass rate — a sign of rushing without a real check.') }}</p>

@include('partials.filterbar', ['range' => $range])

<div class="row g-2 mb-3">
    @php $boxes = [
        [dt('عدد الزيارات','Visits analysed'), $summary['visits'], 'secondary'],
        [dt('تحتاج مراجعة','Flagged'), $summary['flagged'], ($summary['flagged'] ?? 0) > 0 ? 'danger' : 'success'],
        [dt('نسبة المُعلَّمة','Flagged %'), $summary['flagged_pct'] !== null ? $summary['flagged_pct'].'%' : '—', 'warning'],
        [dt('متوسط الثواني/سؤال','Avg sec/question'), $summary['avg_sec_per_q'] !== null ? $summary['avg_sec_per_q'].'s' : '—', 'info'],
    ]; @endphp
    @foreach($boxes as [$l,$v,$c])
        <div class="col"><div class="card stat-card p-2 text-center h-100"><div class="value text-{{ $c }}" style="font-size:1.4rem">{{ $v }}</div><div class="text-muted" style="font-size:.72rem">{{ $l }}</div></div></div>
    @endforeach
</div>

<div class="card p-3 mb-3">
    <h6 class="fw-bold mb-2">{{ dt('حسب المسؤول','By person') }}</h6>
    <table class="table table-sm table-hover align-middle mb-0 js-table">
        <thead><tr><th>{{ dt('الاسم','Name') }}</th><th>{{ dt('الدور','Role') }}</th><th class="text-center">{{ dt('زيارات','Visits') }}</th><th class="text-center">{{ dt('تحتاج مراجعة','Flagged') }}</th><th class="text-center">{{ dt('النسبة','%') }}</th><th class="text-center">{{ dt('ثانية/سؤال','sec/q') }}</th><th class="text-center">{{ dt('نسبة الصح','Pass %') }}</th></tr></thead>
        <tbody>
        @forelse($byManager as $m)
            <tr class="{{ $m['flagged_pct'] >= 50 ? 'table-danger' : '' }}">
                <td class="small fw-semibold">{{ $m['name'] }}</td>
                <td class="small text-muted">{{ $m['role'] }}</td>
                <td class="text-center">{{ $m['visits'] }}</td>
                <td class="text-center">{{ $m['flagged'] }}</td>
                <td class="text-center"><span class="badge text-bg-{{ $m['flagged_pct'] >= 50 ? 'danger' : ($m['flagged_pct'] >= 20 ? 'warning' : 'success') }}">{{ $m['flagged_pct'] }}%</span></td>
                <td class="text-center small">{{ $m['avg_sec_per_q'] }}s</td>
                <td class="text-center small">{{ $m['avg_pass_rate'] }}%</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-muted small p-3">{{ dt('لا توجد بيانات في هذه الفترة.','No data in this period.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card p-3">
    <h6 class="fw-bold mb-2"><i class="bi bi-flag text-danger me-1"></i>{{ dt('زيارات تحتاج مراجعة','Flagged visits') }}</h6>
    <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>{{ dt('المسؤول','Person') }}</th><th>{{ dt('الفرع','Branch') }}</th><th>{{ dt('التاريخ','Date') }}</th><th class="text-center">{{ dt('عدد الأسئلة','Answers') }}</th><th class="text-center">{{ dt('وسيط الفجوة','Median gap') }}</th><th class="text-center">{{ dt('نسبة الصح','Pass rate') }}</th></tr></thead>
        <tbody>
        @forelse($suspicious as $v)
            <tr>
                <td class="small fw-semibold">{{ $v['user_name'] }}</td>
                <td class="small">{{ $v['branch'] }}</td>
                <td class="small">{{ $v['date'] }}</td>
                <td class="text-center small">{{ $v['answers'] }}</td>
                <td class="text-center"><span class="badge text-bg-danger">{{ $v['median_gap'] }}s</span></td>
                <td class="text-center small">{{ round($v['pass_rate'] * 100) }}%</td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-muted small p-3">{{ dt('لا توجد زيارات مشبوهة 👏','No suspicious visits 👏') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
