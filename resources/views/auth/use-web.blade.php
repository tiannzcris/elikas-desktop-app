<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Use the web · E-LIKAS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-50 p-8">
    <main class="w-full max-w-md">
        <div class="flex items-center gap-3 mb-8">
            <img src="{{ asset('images/elikas-logo-mark.png') }}" alt="" class="w-12 h-12 shrink-0">
            <p class="text-lg font-semibold tracking-tight text-navy">E-LIKAS</p>
        </div>
        <div class="card card-body flex flex-col gap-4">
            <h1 class="page-title">Use the web</h1>
            <p class="text-gray-700">This account has no work on the desktop app. Accounts, logs and backups are on the web.</p>
            <p class="font-medium text-gray-900 break-all" data-web-url>{{ $webUrl }}</p>
            <a href="{{ route('login') }}" class="btn btn-secondary self-start">Log in with another account</a>
        </div>
    </main>
</body>
</html>
