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
        <div class="callout callout-info mb-6" role="status" data-no-open-event>{{ \App\Models\EvacuationEvent::NO_OPEN_EVENT }}</div>
    @endunless

    @if ($centers->isEmpty())
        <div class="card">
            <div class="empty-state">
                <i class="ti ti-building-community" aria-hidden="true"></i>
                <p>No evacuation centers on this device yet.</p>
                <form method="POST" action="{{ route('reference-data.refresh') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Refresh reference data</button>
                </form>
            </div>
        </div>
    @else
        {{-- The answer first: how full the barangay's centers are. --}}
        @php
            $inside = $centers->sum(fn ($row) => $row['center']->occupancyHere());
            $capacity = $centers->sum(fn ($row) => (int) $row['center']->capacity_persons);
            $inUse = $centers->filter(fn ($row) => in_array($row['center']->displayStatus(), ['active', 'full'], true))->count();
            $full = $centers->filter(fn ($row) => $row['center']->displayStatus() === 'full')->count();
            $waiting = $centers->sum('pendingCount');
        @endphp
        <div class="stat-strip grid-cols-2 lg:grid-cols-4 mb-6" data-ec-summary>
            <div class="stat">
                <p class="stat-label">Centers in use</p>
                <p class="stat-value">{{ $inUse }} <span class="text-base font-normal text-gray-600">of {{ $centers->count() }}</span></p>
            </div>
            <div class="stat">
                <p class="stat-label">People inside</p>
                <p class="stat-value">{{ number_format($inside) }} <span class="text-base font-normal text-gray-600">/ {{ $capacity ? number_format($capacity) : '—' }}</span></p>
            </div>
            <div class="stat {{ $full > 0 ? 'stat-alert' : '' }}">
                <p class="stat-label">Full</p>
                <p class="stat-value">{{ $full }}</p>
            </div>
            <a href="{{ route('sync.index') }}" class="stat {{ $waiting > 0 ? 'stat-pending' : '' }}">
                <p class="stat-label">Waiting to sync</p>
                <p class="stat-value">{{ $waiting }}</p>
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach ($centers as $row)
                @include('ec-board._center_card', ['center' => $row['center'], 'pending' => $row['pendingCount']])
            @endforeach
        </div>
    @endif
@endsection
