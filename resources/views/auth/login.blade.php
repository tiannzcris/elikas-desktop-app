<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>E-LIKAS Offline Companion</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: { DEFAULT: '#2F5496', dark: '#1F3A6E' } } } } };
    </script>
    <style>body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; }</style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-sm bg-white border border-gray-200 rounded-xl p-8">
        <div class="flex flex-col items-center text-center mb-6">
            <div class="w-11 h-11 rounded-lg bg-blue-500 flex items-center justify-center mb-3">
                <i class="ti ti-shield-check text-white" style="font-size: 22px;" aria-hidden="true"></i>
            </div>
            <p class="text-brand font-semibold text-xl">E-LIKAS</p>
            <p class="text-xs text-gray-400 mt-1">Offline Companion &middot; First-time login requires internet</p>
        </div>

        @if ($errors->any())
            <div class="flex items-start gap-2 bg-red-50 text-red-700 text-sm rounded-lg p-3 mb-4">
                <i class="ti ti-alert-circle shrink-0 mt-0.5" style="font-size: 14px;" aria-hidden="true"></i>
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.submit') }}" class="flex flex-col gap-4">
            @csrf
            <div>
                <label class="text-sm text-gray-600 block mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            </div>
            <div>
                <label class="text-sm text-gray-600 block mb-1">Password</label>
                <input type="password" name="password" required
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            </div>
            <button type="submit"
                class="flex items-center justify-center gap-1.5 bg-brand hover:bg-brand-dark text-white text-sm font-medium rounded-lg py-2.5 mt-2">
                <i class="ti ti-login-2" style="font-size: 15px;" aria-hidden="true"></i> Log in
            </button>
        </form>

        <p class="text-xs text-gray-400 text-center mt-6">
            This connects to your CSWDO account the same way the web dashboard does.
            Once logged in, this device stays logged in for offline use --
            you won't need to do this again unless you log out.
        </p>
    </div>
</body>
</html>
