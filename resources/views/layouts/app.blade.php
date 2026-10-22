<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name') === 'Laravel' ? 'SecureTeam Vault' : config('app.name', 'SecureTeam Vault'))</title>

    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
    @stack('head')
</head>
@php
    $currentUser = auth()->user();
    $rawRole = $currentUser?->role ?? 'staff';
    $roleValue = $rawRole instanceof \BackedEnum ? $rawRole->value : (string) $rawRole;
    $isAdmin = $currentUser
        ? (method_exists($currentUser, 'isAdmin') ? $currentUser->isAdmin() : $roleValue === 'admin')
        : false;
    $isManager = $currentUser
        ? (method_exists($currentUser, 'isManager') ? $currentUser->isManager() : $roleValue === 'manager')
        : false;
    $roleLabel = \App\Enums\UserRole::tryFrom($roleValue)?->label() ?? 'Unknown role';

    $dashboardRoute = Illuminate\Support\Facades\Route::has('dashboard')
        ? 'dashboard'
        : (Illuminate\Support\Facades\Route::has('home') ? 'home' : null);

    $navItems = [];

    if ($dashboardRoute) {
        $navItems[] = [
            'label' => 'Dashboard',
            'route' => $dashboardRoute,
            'active' => ['dashboard', 'home'],
        ];
    }

    if (Illuminate\Support\Facades\Route::has('credentials.index')) {
        $navItems[] = [
            'label' => 'Credentials',
            'route' => 'credentials.index',
            'active' => ['credentials.index', 'credentials.show', 'credentials.edit'],
        ];
    }

    if (($isAdmin || $isManager) && Illuminate\Support\Facades\Route::has('credentials.create')) {
        $navItems[] = [
            'label' => 'Add credential',
            'route' => 'credentials.create',
            'active' => ['credentials.create'],
        ];
    }

    if ($isAdmin && Illuminate\Support\Facades\Route::has('admin.users.index')) {
        $navItems[] = [
            'label' => 'User management',
            'route' => 'admin.users.index',
            'active' => ['admin.users.*'],
        ];
    }

    if ($isAdmin && Illuminate\Support\Facades\Route::has('admin.audit.index')) {
        $navItems[] = [
            'label' => 'Audit logs',
            'route' => 'admin.audit.index',
            'active' => ['admin.audit.*'],
        ];
    }
