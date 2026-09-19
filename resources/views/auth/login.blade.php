<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>E-LIKAS Offline Companion</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen flex bg-white">
    <div class="flex-1 flex items-center justify-center p-8">
        <div class="login-card w-full max-w-sm">
            @include('partials._api_target_banner')

            <p class="text-xs font-semibold tracking-widest text-brand uppercase mb-2">E-LIKAS Staff Portal</p>
            <h1 class="text-3xl font-bold tracking-tight text-gray-900 mb-1">Welcome back</h1>
            <p class="text-sm text-gray-500 mb-8">Log in to the E-LIKAS offline companion.</p>

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

            <p class="text-xs text-gray-400 mt-6">
                This connects using your E-LIKAS staff account -- the same one you
                use for the web dashboard, whether you're CSWD personnel or a
                barangay official. Once logged in, this device stays logged in
                for offline use -- you won't need to do this again unless you
                log out.
            </p>
        </div>
    </div>

    <div class="hidden md:block flex-1 relative overflow-hidden">
        <div class="absolute -inset-4" style="background-image: url('{{ asset('images/ligao-city-hall.jpg') }}'); background-size: cover; background-position: center; filter: blur(5px);"></div>
        <div class="absolute inset-0" style="background: linear-gradient(160deg, rgba(15,36,71,0.92) 0%, rgba(21,47,92,0.82) 45%, rgba(15,36,71,0.92) 100%);"></div>
        <div class="relative h-full flex flex-col items-center justify-center text-center px-8">
            <div class="flex items-center gap-5 mb-7">
                <img src="{{ asset('images/ligao-city-seal.jpg') }}" alt="Official Seal of the City Government of Ligao" class="w-20 h-20 rounded-full ring-4 ring-white/25 shadow-lg object-cover">
                <img src="{{ asset('images/cswdo-ligao-logo.jpg') }}" alt="CSWDO Ligao City logo" class="w-20 h-20 rounded-full ring-4 ring-white/25 shadow-lg object-cover">
            </div>
            <p class="font-bold text-4xl tracking-tight mb-2"><span style="color: #E63946;">E-</span><span class="text-white">LIKAS</span></p>
            <p class="text-sm tracking-wide" style="color: #CFE0F5;">Electronic Ligao Kaligtasan Sistema</p>
        </div>
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
