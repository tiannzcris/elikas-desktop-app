<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>E-LIKAS Offline Companion</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen flex flex-col bg-paper text-ink">
    {{-- No bulletin band here: the desktop is often offline at login, so it
         could not promise live status (DESIGN.md, Band Is Live Rule). --}}
    @include('partials._api_target_banner')
    <div class="flex-1 grid md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <main class="flex items-center justify-center px-6 py-10 sm:px-8">
            <div class="login-card w-full max-w-[26rem]">
                <x-sheet-brand class="mb-10" />

                <h1 class="font-label font-extrabold uppercase text-[3rem] leading-[0.95] text-ink">Welcome back</h1>
                <p class="text-[15px] text-ink-2 mt-3 mb-7">Log in to the E-LIKAS offline companion.</p>

                @if ($errors->has('locked'))
                    {{-- 423: five failed sign-ins in a row; the server says until when. --}}
                    <div class="callout callout-lock flex items-start gap-2 mb-4" role="alert" data-account-locked>
                        <i class="ti ti-lock shrink-0 mt-0.5" style="font-size: 16px;" aria-hidden="true"></i>
                        <span><span class="font-semibold block">Account locked</span>{{ $errors->first('locked') }}</span>
                    </div>
                @elseif ($errors->has('maintenance'))
                    <div class="callout callout-warning flex items-start gap-2 mb-4" role="alert" data-maintenance>
                        <i class="ti ti-tool shrink-0 mt-0.5" style="font-size: 16px;" aria-hidden="true"></i>
                        <span><span class="font-semibold block">The server is under maintenance</span>{{ $errors->first('maintenance') }} Try again later.</span>
                    </div>
                @elseif ($errors->any())
                    <div class="callout callout-danger flex items-start gap-2 mb-4" role="alert">
                        <i class="ti ti-alert-circle shrink-0 mt-0.5" style="font-size: 16px;" aria-hidden="true"></i>
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.submit') }}" class="flex flex-col gap-5">
                    @csrf
                    <div>
                        <label for="email" class="label-caps">Email or username</label>
                        <input type="text" name="email" id="email" value="{{ old('email') }}" required autofocus autocomplete="username" spellcheck="false" autocapitalize="off"
                            class="input border-[1.5px] border-ink-2 py-3">
                    </div>
                    <div>
                        <label for="password" class="label-caps">Password</label>
                        <div class="relative">
                            <input type="password" name="password" id="password" required autocomplete="current-password"
                                class="input border-[1.5px] border-ink-2 py-3 pr-12">
                            <button type="button" id="toggle-password" aria-label="Show password" aria-pressed="false"
                                class="absolute right-0 top-0 h-full w-12 flex items-center justify-center text-ink-2 hover:text-ink">
                                <i class="ti ti-eye" id="toggle-password-icon" style="font-size: 20px;" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="inline-flex items-center justify-center gap-2 w-full bg-brand text-white font-label font-bold uppercase tracking-[.12em] text-[1.1875rem] py-4 transition-colors hover:bg-brand-dark">
                        Log in
                    </button>
                </form>

                <p class="mt-8 pt-4 border-t border-ink text-sm text-ink-2">Your E-LIKAS staff account, the same as on the web.</p>
            </div>
        </main>

        {{-- Partner plate, as on the web's staff login: Ligao City Hall in a
             neatline frame, with the city seal and the CSWDO logo -- worded
             as a partnership, not as the office that runs the system. --}}
        <aside class="hidden md:flex flex-col justify-center gap-6 border-l border-ink bg-paper-2 px-10 py-10 lg:px-14">
            <figure class="neatline bg-paper">
                <img src="{{ asset('images/ligao-city-hall.jpg') }}" alt="Ligao City Hall" class="block w-full h-auto">
                <figcaption class="caption px-3 py-2 border-t-2 border-ink">Ligao City Hall, Albay</figcaption>
            </figure>
            <div class="flex items-center gap-4">
                <img src="{{ asset('images/ligao-city-seal.jpg') }}" alt="Official Seal of the City Government of Ligao" class="w-16 h-16 rounded-full object-cover shrink-0">
                <img src="{{ asset('images/cswdo-ligao-logo.jpg') }}" alt="CSWDO Ligao City logo" class="w-16 h-16 rounded-full object-cover shrink-0">
                <p class="text-[15px] text-ink-2 max-w-[22rem]">E-LIKAS is a capstone project, in partnership with CSWDO Ligao City.</p>
            </div>
        </aside>
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
