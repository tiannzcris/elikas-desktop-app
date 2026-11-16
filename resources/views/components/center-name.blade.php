@props(['center'])
{{-- A center's name, with "Unverified" while CSWD has not approved it yet. --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 min-w-0']) }}>
    <span class="truncate">{{ $center->name }}</span>
    @if ($center->isUnverified())
        <span class="badge badge-warning shrink-0 font-medium" data-unverified-badge>Unverified</span>
    @endif
</span>
