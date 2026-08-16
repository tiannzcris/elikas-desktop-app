@extends('layouts.app')

@section('title', 'All Evacuees')
@section('nav-evacuees', 'active')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-brand mb-1">All Evacuees</h1>
            <p class="text-sm text-gray-500">
                @if ($search !== '')
                    Showing results for "{{ $search }}" &middot; <a href="{{ route('evacuees.index') }}" class="text-brand underline">clear filter</a>
                @else
                    Full roster from the central server, cached here for offline viewing.
                @endif
            </p>
        </div>
        <a href="{{ route('evacuees.index') }}" class="flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-sm font-medium rounded-lg px-4 py-2.5">
            <i class="ti ti-refresh" style="font-size: 15px;" aria-hidden="true"></i> Refresh
        </a>
    </div>

    @if ($lastSyncedAt)
        <div class="bg-white border border-gray-200 rounded-xl p-4 mb-6">
            <div class="flex items-center gap-2 mb-2">
                <div class="w-7 h-7 rounded-md flex items-center justify-center shrink-0" style="background: {{ $isStale ? '#FEF3C7' : '#DCFCE7' }};">
                    <i class="ti ti-{{ $isStale ? 'cloud-off' : 'cloud-check' }}" style="font-size: 15px; color: {{ $isStale ? '#D97706' : '#178A43' }};" aria-hidden="true"></i>
                </div>
                <p class="text-sm font-medium">Showing data as of {{ $lastSyncedAt->format('M j, Y g:i A') }}</p>
            </div>
            @if ($isStale)
                <p class="text-xs text-amber-600 mt-2 flex items-center gap-1.5">
                    <i class="ti ti-alert-circle" style="font-size: 14px;" aria-hidden="true"></i>
                    Couldn't reach the central server -- showing the last successfully synced data instead.
                </p>
            @endif
        </div>
    @endif

    @if ($evacuees->isEmpty())
        <div class="flex flex-col items-center text-center py-16">
            <i class="ti ti-users text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
            <p class="text-sm text-gray-400">
                @if ($search !== '')
                    No cached match for "{{ $search }}".
                @elseif ($isStale)
                    No cached evacuee data yet -- connect to the internet at least once to load it.
                @else
                    No evacuees registered yet.
                @endif
            </p>
        </div>
    @else
        <div class="flex flex-col gap-3">
            @foreach ($evacuees as $record)
                <div class="bg-white border border-gray-200 rounded-xl p-4">
                    <p class="font-medium text-sm">{{ $record->head_name ?? 'Unnamed family' }}</p>
                    <p class="text-xs text-gray-500">
                        {{ $record->barangay_name ?? 'Unknown barangay' }} &middot;
                        {{ $record->member_count }} member(s)
                    </p>
                </div>
            @endforeach
        </div>
    @endif
@endsection
