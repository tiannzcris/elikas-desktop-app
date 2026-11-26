@props(['center'])
{{-- A center's state as on the web: its symbol (components/center-symbol)
     beside its words (EvacuationCenter::STATUSES, from displayStatus()). --}}
@php
    $status = $center->displayStatus();
    $label = \App\Models\EvacuationCenter::STATUSES[$status] ?? \Illuminate\Support\Str::headline($status);
@endphp
<span {{ $attributes->merge(['class' => 'center-state shrink-0']) }} data-center-status="{{ $status }}"><x-center-symbol :status="$status" />{{ $label }}</span>
