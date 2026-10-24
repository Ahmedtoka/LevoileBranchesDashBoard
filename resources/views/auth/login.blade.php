@extends('layouts.app')
@section('title', 'Login')

@section('content')
<div class="d-flex flex-column justify-content-center align-items-center py-4" style="min-height: 80vh;">
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
        @if(config('app.demo_login'))
            <p class="text-muted small mt-3 mb-0">Demo: <code>admin@levoile.test</code> / <code>password</code></p>
        @endif
    </div>

    @if(config('app.demo_login'))
        <div class="card p-4 mt-4 w-100" style="max-width: 760px;">
            <h5 class="fw-bold mb-3"><i class="bi bi-lightning-charge"></i> {{ dt('دخول سريع للتجربة', 'Quick demo login') }}</h5>
            <div class="row g-2">
                @foreach(\Database\Seeders\DemoUsersSeeder::users() as $demo)
                    <div class="col-12 col-md-6">
                        <form method="POST" action="{{ route('login.quick') }}">
                            @csrf
                            <input type="hidden" name="email" value="{{ $demo['email'] }}">
                            <button type="submit" class="btn btn-outline-primary w-100 h-100 text-start py-2">
                                <span class="d-flex justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold">{{ $demo['name'] }}</span>
                                    <span class="text-muted small">{{ $demo['role'] }}</span>
                                </span>
                                <span class="d-block text-muted small mt-1">{{ $demo['hint'] }}</span>
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
