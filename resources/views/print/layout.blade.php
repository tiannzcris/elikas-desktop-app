<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} · {{ $event->name }}</title>
    {{-- Self-contained: turned into a PDF by the desktop app's own engine. --}}
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: "Public Sans", "Segoe UI", Arial, sans-serif; font-size: 10.5pt; color: #111827; margin: 0; }
        h1 { font-size: 15pt; margin: 0 0 2pt; }
        h2 { font-size: 11.5pt; margin: 14pt 0 4pt; }
        .sub { color: #374151; margin: 0 0 8pt; }
        .note { border: 1px solid #F59E0B; background: #FFFBEB; padding: 5pt 8pt; margin: 6pt 0 10pt; font-size: 9.5pt; }
        .secure { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 4pt; }
        th, td { border: 1px solid #9CA3AF; padding: 3.5pt 5pt; text-align: left; vertical-align: top; }
        th { background: #F3F4F6; font-weight: 600; }
        td.num, th.num { text-align: right; }
        tr { page-break-inside: avoid; }
        .strip { display: flex; gap: 8pt; margin: 6pt 0; }
        .strip div { flex: 1; border: 1px solid #9CA3AF; padding: 5pt 7pt; }
        .strip b { display: block; font-size: 13pt; }
        .muted { color: #6B7280; }
        .sign { height: 26pt; }
        .footer { margin-top: 14pt; font-size: 8.5pt; color: #4B5563; }
        .print-bar { padding: 10px; background: #EFF6FF; border-bottom: 1px solid #BFDBFE; font-family: inherit; }
        @media print { .print-bar { display: none; } }
    </style>
</head>
<body>
    @if ($printBar ?? false)
        <div class="print-bar">Outside the desktop app there is no PDF engine: <button type="button" onclick="window.print()">Print or save as PDF</button></div>
    @endif
    <h1>{{ $title }}</h1>
    <p class="sub">Barangay {{ $barangay }} · {{ $event->name }}@if ($center) · {{ $center->name }}@endif</p>
    <p class="note">{{ $note }} <span class="secure">This file contains personal information. Keep it secure.</span></p>

    @yield('body')

    <p class="footer">E-LIKAS Offline Companion {{ config('nativephp.version') }} · Printed by {{ $auth->name }} · {{ $note }}</p>
</body>
</html>
