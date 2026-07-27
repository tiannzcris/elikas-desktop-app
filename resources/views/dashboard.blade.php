@extends('layouts.app')

@section('title', 'Dashboard')
@section('nav-dashboard', 'active')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold mb-1">Offline companion dashboard</h1>
        <p class="text-sm text-gray-500">Logged in as {{ $currentUser->name }} &middot; {{ $currentUser->barangay_name ?? 'City-wide access' }}</p>
    </div>

    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl p-4 flex items-center justify-between" style="border-left: 4px solid #3B82F6;">
            <div>
                <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Barangays</p>
                <p class="text-2xl font-bold text-gray-800">{{ $barangayCount }}</p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center shrink-0">
                <i class="ti ti-map-pin text-blue-500" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
        </div>
        <div class="bg-white rounded-xl p-4 flex items-center justify-between" style="border-left: 4px solid #22C55E;">
            <div>
                <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Active events</p>
                <p class="text-2xl font-bold text-gray-800">{{ $eventCount }}</p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center shrink-0">
                <i class="ti ti-alert-triangle text-green-500" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
        </div>
        <div class="bg-white rounded-xl p-4 flex items-center justify-between" style="border-left: 4px solid #A855F7;">
            <div>
                <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Evacuation centers</p>
                <p class="text-2xl font-bold text-gray-800">{{ $centerCount }}</p>
            </div>
            <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center shrink-0">
                <i class="ti ti-building text-purple-500" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl p-4 mb-6">
        <div class="flex items-center gap-2 mb-2">
            <div class="w-7 h-7 rounded-md bg-amber-50 flex items-center justify-center shrink-0">
                <i class="ti ti-cloud-upload text-amber-500" style="font-size: 15px;" aria-hidden="true"></i>
            </div>
            <p class="text-sm font-medium">Registration sync status</p>
        </div>
        <div class="flex gap-6 text-sm">
            <p><span class="font-semibold text-amber-600">{{ $pendingSyncCount }}</span> <span class="text-gray-500">waiting to sync</span></p>
            <p><span class="font-semibold text-green-600">{{ $syncedCount }}</span> <span class="text-gray-500">already synced</span></p>
        </div>
        @if ($barangayCount === 0)
            <p class="text-xs text-amber-600 mt-2 flex items-center gap-1.5">
                <i class="ti ti-alert-circle" style="font-size: 14px;" aria-hidden="true"></i>
                No reference data cached yet -- you'll need internet at least once to refresh before registering families.
            </p>
        @endif
    </div>

    <div class="flex gap-3">
        <form method="POST" action="{{ route('reference-data.refresh') }}">
            @csrf
            <button type="submit" class="flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-sm font-medium rounded-lg px-4 py-2.5">
                <i class="ti ti-refresh" style="font-size: 15px;" aria-hidden="true"></i> Refresh reference data
            </button>
        </form>
        <a href="{{ route('families.index') }}" class="flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-sm font-medium rounded-lg px-4 py-2.5">
            <i class="ti ti-users" style="font-size: 15px;" aria-hidden="true"></i> View registered families
        </a>
        <a href="{{ route('families.create') }}" class="flex items-center gap-1.5 bg-brand hover:bg-brand-dark text-white text-sm font-medium rounded-lg px-4 py-2.5">
            <i class="ti ti-user-plus" style="font-size: 15px;" aria-hidden="true"></i> Register a family
        </a>
    </div>
@endsection
