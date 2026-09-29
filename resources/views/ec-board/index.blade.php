@extends('layouts.app')

@section('title', 'EC Board')
@section('nav-ec-board', 'active')

@section('content')
    <div class="page-header">
        <div>
            <h1 class="page-title">EC Board</h1>
            <p class="page-subtitle">Pick a barangay, then a center, to open its live headcount board.</p>
        </div>
        {{-- The old center-management pages (create/edit centers' basic
             info) aren't a top-level nav item anymore now that EC Board
             has taken that sidebar slot -- still fully reachable here. --}}
        <a href="{{ route('evacuation-centers.index') }}" class="btn btn-sm btn-ghost shrink-0">
            <i class="ti ti-settings" style="font-size: 14px;" aria-hidden="true"></i> Manage centers
        </a>
    </div>

    @if ($rows->isEmpty())
        <div class="empty-state">
            <i class="ti ti-building-community" aria-hidden="true"></i>
            <p>No evacuation centers cached on this device yet -- refresh reference data while online.</p>
        </div>
    @else
        <p class="callout callout-info text-xs mb-4">
            Your barangay is shown first. Other barangays are included so you can help register displaced residents temporarily staying in your area, or view city-wide activity.
        </p>
        <div class="grid grid-cols-2 gap-3">
            @foreach ($rows as $row)
                <a href="{{ route('ec-board.centers', $row['barangay']) }}" class="card card-link p-4 flex items-center justify-between {{ $row['isOwnBarangay'] ? 'border-brand-200' : '' }}">
                    <div class="flex items-center gap-2.5">
                        <div class="icon-chip">
                            <i class="ti ti-map-pin" style="font-size: 16px;" aria-hidden="true"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-sm text-gray-900 flex items-center gap-1.5">
                                {{ $row['barangay']->name }}
                                @if ($row['isOwnBarangay'])
                                    <span class="badge badge-info">Your barangay</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-600">{{ $row['centerCount'] }} center{{ $row['centerCount'] === 1 ? '' : 's' }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        @if ($row['pendingCount'] > 0)
                            <span class="badge badge-warning">{{ $row['pendingCount'] }} pending</span>
                        @endif
                        <i class="ti ti-chevron-right text-gray-500" style="font-size: 16px;" aria-hidden="true"></i>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
