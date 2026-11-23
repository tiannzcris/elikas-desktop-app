@extends('layouts.app')

@section('title', 'EC Board')
@section('nav-ec-board', 'active')

@section('content')
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">EC Board</h1>
            @if ($barangayName)
                <p class="page-subtitle">Evacuation centers of Barangay {{ $barangayName }}</p>
            @endif
        </div>
    </div>

    @unless (\App\Models\EvacuationEvent::anyOpen())
        <x-lock-notice :message="\App\Models\EvacuationEvent::NO_OPEN_EVENT" class="mb-6" data-no-open-event />
    @else
        @include('partials._event_state', ['what' => \App\Support\EventLock::REGISTERING, 'showOpen' => true, 'arriving' => true])
    @endunless

    @if ($centers->isEmpty())
        <div class="card">
            <x-empty icon="ti-building-community" title="No evacuation centers on this device yet." text="Refresh reference data while online to bring your barangay's centers here."><form method="POST" action="{{ route('reference-data.refresh') }}">@csrf<button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh reference data</button></form></x-empty>
        </div>
        <div class="mt-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @include('ec-board._private_houses_card')
        </div>
    @else
        {{-- The answer first: how full the barangay's centers are. --}}
        @php
            $inside = $centers->sum(fn ($row) => $row['center']->occupancyHere());
            $capacity = $centers->reject(fn ($row) => $row['center']->capacityUnknown())->sum(fn ($row) => (int) $row['center']->capacity_persons);
            $unknownCapacity = $centers->filter(fn ($row) => $row['center']->capacityUnknown())->count();
            $estimated = $centers->contains(fn ($row) => $row['center']->capacityEstimated());
            $inUse = $centers->filter(fn ($row) => $row['center']->inUse())->count();
            $full = $centers->filter(fn ($row) => $row['center']->displayStatus() === 'full')->count();
            $waiting = $centers->sum('pendingCount') + $privateHouses['waiting'];
        @endphp
        <div class="stat-strip grid-cols-2 lg:grid-cols-5 mb-6" data-ec-summary>
            <div class="stat">
                <p class="stat-label">Centers in use</p>
                <p class="stat-value">{{ number_format($inUse) }} <span class="text-base font-normal text-gray-600">of {{ number_format($centers->count()) }}</span></p>
            </div>
            <div class="stat">
                <p class="stat-label">People inside</p>
                <p class="stat-value">{{ number_format($inside) }} <span class="text-base font-normal text-gray-600">/ {{ $capacity ? number_format($capacity).($estimated ? ' est.' : '') : '–' }}</span></p>
                @if ($unknownCapacity > 0)
                    <p class="stat-note" data-capacity-unknown>{{ \App\Support\Plural::of($unknownCapacity, 'center', 'centers') }} with capacity unknown</p>
                @endif
            </div>
            <div class="stat {{ $full > 0 ? 'stat-alert' : '' }}">
                <p class="stat-label">Full</p>
                <p class="stat-value">{{ number_format($full) }}</p>
            </div>
            <a href="{{ route('private-houses.index') }}" class="stat" data-private-houses-stat>
                <p class="stat-label">In private houses</p>
                <p class="stat-value">{{ number_format($privateHouses['person_count']) }}</p>
                <p class="stat-note">{{ \App\Support\Plural::of($privateHouses['family_count'], 'family', 'families') }}</p>
            </a>
            <a href="{{ route('sync.index') }}" class="stat {{ $waiting > 0 ? 'stat-pending' : '' }}">
                <p class="stat-label">Waiting to sync</p>
                <p class="stat-value">{{ number_format($waiting) }}</p>
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach ($centers as $row)
                @include('ec-board._center_card', ['center' => $row['center'], 'pending' => $row['pendingCount']])
            @endforeach
            @include('ec-board._private_houses_card')
        </div>
    @endif
@endsection
