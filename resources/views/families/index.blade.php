@extends('layouts.app')

@section('title', 'Registered families')
@section('nav-families', 'active')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold mb-1">Registered families</h1>
            <p class="text-sm text-gray-500">Everything registered on this device, synced or not.</p>
        </div>
        <div class="flex gap-3">
            <form method="POST" action="{{ route('families.sync') }}">
                @csrf
                <button type="submit" class="flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-sm font-medium rounded-lg px-4 py-2.5">
                    <i class="ti ti-cloud-upload" style="font-size: 15px;" aria-hidden="true"></i> Sync now
                </button>
            </form>
            <a href="{{ route('families.create') }}" class="flex items-center gap-1.5 bg-brand hover:bg-brand-dark text-white text-sm font-medium rounded-lg px-4 py-2.5">
                <i class="ti ti-user-plus" style="font-size: 15px;" aria-hidden="true"></i> Register a family
            </a>
        </div>
    </div>

    @if ($families->isEmpty())
        <div class="flex flex-col items-center text-center py-16">
            <i class="ti ti-users text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
            <p class="text-sm text-gray-400">No families registered on this device yet.</p>
        </div>
    @else
        <div class="flex flex-col gap-3">
            @foreach ($families as $family)
                <div class="bg-white border border-gray-200 rounded-xl p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-medium text-sm">{{ $family->barangay->name ?? 'Unknown barangay' }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $family->evacuationEvent->name ?? '' }} &middot;
                                {{ $family->evacuees->count() }} member(s) &middot;
                                {{ $family->displacement_type === 'inside_center' ? 'Inside center' : 'Outside center' }}
                            </p>
                            <p class="text-xs text-gray-400 mt-1">Registered {{ $family->created_at->format('M j, Y g:i A') }}</p>
                        </div>
                        @if ($family->synced_at)
                            <span class="flex items-center gap-1 text-xs px-2 py-1 rounded-lg bg-green-50 text-green-700">
                                <i class="ti ti-check" style="font-size: 12px;" aria-hidden="true"></i> Synced
                            </span>
                        @else
                            <span class="flex items-center gap-1 text-xs px-2 py-1 rounded-lg bg-amber-50 text-amber-700">
                                <i class="ti ti-clock" style="font-size: 12px;" aria-hidden="true"></i> Waiting to sync
                            </span>
                        @endif
                    </div>
                    @if ($family->sync_error)
                        <p class="text-xs text-red-500 mt-2 border-t border-gray-100 pt-2">{{ $family->sync_error }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endsection
