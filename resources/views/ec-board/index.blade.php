@extends('layouts.app')

@section('title', 'EC Board')
@section('nav-ec-board', 'active')

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-brand mb-1">EC Board</h1>
            <p class="text-sm text-gray-500">Pick a barangay, then a center, to open its live headcount board.</p>
        </div>
        {{-- The old center-management pages (create/edit centers' basic
             info) aren't a top-level nav item anymore now that EC Board
             has taken that sidebar slot -- still fully reachable here. --}}
        <a href="{{ route('evacuation-centers.index') }}" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1 shrink-0 mt-1">
            <i class="ti ti-settings" style="font-size: 13px;" aria-hidden="true"></i> Manage centers
        </a>
    </div>

    @if ($rows->isEmpty())
        <div class="flex flex-col items-center text-center py-16">
            <i class="ti ti-building-community text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
            <p class="text-sm text-gray-400">No evacuation centers cached on this device yet -- refresh reference data while online.</p>
        </div>
    @else
        <p class="text-xs text-gray-500 bg-blue-50 border border-blue-100 rounded-xl p-3 mb-4">
            Your barangay is shown first. Other barangays are included so you can help register displaced residents temporarily staying in your area, or view city-wide activity.
        </p>
        <div class="grid grid-cols-2 gap-3">
            @foreach ($rows as $row)
                <a href="{{ route('ec-board.centers', $row['barangay']) }}" class="card-modern p-4 hover:shadow-md transition-shadow flex items-center justify-between {{ $row['isOwnBarangay'] ? 'ring-1 ring-brand/40' : '' }}">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center shrink-0">
                            <i class="ti ti-map-pin text-brand" style="font-size: 16px;" aria-hidden="true"></i>
                        </div>
                        <div>
                            <p class="font-bold text-sm text-gray-800 flex items-center gap-1.5">
                                {{ $row['barangay']->name }}
                                @if ($row['isOwnBarangay'])
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-brand bg-blue-50 rounded-full px-2 py-0.5">Your barangay</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-400">{{ $row['centerCount'] }} center{{ $row['centerCount'] === 1 ? '' : 's' }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        @if ($row['pendingCount'] > 0)
                            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700">{{ $row['pendingCount'] }} pending</span>
                        @endif
                        <i class="ti ti-chevron-right text-gray-300" style="font-size: 16px;" aria-hidden="true"></i>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
