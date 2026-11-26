@props(['line' => 'Staff portal · Offline Companion'])
{{-- The mark and name at the top of a sheet outside the app shell (login,
     signed out, use the web, update required), as on the web's staff login. --}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    <img src="{{ asset('images/elikas-logo-mark.png') }}" alt="" width="44" height="44" class="w-11 h-11 shrink-0">
    <span class="leading-none">
        <span class="block font-label font-extrabold uppercase text-[1.5rem] tracking-[.06em] text-ink">E-LIKAS</span>
        <span class="block mt-1 font-label font-semibold uppercase tracking-[.14em] text-[0.75rem] text-ink-2">{{ $line }}</span>
    </span>
</div>
