@extends('layouts.app')
@section('title', t('nav.roles','الأدوار'))

@section('content')
<h4 class="fw-bold mb-3">{{ t('nav.roles','الأدوار') }}</h4>
<p class="text-muted">{{ t('roles.intro','نظرة على أدوار النظام وصلاحية كل دور. عدد المستخدمين قابل للضغط لعرضهم.') }}</p>

<div class="row g-3">
    @foreach($roles as $r)
        <div class="col-md-6 col-lg-4">
            <div class="card p-3 h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h6 class="fw-bold mb-0"><i class="bi bi-person-badge text-primary me-1"></i>{{ $r->name }}</h6>
                    <a href="{{ route('users.index', ['role' => $r->slug]) }}" class="badge text-bg-light text-decoration-none">{{ $r->users_count }} {{ t('roles.user','مستخدم') }}</a>
                </div>
                <code class="small text-muted d-block mb-2">{{ $r->slug }}</code>
                <p class="small text-muted mb-0">{{ $r->description }}</p>
            </div>
        </div>
    @endforeach
</div>
@endsection
