@extends('layouts.app')
@section('title', 'التقارير')

@section('content')
<h4 class="fw-bold mb-3">التقارير</h4>

{{-- tickets by source --}}
<div class="row g-3 mb-3">
    @php
        $src = [
            ['شيك ليست مدير الفرع', $bySource['store'] ?? 0, 'clipboard-check', '#6366f1', 'store'],
            ['زيارات الأريا مانجر', $bySource['area'] ?? 0, 'binoculars', '#0d9488', 'area'],
            ['طلبات الصيانة', $bySource['maintenance'] ?? 0, 'tools', '#9C1E6E', 'maintenance'],
        ];
    @endphp
    @foreach($src as [$l,$v,$i,$c,$key])
        <div class="col-md-4">
            <a href="{{ route('tickets.index', ['source' => $key]) }}" class="text-decoration-none">
                <div class="card p-3 d-flex flex-row align-items-center" style="cursor:pointer">
                    <div class="rounded-circle d-flex align-items-center justify-content-center me-3"
                         style="width:46px;height:46px;background:{{ $c }}1a;color:{{ $c }}"><i class="bi bi-{{ $i }} fs-5"></i></div>
                    <div><div class="text-muted small">{{ $l }}</div><div class="fw-bold fs-4 text-dark">{{ $v }}</div></div>
                </div>
            </a>
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">الزيارات حسب الفرع</h6>
            <table class="table table-sm mb-0 js-table">
                <thead><tr><th>الفرع</th><th class="text-end" data-sum>الزيارات</th></tr></thead>
                <tbody>
                @foreach($visitsPerBranch as $row)
                    <tr><td>{{ optional($row->branch)->branch_name }}</td><td class="text-end fw-semibold">{{ $row->total }}</td></tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold"><tr><td class="text-end">الإجمالي</td><td class="text-end">0</td></tr></tfoot>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">التذاكر حسب الإدارة</h6>
            <table class="table table-sm mb-0 js-table">
                <thead><tr><th>الإدارة</th><th class="text-center" data-sum>مفتوحة</th><th class="text-center" data-sum>مقفولة</th></tr></thead>
                <tbody>
                @foreach($byDept as $d)
                    <tr><td>{{ $d->name }}</td><td class="text-center">{{ $d->open }}</td><td class="text-center">{{ $d->closed }}</td></tr>
                @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold"><tr><td class="text-end">الإجمالي</td><td class="text-center">0</td><td class="text-center">0</td></tr></tfoot>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">متوسط زمن الحل (ساعات)</h6>
            <table class="table table-sm mb-0"><tbody>
                @forelse($resolution as $row)
                    <tr><td>{{ optional($row->department)->name ?? '—' }}</td><td class="text-end fw-semibold">{{ round($row->avg_hours ?? 0, 1) }} س</td></tr>
                @empty
                    <tr><td class="text-muted small">لا توجد تذاكر مقفولة بعد.</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold">طلبات متكررة</h6>
            <table class="table table-sm mb-0"><tbody>
                @forelse($repeated as $row)
                    <tr><td><span class="badge text-bg-light text-capitalize">{{ $row->category }}</span> {{ optional($row->branch)->branch_name }}</td><td class="text-end fw-semibold">{{ $row->total }}×</td></tr>
                @empty
                    <tr><td class="text-muted small">لا يوجد.</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </div>

    <div class="col-12">
        <div class="card p-3">
            <h6 class="fw-bold">أداء الموظفين</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>الموظف</th><th>الإدارة</th><th class="text-center">مُسندة</th><th class="text-center">مقفولة</th><th class="text-center">معلّقة</th><th class="text-center">معاد فتحها</th><th class="text-center">م. الساعات</th><th>التقييم</th></tr></thead>
                <tbody>
                @forelse($performance as $u)
                    <tr>
                        <td>{{ $u->name }}</td>
                        <td>{{ optional($u->department)->name }}</td>
                        <td class="text-center">{{ $u->assigned }}</td>
                        <td class="text-center">{{ $u->closed }}</td>
                        <td class="text-center">{{ $u->pending }}</td>
                        <td class="text-center">{{ $u->reopened }}</td>
                        <td class="text-center">{{ $u->avg_hours ?? '—' }}</td>
                        <td><span class="badge {{ $u->performance === 'ممتاز' ? 'text-bg-success' : ($u->performance === 'جيد' ? 'text-bg-info' : 'text-bg-warning') }}">{{ $u->performance }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-muted small">لا توجد إسنادات بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-12">
        <div class="card p-3">
            <h6 class="fw-bold">التذاكر المتأخرة</h6>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>الكود</th><th>العنوان</th><th>الفرع</th><th>الإدارة</th><th>الاستحقاق</th></tr></thead>
                <tbody>
                @forelse($overdue as $t)
                    <tr class="table-danger" onclick="window.location='{{ route('tickets.show', $t) }}'" style="cursor:pointer">
                        <td>{{ $t->reference }}</td><td>{{ Str::limit($t->title, 40) }}</td>
                        <td>{{ optional($t->branch)->branch_name }}</td><td>{{ optional($t->department)->name }}</td>
                        <td>{{ optional($t->due_at)->format('d M H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted small">لا توجد تذاكر متأخرة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
