{{-- "مهام إدارتي" (2026-11-03): for department members and ops / super admin, with what waits on them. --}}
@if(auth()->user()->department_id || auth()->user()->hasRole(['super_admin', 'ops_manager']))
    @php $taskCount = \App\Http\Controllers\Web\TasksController::badge(auth()->user()); @endphp
    <a href="{{ route('tasks.index') }}" class="{{ request()->routeIs('tasks.*') ? 'active' : '' }} d-flex align-items-center">
        <i class="bi bi-inbox me-2"></i>{{ dt('مهام إدارتي', 'My department\'s tasks') }}
        @if($taskCount)<span class="badge rounded-pill text-bg-light ms-auto">{{ $taskCount }}</span>@endif
    </a>
@endif
