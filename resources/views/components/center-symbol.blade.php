@props(['status', 'size' => 20])
{{-- A center state's symbol, the web's public/js/map-symbols.js: shape +
     pattern + colour, never colour alone. Active is a solid green disc,
     nearly full the amber disc hatched, full the red disc cross-hatched,
     on standby a white disc in a thick blue ring, closed a white square
     struck through in grey. An unknown status draws as on standby, as on
     the web. Always shown beside its words (components/center-status). --}}
@php
    $key = in_array($status, ['active', 'nearly_full', 'full', 'on_standby', 'closed'], true) ? $status : 'on_standby';
@endphp
<svg {{ $attributes->merge(['class' => 'center-symbol']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    @switch($key)
        @case('active')
            <circle cx="12" cy="12" r="9" fill="#15803D" stroke="#17241D" stroke-width="1.5"/>
            @break
        @case('nearly_full')
            <circle cx="12" cy="12" r="9" fill="#D97706" stroke="#17241D" stroke-width="1.5"/><circle cx="12" cy="12" r="9" fill="url(#sym-hatch)"/>
            @break
        @case('full')
            <circle cx="12" cy="12" r="9" fill="#DC2626" stroke="#17241D" stroke-width="1.5"/><circle cx="12" cy="12" r="9" fill="url(#sym-cross)"/>
            @break
        @case('closed')
            <rect x="4" y="4" width="16" height="16" fill="#fff" stroke="#4B5563" stroke-width="2.5"/><path d="M6 6l12 12" stroke="#4B5563" stroke-width="2.5"/>
            @break
        @default
            <circle cx="12" cy="12" r="8" fill="#fff" stroke="#2563EB" stroke-width="3.5"/>
    @endswitch
</svg>
