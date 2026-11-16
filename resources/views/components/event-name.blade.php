@props(['event'])
{{-- An event's name, with the DEMO badge for a demonstration event (made-up data). --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 min-w-0']) }}>
    <span class="truncate">{{ $event->name }}</span>
    @if ($event->is_demo)
        <span class="badge badge-warning shrink-0" data-demo-badge>DEMO</span>
    @endif
</span>
