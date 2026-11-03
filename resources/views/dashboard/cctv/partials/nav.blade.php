{{-- CCTV department menu (2026-11-03): included from layouts/app.blade.php, for management and the CCTV staff. --}}
@if(\App\Support\CctvReport::canView(auth()->user()))
    <div class="nav-label">CCTV</div>
    <a href="{{ route('cctv.summary') }}" class="{{ request()->routeIs('cctv.summary') ? 'active' : '' }}"><i class="bi bi-camera-video me-2"></i>{{ dt('ملخص المخالفات', 'Violations summary') }}</a>
    @if(\App\Support\CctvReport::canRecord(auth()->user()))
        <a href="{{ route('cctv.create') }}" class="{{ request()->routeIs('cctv.create') ? 'active' : '' }}"><i class="bi bi-plus-circle me-2"></i>{{ dt('تسجيل مخالفة', 'Record a violation') }}</a>
    @endif
    <a href="{{ route('cctv.index') }}" class="{{ request()->routeIs('cctv.index') ? 'active' : '' }}"><i class="bi bi-list-ul me-2"></i>{{ dt('قائمة المخالفات', 'Violations list') }}</a>
@endif