@endphp
<body class="{{ auth()->check() ? 'stv-app-body' : 'stv-guest-body' }}">
    <div id="app">
        @auth
            <div class="stv-app-shell">
                <aside class="stv-sidebar d-none d-lg-flex" aria-label="Primary navigation">
                    <a class="stv-brand-link" href="{{ $dashboardRoute ? route($dashboardRoute) : url('/') }}">
                        <span class="stv-company-mark">
                            <img src="{{ asset('images/sa-famous-cooling.jpg') }}" alt="SA Famous Cooling">
                        </span>
                        <span>
                            <strong>SecureTeam</strong>
                            <small>Vault</small>
                        </span>
                    </a>

                    <p class="stv-nav-eyebrow">Workspace</p>
                    <nav class="nav flex-column gap-1">
                        @foreach ($navItems as $item)
                            @php($isActive = request()->routeIs(...$item['active']))
                            <a
                                class="stv-nav-link {{ $isActive ? 'active' : '' }}"
                                href="{{ route($item['route']) }}"
                                @if ($isActive) aria-current="page" @endif
                            >
                                <span class="stv-nav-indicator" aria-hidden="true"></span>
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </nav>

                    <div class="stv-sidebar-account">
                        <div class="d-flex align-items-center gap-3">
                            <span class="stv-avatar" aria-hidden="true">{{ strtoupper(substr($currentUser->name, 0, 1)) }}</span>
                            <div class="min-w-0">
                                <strong class="d-block text-truncate">{{ $currentUser->name }}</strong>
                                <small>{{ $roleLabel }}</small>
                            </div>
                        </div>
                        @if (Illuminate\Support\Facades\Route::has('logout'))
                            <form action="{{ route('logout') }}" method="POST" class="mt-3">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-light w-100">Sign out</button>
                            </form>
                        @endif
                    </div>
                </aside>

                <div class="stv-app-main">
                    <nav class="navbar stv-mobile-header d-lg-none" aria-label="Mobile navigation">
                        <div class="container-fluid">
                            <button
                                class="navbar-toggler"
                                type="button"
                                data-bs-toggle="offcanvas"
                                data-bs-target="#stvMobileMenu"
                                aria-controls="stvMobileMenu"
                                aria-label="Open navigation"
                            >
                                <span class="navbar-toggler-icon"></span>
                            </button>
                            <a class="stv-mobile-brand" href="{{ $dashboardRoute ? route($dashboardRoute) : url('/') }}">
                                <span class="stv-company-mark stv-company-mark-mobile">
                                    <img src="{{ asset('images/sa-famous-cooling.jpg') }}" alt="SA Famous Cooling">
                                </span>
                                <span>SecureTeam<br>Vault</span>
                            </a>
                            <span class="stv-avatar stv-avatar-sm" aria-hidden="true">{{ strtoupper(substr($currentUser->name, 0, 1)) }}</span>
                        </div>
                    </nav>

                    <div class="offcanvas offcanvas-start stv-mobile-menu" tabindex="-1" id="stvMobileMenu" aria-labelledby="stvMobileMenuLabel">
                        <div class="offcanvas-header">
                            <h2 class="offcanvas-title h5 mb-0" id="stvMobileMenuLabel">SecureTeam Vault</h2>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close navigation"></button>
                        </div>
                        <div class="offcanvas-body d-flex flex-column">
                            <nav class="nav flex-column gap-1" aria-label="Mobile primary navigation">
                                @foreach ($navItems as $item)
                                    @php($isActive = request()->routeIs(...$item['active']))
                                    <a
                                        class="stv-nav-link {{ $isActive ? 'active' : '' }}"
                                        href="{{ route($item['route']) }}"
                                        @if ($isActive) aria-current="page" @endif
                                    >
                                        <span class="stv-nav-indicator" aria-hidden="true"></span>
                                        {{ $item['label'] }}
                                    </a>
                                @endforeach
                            </nav>

                            <div class="stv-sidebar-account mt-auto">
                                <strong class="d-block">{{ $currentUser->name }}</strong>
                                <small>{{ $roleLabel }}</small>
                                @if (Illuminate\Support\Facades\Route::has('logout'))
                                    <form action="{{ route('logout') }}" method="POST" class="mt-3">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-light w-100">Sign out</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>

                    <header class="stv-topbar d-none d-lg-flex">
                        <div>
                            <p class="stv-topbar-eyebrow mb-1">Secure workspace</p>
                            <h1 class="h4 mb-0">@yield('page-title', 'Dashboard')</h1>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <span class="stv-security-badge">
                                <span aria-hidden="true"></span>
                                Protected session
                            </span>
                            <div class="text-end">
                                <strong class="d-block">{{ $currentUser->name }}</strong>
                                <small class="text-secondary">{{ $roleLabel }}</small>
                            </div>
                        </div>
                    </header>

                    <main class="stv-content">
                        @if (session('success'))
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                {{ session('success') }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        @endif

                        @if (session('status'))
                            <div class="alert alert-info alert-dismissible fade show" role="status">
                                {{ session('status') }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        @endif

                        @if (session('error'))
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                {{ session('error') }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        @endif

                        @if ($errors->any())
                            <div class="alert alert-danger" role="alert">
                                <strong>Please correct the highlighted information.</strong>
                            </div>
                        @endif

                        @yield('content')
                    </main>
                </div>
            </div>
        @else
            <nav class="navbar navbar-expand stv-guest-nav" aria-label="Guest navigation">
                <div class="container">
                    <a class="stv-guest-brand" href="{{ url('/') }}">
                        <span class="stv-company-mark">
                            <img src="{{ asset('images/sa-famous-cooling.jpg') }}" alt="SA Famous Cooling">
                        </span>
                        <span>SecureTeam Vault</span>
                    </a>
                    @if (Illuminate\Support\Facades\Route::has('login') && ! request()->routeIs('login'))
                        <a class="btn btn-primary btn-sm px-3" href="{{ route('login') }}">Sign in</a>
                    @endif
                </div>
            </nav>

            <main>
                @yield('content')
            </main>
        @endauth
    </div>

    <div
        id="stv-live-region"
        class="stv-live-region"
        role="status"
        aria-live="polite"
        aria-atomic="true"
    ></div>

    @stack('scripts')
</body>
</html>
