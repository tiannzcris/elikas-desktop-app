<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update required -- E-LIKAS Offline Companion</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen flex flex-col items-center justify-center" style="background: linear-gradient(160deg, #0F2447 0%, #152F5C 45%, #0F2447 100%);">
    @include('partials._api_target_banner')
    <div class="update-card w-full max-w-md bg-white rounded-3xl p-8 mt-4" style="box-shadow: 0 20px 50px rgba(0,0,0,0.35), 0 4px 12px rgba(0,0,0,0.15);">
        <div class="flex flex-col items-center text-center mb-6">
            <div class="w-14 h-14 rounded-2xl bg-blue-50 flex items-center justify-center mb-3">
                <i class="ti ti-database-cog text-brand" style="font-size: 26px;" aria-hidden="true"></i>
            </div>
            <h1 class="text-lg font-bold text-brand mb-1">This app needs to update its local data</h1>
            <p class="text-sm text-gray-500">
                A newer version of this app was installed, and it needs to prepare
                {{ $pendingCount }} {{ Str::plural('change', $pendingCount) }} on this device's local data before continuing.
            </p>
        </div>

        @if ($errors->any())
            <div class="flex items-start gap-2 bg-red-50 text-red-700 text-sm rounded-xl p-3 mb-4">
                <i class="ti ti-alert-circle shrink-0 mt-0.5" style="font-size: 14px;" aria-hidden="true"></i>
                {{ $errors->first('update') }}
            </div>
        @endif

        <form method="POST" action="{{ route('system.update-required.run') }}" id="update-form">
            @csrf
            <button type="submit" id="update-btn"
                class="btn-modern btn-primary-modern w-full flex items-center justify-center gap-1.5 bg-brand hover:bg-brand-dark text-white text-sm py-2.5">
                <i class="ti ti-refresh" style="font-size: 15px;" aria-hidden="true"></i>
                <span id="update-btn-label">Update Now</span>
            </button>
        </form>

        <p class="text-xs text-gray-400 text-center mt-6">
            This only touches this device's own local copy of its data -- nothing
            is sent anywhere, and no internet connection is needed. It normally
            takes less than a second. Your existing records are not deleted.
        </p>
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
