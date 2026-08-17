<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'E-LIKAS Offline Companion')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 text-gray-900 flex h-screen overflow-hidden">
    @php
        $initials = collect(explode(' ', trim($currentUser->name ?? '')))
            ->filter()
            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
            ->take(2)
            ->implode('');
    @endphp

    @if($currentUser ?? null)
        <aside class="w-60 shrink-0 h-screen flex flex-col px-3 py-4" style="background: linear-gradient(180deg, {{ '#0F2447' }} 0%, {{ '#152F5C' }} 100%);">
            <div class="flex items-center gap-2.5 px-2 mb-6">
                <img src="{{ asset('images/logo-icon.png') }}" alt="E-LIKAS" class="w-9 h-9 object-contain shrink-0">
                <div class="leading-tight">
                    <p class="text-white font-extrabold text-sm tracking-wide"><span style="color: #E63946;">E-</span>LIKAS</p>
                    <p class="text-xs font-medium" style="color: #A8C2E8;">Offline Companion</p>
                </div>
            </div>

            <nav class="flex flex-col gap-1">
                <a href="{{ route('dashboard') }}" class="nav-link @yield('nav-dashboard')">
                    <i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard
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
        <header class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-4 text-sm text-gray-500">
                <span id="live-clock" class="flex items-center gap-1.5"><i class="ti ti-calendar" style="font-size: 15px;" aria-hidden="true"></i></span>
                <span class="flex items-center gap-1.5"><i class="ti ti-map-pin" style="font-size: 15px;" aria-hidden="true"></i> Ligao City, Albay</span>
            </div>

            <div class="flex items-center gap-4">
                <span id="connection-badge" class="text-xs px-2.5 py-1 rounded-lg font-medium"></span>
                @if($currentUser ?? null)
                    <div class="relative">
                        <button type="button" id="user-menu-btn" class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0" style="background: #3B82F6;">{{ $initials }}</div>
                            <div class="text-left leading-tight">
                                <p class="text-sm font-bold text-gray-800">{{ $currentUser->name }}</p>
                                <span class="inline-block mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand bg-blue-50 rounded-full px-2 py-0.5">
                                    {{ \Illuminate\Support\Str::headline($currentUser->role) }}
                                </span>
                            </div>
                            <i class="ti ti-chevron-down text-gray-400" style="font-size: 15px;" aria-hidden="true"></i>
                        </button>
                        <div id="user-menu" class="hidden absolute right-0 top-full mt-2 w-40 bg-white border border-gray-200 rounded-lg shadow-lg overflow-hidden z-50">
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
            @if (session('status'))
                <div class="bg-green-50 text-green-700 text-sm rounded-lg p-3 mb-4">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="bg-red-50 text-red-700 text-sm rounded-lg p-3 mb-4">{{ $errors->first() }}</div>
            @endif

            @yield('content')
        </main>
    </div>

    @yield('scripts')
</body>
</html>
