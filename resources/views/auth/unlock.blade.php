<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Signed out · E-LIKAS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center bg-paper text-ink p-6">
    <main class="login-card title-block max-w-md flex flex-col gap-5" data-unlock>
        <x-sheet-brand />
        <div class="flex items-start gap-3 border-t border-ink pt-5">
            <i class="ti ti-lock shrink-0 text-ink" style="font-size: 28px;" aria-hidden="true"></i>
            <div class="min-w-0">
                <h1 class="font-label font-extrabold uppercase text-[1.75rem] leading-[0.95] text-ink">Signed out after {{ $minutes }} minutes without activity</h1>
                <p class="mt-2 text-sm text-ink-2">Personal data stays hidden until you sign in again.</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="callout callout-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('unlock.submit') }}" class="flex flex-col gap-4">
            @csrf
            <div>
                <p class="label-caps">Account</p>
                <p class="font-medium text-ink">{{ $auth->name }} <span class="text-gray-600 font-normal">· {{ $auth->email }}</span></p>
            </div>
            <div>
                <label for="unlock-password" class="label-caps">Password</label>
                <input id="unlock-password" type="password" name="password" class="input" required autofocus autocomplete="current-password" @disabled($lockedOut)>
                <p class="help">Works offline: checked on this computer.</p>
            </div>
            <button type="submit" class="btn btn-primary w-full" @disabled($lockedOut)>Sign in again</button>
        </form>

        @if ($pending > 0)
            <p class="text-sm text-gray-700">{{ \App\Support\Plural::of($pending, 'record waits', 'records wait') }} on this computer for this account. They are kept.</p>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="border-t border-ink pt-4"
            @if ($pending > 0) data-confirm-title="Sign in as someone else?" data-confirm="{{ \App\Support\Plural::of($pending, 'record stays', 'records stay') }} on this computer for {{ $auth->name }}'s next sign-in. Signing in as someone else needs the internet." data-confirm-label="Sign out" @endif>
            @csrf
            <button type="submit" class="btn btn-ghost w-full">Sign in as someone else</button>
        </form>
    </main>
</body>
</html>
