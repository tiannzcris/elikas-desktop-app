<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'E-LIKAS Offline Companion')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: { DEFAULT: '#1E3D73', dark: '#0F2447', light: '#5B8FD6', red: '#C8102E', green: '#178A43' } } } } };
    </script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; }

        .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; color: #A8C2E8; }
        .nav-link i { font-size: 18px; }
        .nav-link:hover { background: rgba(255,255,255,0.08); color: white; }
        .nav-link.active { background: #3B82F6; color: white; }

        main.page-transition { opacity: 0; transition: opacity 200ms ease; }
        main.page-transition.in { opacity: 1; }

        .modal-pop { animation: modal-pop-in 220ms ease; }
        @keyframes modal-pop-in {
            from { opacity: 0; transform: scale(0.96) translateY(8px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
    </style>
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
                <a href="{{ route('families.create') }}" class="nav-link @yield('nav-register')">
                    <i class="ti ti-user-plus" aria-hidden="true"></i> Register a family
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
                                <p class="text-xs text-gray-400">{{ $currentUser->role }}</p>
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

    <script>
        function updateConnectionBadge() {
            const badge = document.getElementById('connection-badge');
            if (navigator.onLine) {
                badge.textContent = 'Online';
                badge.className = 'text-xs px-2.5 py-1 rounded-lg font-medium bg-green-500 text-white';
            } else {
                badge.textContent = 'Offline';
                badge.className = 'text-xs px-2.5 py-1 rounded-lg font-medium bg-gray-500 text-white';
            }
        }
        updateConnectionBadge();
        window.addEventListener('online', updateConnectionBadge);
        window.addEventListener('offline', updateConnectionBadge);

        function updateClock() {
            const el = document.getElementById('live-clock');
            if (!el) return;
            const formatted = new Date().toLocaleString('en-US', {
                month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
            });
            el.innerHTML = '<i class="ti ti-calendar" style="font-size: 15px;" aria-hidden="true"></i> ' + formatted;
        }
        updateClock();
        setInterval(updateClock, 30000);

        const userMenuBtn = document.getElementById('user-menu-btn');
        const userMenu = document.getElementById('user-menu');
        if (userMenuBtn && userMenu) {
            userMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                userMenu.classList.toggle('hidden');
            });
            document.addEventListener('click', () => userMenu.classList.add('hidden'));
        }

        // Page transitions -- fades the content area out just before leaving
        // for another page in this app, and back in once the new page loads,
        // so navigation doesn't feel like an abrupt full-page reload.
        document.addEventListener('DOMContentLoaded', () => {
            requestAnimationFrame(() => document.querySelector('main')?.classList.add('in'));
        });
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
            const url = new URL(link.href, window.location.href);
            if (url.origin !== window.location.origin || link.target === '_blank' || link.hasAttribute('download')) return;
            e.preventDefault();
            document.querySelector('main')?.classList.remove('in');
            setTimeout(() => { window.location.href = link.href; }, 150);
        });
        document.addEventListener('submit', () => {
            document.querySelector('main')?.classList.remove('in');
        });
    </script>

    @yield('scripts')
</body>
</html>
