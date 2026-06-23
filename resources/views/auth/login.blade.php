@extends('layouts.app')
@section('title', 'Login')

@section('content')
<div class="d-flex justify-content-center align-items-center" style="min-height: 80vh;">
    <div class="card p-4" style="width: 380px;">
        <div class="text-center mb-3">
            <h4 class="fw-bold mb-0"><i class="bi bi-shop"></i> LeVoile Branches</h4>
            <p class="text-muted small">Branch Audit & Ticketing Dashboard</p>
        </div>

        @if($errors->any())
            <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label small">Email</label>
                <input type="email" name="email" value="{{ old('email', 'admin@levoile.test') }}" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small">Password</label>
                <input type="password" name="password" value="password" class="form-control" required>
            </div>
            <div class="form-check mb-3">
                <input type="checkbox" name="remember" class="form-check-input" id="remember">
                <label class="form-check-label small" for="remember">Remember me</label>
            </div>
            <button class="btn btn-primary w-100">Sign in</button>
        </form>
        <p class="text-muted small mt-3 mb-0">Demo: <code>admin@levoile.test</code> / <code>password</code></p>
    </div>
</div>
@endsection
