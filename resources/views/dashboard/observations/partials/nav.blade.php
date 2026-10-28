{{--
    Observations module: operational sidebar links (Plan D). Included once from layouts/app.blade.php, above admin-nav.
    Spec §10: shown to every role except a department_employee of a non-reporting department; the responsible
    department's staff see nothing (ObservationPolicy::viewAny). Admin/settings links live in admin-nav (Plan C).
--}}
@php
    $obsNavUser = auth()->user();
    $obsNavShow = $obsNavUser->can('viewAny', \App\Models\Observation::class);
    $obsNavCreate = $obsNavShow && $obsNavUser->can('create', \App\Models\ObservationVisit::class);
    $obsNavDashboard = $obsNavShow && ! $obsNavUser->hasRole(array_merge(\App\Support\ObservationScope::BRANCH_ROLES, ['department_employee']));
@endphp
@if($obsNavShow)
    <div class="nav-label" data-obs-nav>{{ t('obs.nav.section','الملاحظات') }}</div>
    <a href="{{ route('observations.index') }}" class="{{ request()->routeIs('observations.index', 'observations.show') ? 'active' : '' }}"><i class="bi bi-clipboard2-pulse me-2"></i>{{ t('obs.nav.register','سجل الملاحظات') }}</a>
    @if($obsNavCreate)
        <a href="{{ route('observations.create') }}" class="{{ request()->routeIs('observations.create') ? 'active' : '' }}"><i class="bi bi-plus-circle me-2"></i>{{ t('obs.nav.new_visit','زيارة ملاحظات جديدة') }}</a>
    @endif
    <a href="{{ route('observations.beforeAfter') }}" class="{{ request()->routeIs('observations.beforeAfter') ? 'active' : '' }}"><i class="bi bi-arrow-left-right me-2"></i>{{ t('obs.nav.before_after','كنا وبقينا') }}</a>
    @if($obsNavDashboard)
        <a href="{{ route('observations.dashboard') }}" class="{{ request()->routeIs('observations.dashboard') ? 'active' : '' }}"><i class="bi bi-speedometer me-2"></i>{{ t('obs.nav.dashboard','داشبورد الإدارات') }}</a>
        <a href="{{ route('observations.weekly') }}" class="{{ request()->routeIs('observations.weekly') ? 'active' : '' }}"><i class="bi bi-calendar-week me-2"></i>{{ t('obs.nav.weekly','تقرير الأسبوع') }}</a>
        <a href="{{ route('observations.employees') }}" class="{{ request()->routeIs('observations.employees') ? 'active' : '' }}"><i class="bi bi-person-badge me-2"></i>{{ t('obs.nav.employees','تقرير الموظفين') }}</a>
    @endif
@endif
