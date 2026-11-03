{{-- Finance department menu (2026-11-03): included from layouts/app.blade.php, for management and the finance staff. --}}
@if(\App\Support\FinanceReport::canView(auth()->user()))
    <div class="nav-label">{{ dt('المالية', 'Finance') }}</div>
    <a href="{{ route('finance.summary') }}" class="{{ request()->routeIs('finance.summary') ? 'active' : '' }}"><i class="bi bi-cash-coin me-2"></i>{{ dt('ملخص المالية', 'Finance summary') }}</a>
    @if(\App\Support\FinanceReport::canRecord(auth()->user()))
        <a href="{{ route('finance.create') }}" class="{{ request()->routeIs('finance.create') ? 'active' : '' }}"><i class="bi bi-clipboard-check me-2"></i>{{ dt('مراجعة الفروع', 'Branch reviews') }}</a>
    @endif
    <a href="{{ route('finance.index') }}" class="{{ request()->routeIs('finance.index') ? 'active' : '' }}"><i class="bi bi-list-ul me-2"></i>{{ dt('قائمة المشاكل المالية', 'Finance problems') }}</a>
@endif
