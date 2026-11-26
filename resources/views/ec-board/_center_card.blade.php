{{-- One evacuation center: name, status chip, people inside against
     capacity (with a meter), and what waits on this device. An estimated
     capacity says "est." (tooltip: how it was estimated); an unknown one
     shows no percentage and is never full. --}}
@php
    $inside = $center->occupancyHere();
    $unknown = $center->capacityUnknown();
    $capacity = $unknown ? null : (int) $center->capacity_persons;
    $percent = $capacity ? min(100, (int) round(100 * $inside / $capacity)) : null;
    $meterColor = match (true) {
        $percent === null => 'bg-gray-400',
        $percent >= 90 => 'bg-center-full',
        $percent >= 70 => 'bg-center-nearly-full',
        default => 'bg-center-active',
    };
@endphp
<a href="{{ route('evacuation-centers.ec-board', $center) }}" class="card card-link card-body flex flex-col gap-3" data-center-card>
    <div class="flex items-start justify-between gap-3">
        <p class="card-title min-w-0"><x-center-name :center="$center" /></p>
        <x-center-status :center="$center" />
    </div>
    <div>
        <p class="flex flex-wrap items-baseline gap-x-2" data-occupancy>
            <span class="font-label font-bold text-[2rem] leading-none tabular-nums text-ink">{{ number_format($inside) }} / {{ $unknown ? '–' : number_format($capacity) }}</span>
            @if ($center->capacityEstimated())
                <span class="text-sm font-semibold text-gray-700 underline decoration-dotted underline-offset-4" title="{{ $center->capacityTooltip() }}" data-capacity-est>est.</span>
            @endif
            <span class="text-sm text-gray-600">{{ $unknown ? 'people inside · capacity unknown' : 'people inside · '.$percent.'%' }}</span>
        </p>
        @unless ($unknown)
            <div class="meter mt-2" role="img" aria-label="{{ $percent }}% of {{ $center->capacityEstimated() ? 'the estimated capacity' : 'capacity' }}">
                <div class="meter-fill {{ $meterColor }}" style="width: {{ $percent }}%"></div>
            </div>
        @endunless
        @include('evacuation-centers._occupancy_note')
    </div>
    @if ($readiness = $readiness ?? null)
        @php [$readyLabel, $readyTone] = \App\Services\BarangayDashboard::READINESS[$readiness] ?? [\Illuminate\Support\Str::headline($readiness), 'badge-neutral']; @endphp
        <p class="text-sm" data-readiness="{{ $readiness }}">Facilities: <span class="badge {{ $readyTone }}">{{ $readyLabel }}</span></p>
    @endif
    @if (($pending ?? 0) > 0)
        <p class="text-sm font-medium text-amber-800">{{ \App\Support\Plural::of($pending, 'person', 'people') }} waiting to sync</p>
    @endif
</a>
