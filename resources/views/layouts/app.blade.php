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
                {{-- Groups follow permissions: a group with no link for
                     this account is not shown (web design system, 5). --}}
                @if ($currentUser->isBarangayOfficial())
                    <div class="flex flex-col gap-0.5" data-nav-group="operations">
                        <p class="nav-group-label">Operations</p>
                        <a href="{{ route('ec-board.index') }}" class="nav-link @yield('nav-ec-board')">
                            <i class="ti ti-building-community" aria-hidden="true"></i> EC Board
                        </a>
                        <a href="{{ route('transfers.index') }}" class="nav-link @yield('nav-transfers')" data-nav-transfers>
                            <i class="ti ti-arrows-exchange" aria-hidden="true"></i> Transfers
                            @if ($transfersWaiting > 0)
                                <span class="ml-auto rounded-full bg-white/90 px-2 text-xs font-semibold text-navy">{{ $transfersWaiting }}</span>
                            @endif
                        </a>
                        <a href="{{ route('sync.index') }}" class="nav-link @yield('nav-sync')">
                            <i class="ti ti-cloud-upload" aria-hidden="true"></i> Sync
                            @if ($pendingTotal > 0)
                                <span class="ml-auto rounded-full bg-amber-400 px-2 text-xs font-semibold text-gray-900" data-nav-pending>{{ $pendingTotal }}</span>
                            @endif
                        </a>
                    </div>
                @endif
                @if ($currentUser->isCityWide())
                    <div class="flex flex-col gap-0.5" data-nav-group="city">
                        <p class="nav-group-label">Monitoring</p>
                        <a href="{{ route('monitoring.index') }}" class="nav-link @yield('nav-monitoring')">
                            <i class="ti ti-activity" aria-hidden="true"></i> Monitoring
                        </a>
                        <a href="{{ route('final-counts.index') }}" class="nav-link @yield('nav-final-counts')">
                            <i class="ti ti-clipboard-check" aria-hidden="true"></i> Final counts
                        </a>
                        <a href="{{ route('transfers.index') }}" class="nav-link @yield('nav-transfers')" data-nav-transfers>
                            <i class="ti ti-arrows-exchange" aria-hidden="true"></i> Transfers
                            @if ($transfersWaiting > 0)
                                <span class="ml-auto rounded-full bg-white/90 px-2 text-xs font-semibold text-navy">{{ $transfersWaiting }}</span>
                            @endif
                        </a>
                    </div>
                @endif
            </nav>
        </aside>
    @endif

    <div class="flex-1 flex flex-col min-w-0">
        <header class="h-16 bg-white border-b border-gray-200 px-6 flex items-center justify-between gap-4 shrink-0">
            <div class="flex items-center gap-4 text-sm text-gray-600">
                <span id="live-clock" class="flex items-center gap-1.5"><i class="ti ti-calendar" style="font-size: 15px;" aria-hidden="true"></i></span>
                @if (($currentUser ?? null)?->hasOperationsAccess())
                    @include('partials._event_switcher')
                @else
                    <span class="flex items-center gap-1.5"><i class="ti ti-map-pin" style="font-size: 15px;" aria-hidden="true"></i> Ligao City, Albay</span>
                @endif
            </div>

            <div class="flex items-center gap-4">
                @if (($currentUser ?? null)?->isBarangayOfficial())
                    {{-- One chip for the connection and the queue: "Offline · 3 waiting",
                         "Sending…", "All sent" (app.js renderSyncChip; auto sync). --}}
                    <a href="{{ route('sync.index') }}" id="sync-chip" role="status" aria-live="polite"
                        class="badge {{ $pendingTotal > 0 ? 'badge-warning' : 'badge-success' }} text-sm px-3 py-1" data-pending="{{ $pendingTotal }}">
                        {{ $pendingTotal > 0 ? \App\Support\Plural::of($pendingTotal, 'record', 'records').' waiting' : 'All sent' }}
                    </a>
                    <form hidden data-auto-sync data-step-url="{{ route('sync.step') }}" data-retry-url="{{ route('sync.retry') }}" data-probe-url="{{ route('sync.probe') }}">@csrf</form>
                @elseif ($currentUser ?? null)
                    <span id="connection-badge" class="badge badge-neutral text-sm px-3 py-1" role="status" aria-live="polite"></span>
                @endif
                @if (($currentUser ?? null)?->hasOperationsAccess())
                    {{-- In-app notifications (GET /notifications), loaded by app.js. --}}
                    <div class="relative" data-bell data-feed-url="{{ route('notifications.feed') }}" data-read-url="{{ route('notifications.read') }}">
                        <button type="button" class="btn-icon relative" data-bell-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Notifications">
                            <i class="ti ti-bell" style="font-size: 20px;" aria-hidden="true"></i>
                            <span class="absolute -top-0.5 -right-0.5 min-w-[1.25rem] rounded-full bg-red-600 px-1 text-xs font-semibold text-white" data-bell-count hidden></span>
                        </button>
                        <div class="menu-list w-96" role="menu" data-bell-list hidden>
                            <p class="px-3 py-2 text-sm text-gray-600" data-bell-empty>No notifications.</p>
                        </div>
                    </div>
                @endif
                @if($currentUser ?? null)
                    <div class="relative">
                        <button type="button" id="user-menu-btn" class="flex items-center gap-2.5 rounded-lg px-1.5 py-1 hover:bg-gray-100" aria-haspopup="menu">
                            <div class="w-9 h-9 rounded-full bg-brand-700 flex items-center justify-center text-white text-xs font-semibold shrink-0">{{ $initials }}</div>
                            <div class="text-left leading-tight">
                                <p class="text-sm font-semibold text-gray-900">{{ $currentUser->name }}</p>
                                <p class="text-xs text-gray-600">{{ $currentUser->roleLabel() }}</p>
                            </div>
                            <i class="ti ti-chevron-down text-gray-500" style="font-size: 15px;" aria-hidden="true"></i>
                        </button>
                        <div id="user-menu" class="hidden absolute right-0 top-full mt-2 w-52 card shadow-lg overflow-hidden z-50 py-1" role="menu">
                            <a href="{{ route('about') }}" class="menu-item" role="menuitem">
                                <i class="ti ti-info-circle" aria-hidden="true"></i> About E-LIKAS
                            </a>
                            <div class="menu-divider"></div>
                            <form method="POST" action="{{ route('logout') }}" @if($pendingTotal > 0) data-confirm-title="Log out with records not sent?" data-confirm="{{ \App\Support\Plural::of($pendingTotal, 'record stays', 'records stay') }} on this device for your next login." data-confirm-label="Log out" @endif>
                                @csrf
                                <button type="submit" class="menu-item" role="menuitem">
                                    <i class="ti ti-logout" aria-hidden="true"></i> Log out
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </header>
        @if ($currentUser ?? null)
            {{-- "A new version is available" (app.js, update notice). Hidden until a
                 check finds a newer version on the hosting. --}}
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-blue-200 bg-blue-50 px-6 py-2.5 text-sm text-blue-900"
                role="status" data-update-banner data-check-url="{{ route('app-version.check') }}" hidden>
                <span class="flex items-center gap-2">
                    <i class="ti ti-download" style="font-size: 16px;" aria-hidden="true"></i>
                    <span>A new version of E-LIKAS is available: <strong data-update-latest></strong> (this computer has {{ config('nativephp.version') }}). Your data on this computer is kept.</span>
                </span>
                <span class="flex items-center gap-2">
                    <a href="{{ route('app-version.download') }}" class="btn btn-secondary btn-sm" data-update-download>Download now</a>
                    <button type="button" class="btn btn-ghost btn-sm" data-update-later>Later</button>
                </span>
            </div>
        @endif

        <main class="page-transition flex-1 min-h-0 overflow-y-auto p-6 w-full max-w-[1200px] mx-auto">
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
                {{-- A refusal because of the event's state (closed, being monitored)
                     is a lock, not an error: the one lock design. --}}
                @if (\App\Support\EventLock::isLockMessage($errors->first()))
                    <x-lock-notice :message="$errors->first()" class="mb-4" />
                @else
                    <div class="callout callout-danger mb-4" role="alert">{{ $errors->first() }}</div>
                @endif
            @endif

            @yield('content')
        </main>
    </div>
    </div>

    @yield('scripts')
</body>
</html>
