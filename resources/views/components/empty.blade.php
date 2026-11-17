@props(['icon' => 'ti-inbox', 'title', 'text' => null, 'compact' => false])
{{-- Empty state (web design system 5): an icon, one line saying what is
     missing, and the action that fixes it when there is one (slot). --}}
<div {{ $attributes->merge(['class' => 'empty-state'.($compact ? ' py-6' : '')]) }} data-empty>
    <i class="ti {{ $icon }}" aria-hidden="true"></i>
    <p class="empty-title">{{ $title }}</p>
    @if ($text)
        <p class="empty-text">{{ $text }}</p>
    @endif
    @if (trim($slot) !== '')
        <div class="mt-2">{{ $slot }}</div>
    @endif
</div>
