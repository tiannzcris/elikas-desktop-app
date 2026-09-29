<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>E-LIKAS Offline Companion</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen flex bg-gray-50">
    <main class="flex-1 flex items-center justify-center p-8">
        <div class="login-card w-full max-w-sm">
            @include('partials._api_target_banner', ['bannerClass' => 'rounded-lg mb-6'])

            <div class="flex items-center gap-3 mb-8">
                <img src="{{ asset('images/elikas-logo-mark.png') }}" alt="" class="w-12 h-12 shrink-0">
                <div class="leading-tight">
                    <p class="text-lg font-semibold tracking-tight text-navy">E-LIKAS</p>
                    <p class="text-xs text-gray-600">Staff portal &middot; Offline Companion</p>
                </div>
            </div>

            <h1 class="text-2xl leading-8 font-semibold tracking-tight text-gray-900">Welcome back</h1>
            <p class="text-sm text-gray-600 mt-1 mb-6">Log in to the E-LIKAS offline companion.</p>

            @if ($errors->any())
                <div class="callout callout-danger flex items-start gap-2 mb-4" role="alert">
                    <i class="ti ti-alert-circle shrink-0 mt-0.5" style="font-size: 14px;" aria-hidden="true"></i>
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.submit') }}" class="card p-5 flex flex-col gap-4">
                @csrf
                <div>
                    <label for="email" class="label">Email</label>
                    <div class="relative">
                        <i class="ti ti-mail absolute left-3 top-1/2 text-gray-500" style="font-size: 16px; transform: translateY(-50%);" aria-hidden="true"></i>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" required autocomplete="username"
                            class="input pl-9 py-2.5">
                    </div>
                </div>
                <div>
                    <label for="password" class="label">Password</label>
                    <div class="relative">
                        <i class="ti ti-lock absolute left-3 top-1/2 text-gray-500" style="font-size: 16px; transform: translateY(-50%);" aria-hidden="true"></i>
                        <input type="password" name="password" id="password" required autocomplete="current-password"
                            class="input pl-9 pr-10 py-2.5">
                        <button type="button" id="toggle-password" aria-label="Show password" aria-pressed="false"
                            class="absolute right-1 top-1/2 btn-icon" style="transform: translateY(-50%);">
                            <i class="ti ti-eye" id="toggle-password-icon" style="font-size: 16px;" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-full py-2.5 mt-1">
                    <i class="ti ti-login-2" style="font-size: 15px;" aria-hidden="true"></i> Log in
                </button>
            </form>

            <p class="text-xs text-gray-600 mt-6">
                This connects using your E-LIKAS staff account -- the same one you
                use for the web dashboard, whether you're CSWD personnel or a
                barangay official. Once logged in, this device stays logged in
                for offline use -- you won't need to do this again unless you
                log out.
            </p>
        </div>
    </main>

    {{-- Institutional panel, the same as the web login: Ligao City Hall
         behind the logo navy, with the city seal and the CSWDO logo. --}}
    <aside class="hidden md:block flex-1 relative overflow-hidden">
        <div class="absolute -inset-4" style="background-image: url('{{ asset('images/ligao-city-hall.jpg') }}'); background-size: cover; background-position: center; filter: blur(5px);"></div>
        <div class="absolute inset-0" style="background: linear-gradient(160deg, rgba(7,58,97,0.92), rgba(9,71,118,0.80));"></div>
        <div class="relative h-full flex flex-col items-center justify-center text-center px-8">
            <div class="flex items-center gap-5 mb-8">
                <img src="{{ asset('images/ligao-city-seal.jpg') }}" alt="Official Seal of the City Government of Ligao" class="w-20 h-20 rounded-full ring-4 ring-white/25 shadow-lg object-cover">
                <img src="{{ asset('images/cswdo-ligao-logo.jpg') }}" alt="CSWDO Ligao City logo" class="w-20 h-20 rounded-full ring-4 ring-white/25 shadow-lg object-cover">
            </div>
            <p class="text-white font-semibold text-4xl tracking-tight mb-2">E-LIKAS</p>
            <p class="text-sm text-[#C7D7F0]">Electronic Ligao Kaligtasan Sistema</p>
        </div>
    </aside>

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
