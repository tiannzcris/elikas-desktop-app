<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Signed out · E-LIKAS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-50 p-6">
    <main class="login-card card card-body w-full max-w-md flex flex-col gap-5" data-unlock>
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-full bg-navy flex items-center justify-center shrink-0">
                <i class="ti ti-lock text-white" style="font-size: 22px;" aria-hidden="true"></i>
            </div>
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-gray-900">Signed out after {{ $minutes }} minutes without activity</h1>
                <p class="text-sm text-gray-600">Personal data stays hidden until you sign in again.</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="callout callout-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('unlock.submit') }}" class="flex flex-col gap-4">
            @csrf
            <div>
                <p class="label">Account</p>
                <p class="font-medium text-gray-900">{{ $auth->name }} <span class="text-gray-600 font-normal">· {{ $auth->email }}</span></p>
            </div>
            <div>
                <label for="unlock-password" class="label">Password</label>
                <input id="unlock-password" type="password" name="password" class="input" required autofocus autocomplete="current-password" @disabled($lockedOut)>
                <p class="help">Works offline: checked on this computer.</p>
            </div>
            <button type="submit" class="btn btn-primary w-full" @disabled($lockedOut)>Sign in again</button>
        </form>

        @if ($pending > 0)
            <p class="text-sm text-gray-700">{{ \App\Support\Plural::of($pending, 'record waits', 'records wait') }} on this computer for this account. They are kept.</p>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="border-t border-gray-100 pt-4"
            @if ($pending > 0) data-confirm-title="Sign in as someone else?" data-confirm="{{ \App\Support\Plural::of($pending, 'record stays', 'records stay') }} on this computer for {{ $auth->name }}'s next sign-in. Signing in as someone else needs the internet." data-confirm-label="Sign out" @endif>
            @csrf
            <button type="submit" class="btn btn-ghost w-full">Sign in as someone else</button>
        </form>
    </main>
</body>
</html>
