@props(['label' => 'More actions'])
{{-- "..." menu for a row's actions (web design system 5). --}}
<div class="relative inline-block" data-menu>
    <button type="button" class="btn-icon" data-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="{{ $label }}">
        <i class="ti ti-dots" style="font-size: 16px;" aria-hidden="true"></i>
    </button>
    <div class="menu-list" role="menu" data-menu-list hidden>
        {{ $slot }}
    </div>
</div>
