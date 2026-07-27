<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'E-LIKAS Offline Companion')</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: { DEFAULT: '#2F5496', dark: '#1F3A6E' } } } } };
    </script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; }
        .nav-link { display: flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; font-size: 13px; color: #A8C2E8; }
        .nav-link:hover { background: rgba(255,255,255,0.08); color: white; }
        .nav-link.active { background: rgba(255,255,255,0.15); color: white; font-weight: 500; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">
    <div class="flex items-center justify-between px-6 py-3" style="background: #1F3A6E;">
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-blue-500 flex items-center justify-center shrink-0">
                    <i class="ti ti-shield-check text-white" style="font-size: 16px;" aria-hidden="true"></i>
                </div>
                <div class="leading-tight">
                    <p class="text-white font-semibold text-sm">E-LIKAS</p>
                    <p class="text-xs" style="color: #A8C2E8;">Offline Companion</p>
                </div>
            </div>

            @if($currentUser ?? null)
                <nav class="flex items-center gap-1">
                    <a href="{{ route('dashboard') }}" class="nav-link @yield('nav-dashboard')">
                        <i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard
                    </a>
                    <a href="{{ route('families.create') }}" class="nav-link @yield('nav-register')">
                        <i class="ti ti-user-plus" aria-hidden="true"></i> Register a family
                    </a>
                    <a href="{{ route('families.index') }}" class="nav-link @yield('nav-families')">
                        <i class="ti ti-users" aria-hidden="true"></i> Registered families
                    </a>
                </nav>
            @endif
        </div>

        <div class="flex items-center gap-4 text-sm">
            <span id="connection-badge" class="text-xs px-2.5 py-1 rounded-lg font-medium"></span>
            @if($currentUser ?? null)
                <span class="text-white">{{ $currentUser->name }} <span style="color: #A8C2E8;">({{ $currentUser->role }})</span></span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-xs" style="color: #A8C2E8;">Log out</button>
                </form>
            @endif
        </div>
    </div>

    <main class="p-6 max-w-3xl mx-auto">
        @if (session('status'))
            <div class="bg-green-50 text-green-700 text-sm rounded-lg p-3 mb-4">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red-50 text-red-700 text-sm rounded-lg p-3 mb-4">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>

    <script>
        // Connection indicator now lives in the shared layout, not just the
        // dashboard -- shows on every page, since knowing whether you're
        // online matters just as much while registering a family as it
        // does on the dashboard.
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
    </script>

    @yield('scripts')
</body>
</html>
