<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · LeVoile Branches</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --lv: #4a0f33; --lv-accent: #9c1e6e; --bs-primary: #9c1e6e; }
        body { background: #f7f4f6; }
        /* Brand (Le Voile magenta) overrides for Bootstrap primary */
        .btn-primary { --bs-btn-bg:#9c1e6e; --bs-btn-border-color:#9c1e6e; --bs-btn-hover-bg:#7a1656; --bs-btn-hover-border-color:#7a1656; --bs-btn-active-bg:#7a1656; }
        .btn-outline-primary { --bs-btn-color:#9c1e6e; --bs-btn-border-color:#9c1e6e; --bs-btn-hover-bg:#9c1e6e; --bs-btn-hover-border-color:#9c1e6e; --bs-btn-active-bg:#9c1e6e; }
        .text-primary { color:#9c1e6e !important; }
        .bg-primary, .text-bg-primary { background-color:#9c1e6e !important; }
        a { color: #9c1e6e; }
        .sidebar { width: 250px; min-height: 100vh; background: var(--lv); color: #e7d6e2; position: fixed; }
        .sidebar .brand { color: #fff; font-weight: 700; letter-spacing: .5px; }
        .sidebar a { color: #cbd5e1; text-decoration: none; display: block; padding: .55rem .9rem; border-radius: .4rem; font-size: .9rem; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,.08); color: #fff; }
        .sidebar .nav-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: #64748b; padding: .9rem .9rem .3rem; }
        .content { margin-left: 250px; }
        .stat-card { border: none; border-radius: .8rem; }
        .stat-card .value { font-size: 1.7rem; font-weight: 700; }
        .badge-status { text-transform: capitalize; }
        .table thead th { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; color: #64748b; }
        .card { border: none; border-radius: .7rem; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
    </style>
    @stack('head')
</head>
<body>
@auth
<nav class="sidebar p-3">
    <div class="brand fs-5 mb-3"><i class="bi bi-shop"></i> LeVoile</div>
    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}"><i class="bi bi-grid me-2"></i>Overview</a>
    <a href="{{ route('branches.index') }}" class="{{ request()->routeIs('branches.*') ? 'active' : '' }}"><i class="bi bi-geo-alt me-2"></i>Branches</a>
    <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}"><i class="bi bi-people me-2"></i>Users</a>
    <a href="{{ route('templates.index') }}" class="{{ request()->routeIs('templates.*') || request()->routeIs('types.*') ? 'active' : '' }}"><i class="bi bi-ui-checks me-2"></i>Checklist Builder</a>
    <a href="{{ route('visits.schedule') }}" class="{{ request()->routeIs('visits.schedule') ? 'active' : '' }}"><i class="bi bi-calendar-plus me-2"></i>Schedule Visit</a>
    <a href="{{ route('visits.index') }}" class="{{ request()->routeIs('visits.index') || request()->routeIs('visits.show') ? 'active' : '' }}"><i class="bi bi-clipboard-check me-2"></i>Visits</a>
    <a href="{{ route('tickets.index') }}" class="{{ request()->routeIs('tickets.index') ? 'active' : '' }}"><i class="bi bi-ticket-detailed me-2"></i>Tickets</a>
    <a href="{{ route('departments.index') }}" class="{{ request()->routeIs('departments.*') ? 'active' : '' }}"><i class="bi bi-diagram-3 me-2"></i>Departments</a>
    <a href="{{ route('maintenance.index') }}" class="{{ request()->routeIs('maintenance.*') ? 'active' : '' }}"><i class="bi bi-tools me-2"></i>Maintenance Center</a>

    <div class="nav-label">Insights</div>
    <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}"><i class="bi bi-bar-chart me-2"></i>Reports</a>

    <hr class="text-secondary">
    <div class="small text-secondary px-2">
        {{ auth()->user()->name }}<br>
        <span class="text-muted">{{ optional(auth()->user()->role)->name }}</span>
    </div>
    <form method="POST" action="{{ route('logout') }}" class="px-2 mt-2">
        @csrf
        <button class="btn btn-sm btn-outline-light w-100"><i class="bi bi-box-arrow-right me-1"></i>Logout</button>
    </form>
</nav>
@endauth

<main class="@auth content @endauth p-4">
    @auth
        @php
            $unread = auth()->user()->appNotifications()->whereNull('read_at')->count();
            $notes = auth()->user()->appNotifications()->limit(12)->get();
        @endphp
        <div class="d-flex justify-content-end mb-3">
            <div class="dropdown">
                <button class="btn btn-light position-relative" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-bell"></i>
                    @if($unread > 0)
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger">{{ $unread }}</span>
                    @endif
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow p-0" style="width:340px">
                    <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                        <strong class="small">Notifications</strong>
                        @if($unread > 0)
                            <form method="POST" action="{{ route('notifications.readAll') }}">@csrf
                                <button class="btn btn-sm btn-link p-0 text-decoration-none">Mark all read</button>
                            </form>
                        @endif
                    </div>
                    <div style="max-height:360px;overflow:auto">
                        @forelse($notes as $n)
                            <a href="{{ route('notifications.read', $n) }}" class="d-block text-decoration-none text-dark px-3 py-2 border-bottom {{ $n->read_at ? '' : 'bg-light' }}">
                                <div class="small fw-semibold">{{ $n->title }}</div>
                                @if($n->body)<div class="small text-muted text-truncate">{{ $n->body }}</div>@endif
                                <div class="text-muted" style="font-size:.7rem">{{ $n->created_at->diffForHumans() }}</div>
                            </a>
                        @empty
                            <div class="p-3 text-muted small text-center">No notifications.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @endauth

    @if(session('status'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @yield('content')
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Enhance any <table class="js-table"> with search + sortable headers + numeric footer totals.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table.js-table').forEach(initTable);

    function initTable(table) {
        const tbody = table.tBodies[0];
        if (!tbody) return;
        const rows = () => Array.from(tbody.rows);

        // --- search box (if a sibling input[data-table-search] targets this table) ---
        const wrapId = table.getAttribute('data-search');
        if (wrapId) {
            const input = document.getElementById(wrapId);
            if (input) input.addEventListener('input', () => {
                const q = input.value.toLowerCase();
                rows().forEach(r => { r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none'; });
                recompute();
            });
        }

        // --- sortable headers ---
        const ths = table.tHead ? Array.from(table.tHead.rows[0].cells) : [];
        ths.forEach((th, idx) => {
            if (th.hasAttribute('data-nosort')) return;
            th.style.cursor = 'pointer';
            th.title = 'Click to sort';
            let dir = 1;
            th.addEventListener('click', () => {
                dir = -dir;
                const sorted = rows().sort((a, b) => {
                    const x = (a.cells[idx]?.innerText || '').trim();
                    const y = (b.cells[idx]?.innerText || '').trim();
                    const nx = parseFloat(x.replace(/[^0-9.\-]/g, '')), ny = parseFloat(y.replace(/[^0-9.\-]/g, ''));
                    if (!isNaN(nx) && !isNaN(ny) && x !== '' && y !== '') return (nx - ny) * dir;
                    return x.localeCompare(y) * dir;
                });
                sorted.forEach(r => tbody.appendChild(r));
            });
        });

        // --- numeric footer totals (columns marked th[data-sum]) ---
        function recompute() {
            if (!table.tFoot) return;
            const footCells = table.tFoot.rows[0].cells;
            ths.forEach((th, idx) => {
                if (!th.hasAttribute('data-sum')) return;
                let sum = 0;
                rows().forEach(r => {
                    if (r.style.display === 'none') return;
                    const v = parseFloat((r.cells[idx]?.innerText || '').replace(/[^0-9.\-]/g, ''));
                    if (!isNaN(v)) sum += v;
                });
                if (footCells[idx]) footCells[idx].innerText = sum;
            });
        }
        recompute();
    }
});
</script>
@stack('scripts')
</body>
</html>
