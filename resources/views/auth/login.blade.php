<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>E-LIKAS Offline Companion</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen flex flex-col items-center justify-center" style="background: linear-gradient(160deg, #0F2447 0%, #152F5C 45%, #0F2447 100%);">
    @include('partials._api_target_banner')
    <div class="login-card w-full max-w-sm bg-white rounded-3xl p-8 mt-4" style="box-shadow: 0 20px 50px rgba(0,0,0,0.35), 0 4px 12px rgba(0,0,0,0.15);">
        <div class="flex flex-col items-center text-center mb-6">
            <img src="{{ asset('images/logo-full.png') }}" alt="E-LIKAS - Electronic Ligao Kaligtasan Sistema" class="w-48 object-contain mb-2">
            <p class="text-xs text-gray-400 mt-1">Offline Companion &middot; First-time login requires internet</p>
        </div>

        @if ($errors->any())
            <div class="flex items-start gap-2 bg-red-50 text-red-700 text-sm rounded-xl p-3 mb-4">
                <i class="ti ti-alert-circle shrink-0 mt-0.5" style="font-size: 14px;" aria-hidden="true"></i>
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.submit') }}" class="flex flex-col gap-4">
            @csrf
            <div>
                <label class="text-sm text-gray-600 font-medium block mb-1">Email</label>
                <div class="relative">
                    <i class="ti ti-mail absolute left-3 top-1/2 text-gray-400" style="font-size: 16px; transform: translateY(-50%);" aria-hidden="true"></i>
                    <input type="email" name="email" value="{{ old('email') }}" required
                        class="w-full border border-gray-300 rounded-xl pl-9 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent">
                </div>
            </div>
            <div>
                <label class="text-sm text-gray-600 font-medium block mb-1">Password</label>
                <div class="relative">
                    <i class="ti ti-lock absolute left-3 top-1/2 text-gray-400" style="font-size: 16px; transform: translateY(-50%);" aria-hidden="true"></i>
                    <input type="password" name="password" id="password" required
                        class="w-full border border-gray-300 rounded-xl pl-9 pr-9 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent">
                    <button type="button" id="toggle-password" aria-label="Show password" aria-pressed="false"
                        class="absolute right-3 top-1/2 text-gray-400 hover:text-gray-600" style="transform: translateY(-50%);">
                        <i class="ti ti-eye" id="toggle-password-icon" style="font-size: 16px;" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <button type="submit"
                class="btn-modern btn-primary-modern flex items-center justify-center gap-1.5 bg-brand hover:bg-brand-dark text-white text-sm py-2.5 mt-2">
                <i class="ti ti-login-2" style="font-size: 15px;" aria-hidden="true"></i> Log in
            </button>
        </form>

        <p class="text-xs text-gray-400 text-center mt-6">
            This connects using your E-LIKAS staff account -- the same one you
            use for the web dashboard, whether you're CSWD personnel or a
            barangay official. Once logged in, this device stays logged in
            for offline use -- you won't need to do this again unless you
            log out.
        </p>
    </div>

    <script>
        requestAnimationFrame(() => document.querySelector('.login-card')?.classList.add('in'));

        // Show/hide password -- purely a UI convenience, no effect on the
        // actual login request. Always starts masked: type="password" is
        // the input's default in the markup above and nothing here
        // persists state across page loads, so a fresh/reopened login
        // page is masked again every time, by construction.
        const passwordInput = document.getElementById('password');
        const toggleBtn = document.getElementById('toggle-password');
        const toggleIcon = document.getElementById('toggle-password-icon');
        toggleBtn?.addEventListener('click', () => {
            const showing = passwordInput.type === 'text';
            passwordInput.type = showing ? 'password' : 'text';
            toggleIcon.classList.toggle('ti-eye', showing);
            toggleIcon.classList.toggle('ti-eye-off', !showing);
            toggleBtn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            toggleBtn.setAttribute('aria-pressed', String(!showing));
        });
    </script>
</body>
</html>
