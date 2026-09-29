<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'E-LIKAS Offline Companion')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex flex-col h-screen overflow-hidden">
    @include('partials._api_target_banner')
    <div class="flex flex-1 min-h-0">
    @php
        $initials = collect(explode(' ', trim($currentUser->name ?? '')))
            ->filter()
            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
            ->take(2)
            ->implode('');
    @endphp

    @if($currentUser ?? null)
        {{-- Same sidebar as the web dashboard: solid logo navy, the logo
             mark on a white circle (its dark arms would vanish against the
             navy otherwise), and links grouped under plain labels. --}}
        <aside id="sidebar" class="w-60 shrink-0 h-full flex flex-col bg-navy px-3 py-4">
            <div class="flex items-center gap-3 px-2 mb-6">
                <div class="w-12 h-12 rounded-full bg-white flex items-center justify-center shrink-0">
                    <img src="{{ asset('images/elikas-logo-mark.png') }}" alt="" class="w-[78%] h-[78%] object-contain">
                </div>
                <div class="leading-tight min-w-0">
                    <p class="text-white font-semibold text-base tracking-tight">E-LIKAS</p>
                    <p class="text-xs text-[#C7D7F0]">Offline Companion</p>
                </div>
            </div>

            <nav class="flex-1 min-h-0 overflow-y-auto flex flex-col gap-4" aria-label="Main">
                <div>
                    <a href="{{ route('dashboard') }}" class="nav-link @yield('nav-dashboard')">
                        <i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard
                    </a>
                </div>
                {{-- Elevated to the primary entry point, immediately after
                     Dashboard (mirrors the web dashboard's structure). Real
                     standalone section now: barangay -> centers -> board
                     (see EvacuationCenterController::ecBoardBarangays()),
                     its own route/controller separate from the old
                     Evacuation Centers management pages below. --}}
                <div class="flex flex-col gap-0.5">
                    <p class="nav-group-label">Operations</p>
                    <a href="{{ route('ec-board.index') }}" class="nav-link @yield('nav-ec-board')">
                        <i class="ti ti-building-community" aria-hidden="true"></i> EC Board
                    </a>
                    <a href="{{ route('families.index') }}" class="nav-link @yield('nav-families')">
                        <i class="ti ti-users" aria-hidden="true"></i> Registered families
                    </a>
                </div>
            </nav>
        </aside>
    @endif

    <div class="flex-1 flex flex-col min-w-0">
        <header class="h-16 bg-white border-b border-gray-200 px-6 flex items-center justify-between gap-4 shrink-0">
            <div class="flex items-center gap-4 text-sm text-gray-600">
                <span id="live-clock" class="flex items-center gap-1.5"><i class="ti ti-calendar" style="font-size: 15px;" aria-hidden="true"></i></span>
                <span class="flex items-center gap-1.5"><i class="ti ti-map-pin" style="font-size: 15px;" aria-hidden="true"></i> Ligao City, Albay</span>
            </div>

            <div class="flex items-center gap-4">
                <span id="connection-badge" class="badge badge-neutral"></span>
                @if($currentUser ?? null)
                    <div class="relative">
                        <button type="button" id="user-menu-btn" class="flex items-center gap-2.5 rounded-lg px-1.5 py-1 hover:bg-gray-100" aria-haspopup="menu">
                            <div class="w-9 h-9 rounded-full bg-brand-700 flex items-center justify-center text-white text-xs font-semibold shrink-0">{{ $initials }}</div>
                            <div class="text-left leading-tight">
                                <p class="text-sm font-semibold text-gray-900">{{ $currentUser->name }}</p>
                                <p class="text-xs text-gray-600">{{ \Illuminate\Support\Str::headline($currentUser->role) }}</p>
                            </div>
                            <i class="ti ti-chevron-down text-gray-500" style="font-size: 15px;" aria-hidden="true"></i>
                        </button>
                        <div id="user-menu" class="hidden absolute right-0 top-full mt-2 w-44 card shadow-lg overflow-hidden z-50">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="w-full text-left flex items-center gap-2 px-3 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                                    <i class="ti ti-logout" style="font-size: 15px;" aria-hidden="true"></i> Log out
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </header>

        <main class="page-transition flex-1 min-h-0 overflow-y-auto p-6 w-full max-w-6xl mx-auto">
            @if (session('authExpired'))
                {{-- Specifically for a 401 during sync (a dead/revoked token) -- kept visually and
                     textually distinct from the green status banner below, which still covers
                     ordinary per-record validation failures (a malformed field, a deleted reference)
                     that have nothing to do with the session and need their own specific fix instead
                     of a re-login. --}}
                <div class="callout callout-warning flex items-center justify-between gap-4 mb-4" role="alert">
                    <span class="flex items-center gap-2">
                        <i class="ti ti-lock-exclamation shrink-0" style="font-size: 16px;" aria-hidden="true"></i>
                        {{ session('authExpired') }}
                    </span>
                    <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-attention">
                            Log in again
                        </button>
                    </form>
                </div>
            @endif
            @if (session('referenceDataWarning'))
                {{-- Surfaces a refresh that happened at login but failed
                     partway through (see AuthController::login()'s own
                     comment) -- kept visible on the FIRST page load after
                     login only (a normal session() flash, not a banner
                     that persists across navigation), since by design
                     this is a one-time "here's what just happened",
                     not an ongoing state indicator. --}}
                <div class="callout callout-warning flex items-center gap-2 mb-4" role="alert">
                    <i class="ti ti-alert-triangle shrink-0" style="font-size: 16px;" aria-hidden="true"></i>
                    {{ session('referenceDataWarning') }}
                </div>
            @endif
            @if (session('status'))
                <div class="callout callout-success mb-4" role="status">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="callout callout-danger mb-4" role="alert">{{ $errors->first() }}</div>
            @endif

            @yield('content')
        </main>
    </div>
    </div>

    @yield('scripts')
</body>
</html>
