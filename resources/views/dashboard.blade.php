@extends('layouts.app')

@section('title', 'Dashboard')
@section('nav-dashboard', 'active')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-extrabold text-gray-800 mb-1">Welcome back, {{ $currentUser->name }}!</h1>
        <p class="text-sm text-gray-500">{{ $currentUser->barangay_name ?? 'City-wide access' }} &middot; Here's what's registered on this device.</p>
    </div>

    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="card-modern card-hover p-5">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 mb-3" style="background: linear-gradient(135deg, #3B82F6, #2563EB); box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);">
                <i class="ti ti-map-pin text-white" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
            <p class="text-2xl font-extrabold text-gray-800">{{ $barangayCount }}</p>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mt-0.5">Barangays</p>
        </div>
        <div class="card-modern card-hover p-5">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 mb-3" style="background: linear-gradient(135deg, #22B563, #178A43); box-shadow: 0 4px 10px rgba(23, 138, 67, 0.25);">
                <i class="ti ti-alert-triangle text-white" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
            <p class="text-2xl font-extrabold text-gray-800">{{ $eventCount }}</p>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mt-0.5">Active events</p>
        </div>
        <div class="card-modern card-hover p-5">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 mb-3" style="background: linear-gradient(135deg, #A855F7, #9333EA); box-shadow: 0 4px 10px rgba(147, 51, 234, 0.25);">
                <i class="ti ti-building text-white" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
            <p class="text-2xl font-extrabold text-gray-800">{{ $centerCount }}</p>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mt-0.5">Evacuation centers</p>
        </div>
        <div class="card-modern card-hover p-5">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 mb-3" style="background: linear-gradient(135deg, #E63946, #C8102E); box-shadow: 0 4px 10px rgba(200, 16, 46, 0.25);">
                <i class="ti ti-cloud-upload text-white" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
            <p class="text-2xl font-extrabold text-gray-800">{{ $pendingSyncCount }}</p>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mt-0.5">Pending sync</p>
        </div>
    </div>

    <div class="card-modern p-5 mb-6">
        <div class="flex items-center gap-2.5 mb-3">
            <div class="w-8 h-8 rounded-xl bg-amber-50 flex items-center justify-center shrink-0">
                <i class="ti ti-cloud-upload text-amber-500" style="font-size: 16px;" aria-hidden="true"></i>
            </div>
            <p class="text-sm font-bold text-gray-800">Registration sync status</p>
        </div>
        <div class="flex gap-6 text-sm">
            <p><span class="font-bold text-amber-600">{{ $pendingSyncCount }}</span> <span class="text-gray-500">waiting to sync</span></p>
            <p><span class="font-bold text-green-600">{{ $syncedCount }}</span> <span class="text-gray-500">already synced</span></p>
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
            <button type="submit" class="btn-modern flex items-center gap-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-sm text-gray-700 px-4 py-2.5">
                <i class="ti ti-refresh" style="font-size: 15px;" aria-hidden="true"></i> Refresh reference data
            </button>
        </form>
        <a href="{{ route('families.index') }}" class="btn-modern flex items-center gap-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-sm text-gray-700 px-4 py-2.5">
            <i class="ti ti-users" style="font-size: 15px;" aria-hidden="true"></i> View registered families
        </a>
        <a href="{{ route('families.create') }}" data-modal-trigger="register-family" class="btn-modern btn-primary-modern flex items-center gap-1.5 bg-brand hover:bg-brand-dark text-white text-sm px-4 py-2.5">
            <i class="ti ti-user-plus" style="font-size: 15px;" aria-hidden="true"></i> Register a family
        </a>
    </div>
@endsection
