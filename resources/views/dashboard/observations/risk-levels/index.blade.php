@extends('layouts.app')
@section('title', t('obs.risk.title','درجات الخطورة'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">{{ t('obs.risk.title','درجات الخطورة') }}</h4>
    <span class="text-muted small">{{ t('obs.risk.fixed','الدرجات الخمس ثابتة: تعديل الأسماء والألوان والمهل فقط.') }}</span>
</div>

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="card p-0">
    <table class="table align-middle mb-0">
        <thead><tr>
            <th>#</th>
            <th>{{ t('obs.risk.slug','الرمز') }}</th>
            <th>{{ t('obs.risk.name_ar','الاسم بالعربي') }}</th>
            <th>{{ t('obs.risk.name_en','الاسم بالإنجليزي') }}</th>
            <th>{{ t('obs.risk.color','اللون') }}</th>
            <th>{{ t('obs.risk.sla','المهلة (ساعات)') }}</th>
            <th class="text-center">{{ t('obs.risk.verify','تحقق ميداني') }}</th>
            <th class="text-center">{{ t('common.active','نشط') }}</th>
            <th>{{ t('obs.risk.priority','أولوية التيكت') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse($levels as $l)
            <tr>
                <form method="POST" action="{{ route('obs.risk.update', $l) }}" id="rl-{{ $l->id }}">@csrf @method('PUT')</form>
                <td class="text-muted small">{{ $l->sort_order }}</td>
                <td><span class="badge" style="background:{{ $l->color }}">{{ $l->slug }}</span></td>
                <td><input form="rl-{{ $l->id }}" name="name_ar" value="{{ $l->name_ar }}" class="form-control form-control-sm" required></td>
                <td><input form="rl-{{ $l->id }}" name="name_en" value="{{ $l->name_en }}" class="form-control form-control-sm" required></td>
                <td><input form="rl-{{ $l->id }}" name="color" type="color" value="{{ $l->color }}" class="form-control form-control-color form-control-sm"></td>
                <td style="max-width:120px"><input form="rl-{{ $l->id }}" name="default_sla_hours" type="number" min="1" max="8760" value="{{ $l->default_sla_hours }}" class="form-control form-control-sm" placeholder="{{ t('obs.risk.no_sla','بدون مهلة') }}"></td>
                <td class="text-center"><input form="rl-{{ $l->id }}" type="checkbox" name="requires_field_verification" value="1" class="form-check-input" @checked($l->requires_field_verification)></td>
                <td class="text-center"><input form="rl-{{ $l->id }}" type="checkbox" name="active" value="1" class="form-check-input" @checked($l->active)></td>
                <td class="small text-muted">{{ $l->ticket_priority ?? '—' }}</td>
                <td class="text-end"><button form="rl-{{ $l->id }}" class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> {{ t('common.save','حفظ') }}</button></td>
            </tr>
        @empty
            <tr><td colspan="10" class="text-center text-muted p-4">{{ t('obs.risk.empty','لم تُزرع درجات الخطورة بعد. شغّل ObservationSetupSeeder.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="form-text mt-2">{{ t('obs.risk.hint','المهلة الافتراضية تُستخدم لما يكون البند والفئة بدون مهلة خاصة. التحقق الميداني يعني أن التيكت المقفول لا يُنهي الملاحظة إلا بعد زيارة تحقق.') }}</div>
@endsection
