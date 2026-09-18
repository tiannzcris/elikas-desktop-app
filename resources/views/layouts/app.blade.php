<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'E-LIKAS Offline Companion')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 text-gray-900 flex flex-col h-screen overflow-hidden">
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
        <aside class="w-64 shrink-0 h-full flex flex-col px-4 py-5" style="background: linear-gradient(180deg, {{ '#0F2447' }} 0%, {{ '#152F5C' }} 100%);">
            <div class="leading-tight px-2 mb-8">
                <p class="text-white font-extrabold text-base tracking-wide"><span style="color: #E63946;">E-</span>LIKAS</p>
                <p class="text-xs font-medium" style="color: #A8C2E8;">Offline Companion</p>
            </div>

            <nav class="flex flex-col gap-1.5">
                <a href="{{ route('dashboard') }}" class="nav-link @yield('nav-dashboard')">
                    <i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard
                </a>
                {{-- Elevated to the primary entry point, immediately after
                     Dashboard (mirrors the web dashboard's structure). Real
                     standalone section now: barangay -> centers -> board
                     (see EvacuationCenterController::ecBoardBarangays()),
                     its own route/controller separate from the old
                     Evacuation Centers management pages below. --}}
                <a href="{{ route('ec-board.index') }}" class="nav-link @yield('nav-ec-board')">
                    <i class="ti ti-building-community" aria-hidden="true"></i> EC Board
                </a>
                <a href="{{ route('families.index') }}" class="nav-link @yield('nav-families')">
                    <i class="ti ti-users" aria-hidden="true"></i> Registered families
                </a>
                <a href="{{ route('evacuees.index') }}" class="nav-link @yield('nav-evacuees')">
                    <i class="ti ti-clipboard-list" aria-hidden="true"></i> All Evacuees
                </a>
            </nav>
        </aside>
    @endif

    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-white px-6 py-3.5 flex items-center justify-between shrink-0" style="box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05), 0 1px 2px rgba(15, 23, 42, 0.04);">
            <div class="flex items-center gap-4 text-sm text-gray-500">
                <span id="live-clock" class="flex items-center gap-1.5"><i class="ti ti-calendar" style="font-size: 15px;" aria-hidden="true"></i></span>
                <span class="flex items-center gap-1.5"><i class="ti ti-map-pin" style="font-size: 15px;" aria-hidden="true"></i> Ligao City, Albay</span>
            </div>

            <div class="flex items-center gap-4">
                <span id="connection-badge" class="text-xs px-2.5 py-1 rounded-full font-semibold"></span>
                @if($currentUser ?? null)
                    <div class="relative">
                        <button type="button" id="user-menu-btn" class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0" style="background: linear-gradient(135deg, #3B82F6, #2563EB);">{{ $initials }}</div>
                            <div class="text-left leading-tight">
                                <p class="text-sm font-bold text-gray-800">{{ $currentUser->name }}</p>
                                <span class="inline-block mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand bg-blue-50 rounded-full px-2 py-0.5">
                                    {{ \Illuminate\Support\Str::headline($currentUser->role) }}
                                </span>
                            </div>
                            <i class="ti ti-chevron-down text-gray-400" style="font-size: 15px;" aria-hidden="true"></i>
                        </button>
                        <div id="user-menu" class="hidden absolute right-0 top-full mt-2 w-40 card-modern overflow-hidden z-50">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="w-full text-left flex items-center gap-2 px-3 py-2.5 text-sm text-gray-600 hover:bg-gray-50">
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
                <div class="flex items-center justify-between gap-4 bg-amber-50 text-amber-800 text-sm rounded-lg p-3 mb-4">
                    <span class="flex items-center gap-2">
                        <i class="ti ti-lock-exclamation shrink-0" style="font-size: 16px;" aria-hidden="true"></i>
                        {{ session('authExpired') }}
                    </span>
                    <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="btn-modern bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold px-3 py-2">
                            Log in again
                        </button>
                    </form>
                </div>
            @endif
            @if (session('status'))
                <div class="bg-green-50 text-green-700 text-sm rounded-lg p-3 mb-4">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-50 text-red-700 text-sm rounded-lg p-3 mb-4">{{ $errors->first() }}</div>
            @endif

            @yield('content')
        </main>
    </div>
    </div>

    @yield('scripts')
</body>
</html>
