@extends('layouts.app')

@section('title', 'Dashboard')
@section('nav-dashboard', 'active')

@section('content')
    <div class="page-header">
        <div>
            <h1 class="page-title">Welcome back, {{ $currentUser->name }}!</h1>
            <p class="page-subtitle">{{ $currentUser->barangay_name ?? 'City-wide access' }} &middot; Here's what's registered on this device.</p>
        </div>
    </div>

    {{-- One ruled strip of figures, the same as the web dashboard's. Pending
         sync takes the EC Board's amber "waiting on this device" tint only
         when something is actually waiting. --}}
    <div class="stat-strip grid-cols-2 lg:grid-cols-4 mb-6">
        <div class="stat">
            <p class="stat-label"><i class="ti ti-map-pin" style="font-size: 14px;" aria-hidden="true"></i> Barangays</p>
            <p class="stat-value">{{ $barangayCount }}</p>
        </div>
        <div class="stat">
            <p class="stat-label"><i class="ti ti-alert-triangle" style="font-size: 14px;" aria-hidden="true"></i> Active events</p>
            <p class="stat-value">{{ $eventCount }}</p>
        </div>
        <div class="stat">
            <p class="stat-label"><i class="ti ti-building" style="font-size: 14px;" aria-hidden="true"></i> Evacuation centers</p>
            <p class="stat-value">{{ $centerCount }}</p>
        </div>
        <div class="stat {{ $pendingSyncCount > 0 ? 'stat-pending' : '' }}">
            <p class="stat-label"><i class="ti ti-cloud-upload" style="font-size: 14px;" aria-hidden="true"></i> Pending sync</p>
            <p class="stat-value">{{ $pendingSyncCount }}</p>
        </div>
    </div>

    <div class="card p-5 mb-6">
        <div class="card-header">
            <h2 class="card-title flex items-center gap-2">
                <i class="ti ti-cloud-upload text-gray-500" style="font-size: 16px;" aria-hidden="true"></i>
                Registration sync status
            </h2>
        </div>
        <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
            <p><span class="font-semibold text-amber-800 tabular-nums">{{ $pendingSyncCount }}</span> <span class="text-gray-600">waiting to sync</span></p>
            <p><span class="font-semibold text-green-800 tabular-nums">{{ $syncedCount }}</span> <span class="text-gray-600">already synced</span></p>
        </div>
        @if ($barangayCount === 0)
            <p class="callout callout-warning text-xs mt-3 flex items-center gap-1.5">
                <i class="ti ti-alert-circle" style="font-size: 14px;" aria-hidden="true"></i>
                No reference data cached yet -- you'll need internet at least once to refresh before registering families.
            </p>
        @endif
    </div>

    <div class="flex flex-wrap gap-3">
        <form method="POST" action="{{ route('reference-data.refresh') }}">
            @csrf
            <button type="submit" class="btn btn-secondary">
                <i class="ti ti-refresh" style="font-size: 15px;" aria-hidden="true"></i> Refresh reference data
            </button>
        </form>
        <a href="{{ route('families.index') }}" class="btn btn-secondary">
            <i class="ti ti-users" style="font-size: 15px;" aria-hidden="true"></i> View registered families
        </a>
    </div>
@endsection
