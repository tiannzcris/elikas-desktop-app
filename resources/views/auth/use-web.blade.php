<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Use the web · E-LIKAS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen flex items-center justify-center bg-paper text-ink p-8">
    <main class="title-block max-w-md flex flex-col gap-4">
        <x-sheet-brand class="mb-2" />
        <h1 class="page-title border-t border-ink pt-5">Use the web</h1>
        <p class="text-ink-2">This account has no work on the desktop app. Accounts, logs and backups are on the web.</p>
        <p class="font-medium text-ink break-all" data-web-url>{{ $webUrl }}</p>
        <a href="{{ route('login') }}" class="btn btn-secondary self-start">Log in with another account</a>
    </main>
</body>
</html>
