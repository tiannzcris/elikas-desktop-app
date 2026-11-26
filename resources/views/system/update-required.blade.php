<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update required -- E-LIKAS Offline Companion</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen flex flex-col bg-paper text-ink">
    @include('partials._api_target_banner')
    <div class="flex-1 flex items-center justify-center p-6">
    <main class="update-card title-block max-w-md">
        <x-sheet-brand class="mb-6" />
        <div class="flex flex-col items-start mb-6 border-t border-ink pt-5">
            <div class="icon-chip w-12 h-12 mb-3">
                <i class="ti ti-database-cog" style="font-size: 24px;" aria-hidden="true"></i>
            </div>
            <h1 class="font-label font-extrabold uppercase text-[1.75rem] leading-[0.95] text-ink mb-2">This app needs to update its local data</h1>
            <p class="text-sm text-ink-2">
                A newer version of this app was installed, and it needs to prepare
                {{ \App\Support\Plural::of($pendingCount, 'change', 'changes') }} on this device's local data before continuing.
            </p>
        </div>

        @if ($errors->any())
            <div class="callout callout-danger flex items-start gap-2 mb-4" role="alert">
                <i class="ti ti-alert-circle shrink-0 mt-0.5" style="font-size: 14px;" aria-hidden="true"></i>
                {{ $errors->first('update') }}
            </div>
        @endif

        <form method="POST" action="{{ route('system.update-required.run') }}" id="update-form">
            @csrf
            <button type="submit" id="update-btn"
                class="btn btn-primary w-full py-2.5">
                <i class="ti ti-refresh" style="font-size: 15px;" aria-hidden="true"></i>
                <span id="update-btn-label">Update Now</span>
            </button>
        </form>

        <p class="text-xs text-ink-2 mt-6">
            This only touches this device's own local copy of its data -- nothing
            is sent anywhere, and no internet connection is needed. It normally
            takes less than a second. Your existing records are not deleted.
        </p>
    </main>
    </div>

    <script>
        requestAnimationFrame(() => document.querySelector('.update-card')?.classList.add('in'));

        // Runs synchronously on the server (a handful of small SQLite
        // migrations, normally well under a second) -- this is purely a
        // visual "something is happening" cue for that brief window, not
        // a progress bar tracking real steps.
        document.getElementById('update-form').addEventListener('submit', () => {
            const btn = document.getElementById('update-btn');
            btn.disabled = true;
            document.getElementById('update-btn-label').textContent = 'Updating...';
        });
    </script>
</body>
</html>
