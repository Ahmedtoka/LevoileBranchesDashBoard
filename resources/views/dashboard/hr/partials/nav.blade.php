{{-- HR menu (2026-11-03): included from layouts/app.blade.php, for management and the HR staff. --}}
@if(\App\Support\HrReport::canView(auth()->user()))
    <div class="nav-label">{{ dt('الموارد البشرية', 'HR') }}</div>
    <a href="{{ route('hr.summary') }}" class="{{ request()->routeIs('hr.summary', 'hr.show') ? 'active' : '' }}"><i class="bi bi-people me-2"></i>{{ dt('دليل الموظفين', 'Staff directory') }}</a>
    @if(\App\Support\HrReport::canManage(auth()->user()))
        <a href="{{ route('hr.import') }}" class="{{ request()->routeIs('hr.import', 'hr.preview') ? 'active' : '' }}"><i class="bi bi-cloud-arrow-up me-2"></i>{{ dt('تحديث ملف الموظفين', 'Update the staff file') }}</a>
    @endif
@endif
