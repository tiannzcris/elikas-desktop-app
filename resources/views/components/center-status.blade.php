@props(['center'])
{{-- A center's status chip, as on the web: active green, on standby blue,
     full red, closed dark gray (EvacuationCenter::displayStatus()). --}}
@php
    $status = $center->displayStatus();
    [$label, $tone] = \App\Models\EvacuationCenter::STATUSES[$status] ?? [\Illuminate\Support\Str::headline($status), 'badge-neutral'];
@endphp
<span {{ $attributes->merge(['class' => "badge {$tone} shrink-0"]) }} data-center-status="{{ $status }}">{{ $label }}</span>
