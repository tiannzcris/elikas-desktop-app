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
            <p class="stat-value">{{ $waiting }}</p>
        </a>
        <a href="{{ route('sync.index') }}" class="stat {{ $attention > 0 ? 'stat-alert' : '' }}">
            <p class="stat-label">Needs attention</p>
            <p class="stat-value">{{ $attention }}</p>
        </a>
        <div class="stat">
            <p class="stat-label">Last sent</p>
            <p class="stat-value text-base leading-8">
                {{ $lastSyncAt ? \Illuminate\Support\Carbon::parse($lastSyncAt)->timezone('Asia/Manila')->format('M j, g:i A') : 'Not yet' }}
            </p>
        </div>
    </div>

    <section aria-labelledby="centers-title">
        <h2 id="centers-title" class="section-title mb-4">Your centers</h2>
        @if ($centers->isEmpty())
            <div class="card"><div class="empty-state">
                <i class="ti ti-building-community" aria-hidden="true"></i>
                <p>No evacuation centers on this device yet. Refresh reference data while online.</p>
            </div></div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach ($centers as $row)
                    <a href="{{ route('evacuation-centers.ec-board', $row['center']) }}" class="card card-link card-body flex items-center justify-between gap-3">
                        <span class="card-title min-w-0"><x-center-name :center="$row['center']" /></span>
                        @if ($row['pending'] > 0)
                            <span class="badge badge-warning shrink-0">{{ $row['pending'] }} waiting</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
