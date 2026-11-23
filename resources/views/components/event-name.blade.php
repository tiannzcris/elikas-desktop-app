@props(['event'])
{{-- An event's name. A practice event (is_demo) looks like any other event:
     no DEMO badge or label anywhere (docs/backend/apps-api-2026-11-22.md 1). --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 min-w-0']) }}>
    <span class="truncate">{{ $event->name }}</span>
</span>
