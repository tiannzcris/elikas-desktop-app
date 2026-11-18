{{-- The event every screen works on (server: /selected-event), with the
     open events to switch to and the closed ones to look back at. --}}
@php
    $switcher = app(\App\Services\SelectedEvent::class);
    $working = $switcher->get();
    $eventOptions = $switcher->options();
@endphp
<div class="relative" data-menu data-event-switcher>
    <button type="button" class="btn btn-secondary btn-sm max-w-[26rem]" data-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Event: {{ $working?->name ?? 'none open' }}. Change event">
        <i class="ti ti-calendar-event" aria-hidden="true"></i>
        <span class="text-gray-600 font-medium">Event:</span>
        <span class="truncate" data-working-event>{{ $working?->name ?? 'None open' }}</span>
        @if ($working?->is_demo)<span class="badge badge-warning" data-demo-badge>DEMO</span>@endif
        @if ($working?->isClosed())<span class="badge badge-dark">Closed</span>@endif
        <i class="ti ti-chevron-down text-gray-500" aria-hidden="true"></i>
    </button>
    <div class="menu-list left-0 right-auto w-80" role="menu" data-menu-list hidden>
        @foreach (['open' => 'Open events', 'closed' => 'Closed events (read-only)'] as $group => $heading)
            @if ($eventOptions[$group]->isNotEmpty())
                @unless ($loop->first)<div class="menu-divider"></div>@endunless
                <p class="px-3 pt-2 pb-1 text-xs font-semibold text-gray-600">{{ $heading }}</p>
                @foreach ($group === 'closed' ? $eventOptions[$group]->take(5) : $eventOptions[$group] as $option)
                    <form method="POST" action="{{ route('selected-event.update') }}">
                        @csrf
                        <input type="hidden" name="event" value="{{ $option->id }}">
                        <button type="submit" class="menu-item" role="menuitemradio" aria-checked="{{ $working?->id === $option->id ? 'true' : 'false' }}">
                            <i class="ti {{ $working?->id === $option->id ? 'ti-check text-brand-700' : 'ti-point' }}" aria-hidden="true"></i>
                            <span class="truncate">{{ $option->optionLabel() }}</span>
                            @if ($option->status === 'monitoring')<span class="badge badge-info ml-auto">Monitoring</span>@endif
                        </button>
                    </form>
                @endforeach
            @endif
        @endforeach
        @if ($eventOptions['open']->isEmpty() && $eventOptions['closed']->isEmpty())
            <p class="px-3 py-2 text-sm text-gray-600">No events on this device yet.</p>
        @endif
    </div>
</div>
