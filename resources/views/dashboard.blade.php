@extends('layouts.app')

@section('title', 'Dashboard')
@section('nav-dashboard', 'active')

@section('content')
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Dashboard</h1>
            <p class="page-subtitle">Barangay {{ $currentUser->barangay_name }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('ec-board.index') }}" class="btn btn-primary">
                <i class="ti ti-building-community" style="font-size: 15px;" aria-hidden="true"></i> Open EC Board
            </a>
            <x-row-menu label="More actions">
                <form method="POST" action="{{ route('reference-data.refresh') }}">
                    @csrf
                    <button type="submit" class="menu-item" role="menuitem" data-online-only>
                        <i class="ti ti-refresh" aria-hidden="true"></i> Refresh reference data
                    </button>
                </form>
            </x-row-menu>
        </div>
    </div>

    @include('partials._event_state', ['event' => $event, 'what' => \App\Support\EventLock::REGISTERING, 'showOpen' => true, 'arriving' => true])

    <div class="stat-strip grid-cols-2 lg:grid-cols-4 mb-8">
        <div class="stat">
            <p class="stat-label">Current event</p>
            <p class="stat-value text-base leading-8">
                @if ($event)
                    <x-event-name :event="$event" />
                @else
                    None open
                @endif
            </p>
        </div>
        <a href="{{ route('sync.index') }}" class="stat {{ $waiting > 0 ? 'stat-pending' : '' }}">
            <p class="stat-label">Waiting to send</p>
            <p class="stat-value">{{ number_format($waiting) }}</p>
        </a>
        <a href="{{ route('sync.index') }}" class="stat {{ $attention > 0 ? 'stat-alert' : '' }}">
            <p class="stat-label">Needs attention</p>
            <p class="stat-value">{{ number_format($attention) }}</p>
        </a>
        <div class="stat">
            <p class="stat-label">Last sent</p>
            <p class="stat-value text-base leading-8">
                {{ $lastSyncAt ? \App\Support\Format::when($lastSyncAt) : 'Not yet' }}
            </p>
        </div>
    </div>

    <section aria-labelledby="centers-title">
        <h2 id="centers-title" class="section-title mb-4">Your centers</h2>
        @if ($centers->isEmpty())
            <div class="card"><x-empty icon="ti-building-community" title="No evacuation centers on this device yet." text="Refresh reference data while online to bring your barangay's centers here."><form method="POST" action="{{ route('reference-data.refresh') }}">@csrf<button type="submit" class="btn btn-secondary" data-online-only><i class="ti ti-refresh" aria-hidden="true"></i> Refresh reference data</button></form></x-empty></div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($centers as $row)
                    @include('ec-board._center_card', ['center' => $row['center'], 'pending' => $row['pending']])
                @endforeach
            </div>
        @endif
    </section>
@endsection
