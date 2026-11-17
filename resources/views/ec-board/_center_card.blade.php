{{-- One evacuation center: name, status chip, people inside against
     capacity (with a meter), and what waits on this device. --}}
@php
    $inside = $center->occupancyHere();
    $capacity = $center->capacity_persons;
    $percent = $capacity ? min(100, (int) round(100 * $inside / $capacity)) : null;
    $status = $center->displayStatus();
    $meterColor = match (true) {
        $percent === null => 'bg-gray-300',
        $percent >= 90 => 'bg-red-600',
        $percent >= 70 => 'bg-amber-500',
        default => 'bg-green-700',
    };
@endphp
<a href="{{ route('evacuation-centers.ec-board', $center) }}" class="card card-link card-body flex flex-col gap-3" data-center-card>
    <div class="flex items-start justify-between gap-3">
        <p class="card-title min-w-0"><x-center-name :center="$center" /></p>
        <x-center-status :center="$center" />
    </div>
    <div>
        <p class="flex items-baseline gap-2">
            <span class="text-2xl font-semibold tabular-nums text-gray-900" data-occupancy>{{ number_format($inside) }} / {{ $capacity ? number_format($capacity) : '—' }}</span>
            <span class="text-sm text-gray-600">{{ $capacity ? 'people inside · '.$percent.'% full' : 'people inside · capacity not set' }}</span>
        </p>
        <div class="meter mt-2" role="img" aria-label="{{ $capacity ? $percent.'% of capacity' : 'Capacity not set' }}">
            <div class="meter-fill {{ $meterColor }}" style="width: {{ $percent ?? 0 }}%"></div>
        </div>
    </div>
    @if (($pending ?? 0) > 0)
        <p class="text-sm font-medium text-amber-800">{{ \App\Support\Plural::of($pending, 'person', 'people') }} waiting to sync</p>
    @endif
</a>
