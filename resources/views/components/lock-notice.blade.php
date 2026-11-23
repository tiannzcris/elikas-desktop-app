@props(['message', 'compact' => false])
{{-- The one lock design (App\Support\EventLock): what the event's state holds
     back, in the server's words. Used for this device's own checks and for
     the server's 422 lock answers alike. --}}
@php
    $kind = \App\Support\EventLock::kindOf($message);
    $title = match ($kind) {
        \App\Support\EventLock::MONITORING => 'Waiting for the evacuation to start',
        \App\Support\EventLock::CLOSED => 'Read-only',
        default => 'Locked',
    };
@endphp
<div {{ $attributes->merge(['class' => 'callout callout-lock flex items-start gap-3'.($compact ? ' py-2 text-sm' : '')]) }} role="note" data-lock-notice="{{ $kind ?? 'lock' }}">
    <i class="ti ti-lock shrink-0 mt-0.5" style="font-size: 18px;" aria-hidden="true"></i>
    <div class="min-w-0">
        @unless ($compact)<p class="font-semibold">{{ $title }}</p>@endunless
        <p>{{ $message }}</p>
        @if (trim($slot) !== '')
            <div class="mt-2">{{ $slot }}</div>
        @endif
    </div>
</div>
