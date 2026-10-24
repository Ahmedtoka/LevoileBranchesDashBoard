{{--
    Observations module: admin sidebar links (Plan C). Included once from layouts/app.blade.php.
    Shown to super_admin and to managers of a reporting department (ObservationCatalogPolicy).
    The operational links (/observations, dashboard, before-after) belong to Plan D's partial.
--}}
@php
    $obsUser = auth()->user();
    $obsAdmin = $obsUser->hasRole('super_admin');
    $obsDeptManager = ! $obsAdmin && \App\Support\ObservationScope::managedReportingDepartmentId($obsUser) !== null;
@endphp
@if($obsAdmin || $obsDeptManager)
    <div class="nav-label">{{ t('obs.nav.settings','إعدادات الملاحظات') }}</div>
    <a href="{{ route('obs.review.index') }}" class="{{ request()->routeIs('obs.review.*') ? 'active' : '' }}"><i class="bi bi-patch-check me-2"></i>{{ t('obs.nav.review','اعتماد التصنيفات') }}</a>
    <a href="{{ route('obs.categories.index') }}" class="{{ request()->routeIs('obs.categories.*') ? 'active' : '' }}"><i class="bi bi-tags me-2"></i>{{ t('obs.nav.categories','فئات الملاحظات') }}</a>
    <a href="{{ route('obs.catalog.index') }}" class="{{ request()->routeIs('obs.catalog.*') ? 'active' : '' }}"><i class="bi bi-journal-text me-2"></i>{{ t('obs.nav.catalog','كتالوج الملاحظات') }}</a>
    @if($obsAdmin)
        <a href="{{ route('obs.areas.index') }}" class="{{ request()->routeIs('obs.areas.*') ? 'active' : '' }}"><i class="bi bi-map me-2"></i>{{ t('obs.nav.areas','المناطق') }}</a>
        <a href="{{ route('obs.risk.index') }}" class="{{ request()->routeIs('obs.risk.*') ? 'active' : '' }}"><i class="bi bi-exclamation-diamond me-2"></i>{{ t('obs.nav.risk','درجات الخطورة') }}</a>
    @endif
@endif
