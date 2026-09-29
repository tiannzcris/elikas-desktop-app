@extends('layouts.app')

@section('title', $barangay->name.' -- EC Board')
@section('nav-ec-board', 'active')

@section('content')
    <a href="{{ route('ec-board.index') }}" class="back-link">
        <i class="ti ti-arrow-left" style="font-size: 14px;" aria-hidden="true"></i> All barangays
    </a>

    <div class="page-header">
        <div>
            <h1 class="page-title">{{ $barangay->name }}</h1>
            <p class="page-subtitle">Pick a center to open its live headcount board.</p>
        </div>
    </div>

    @if ($centers->isEmpty())
        <div class="empty-state">
            <i class="ti ti-building-community" aria-hidden="true"></i>
            <p>No evacuation centers cached for this barangay.</p>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3">
            @foreach ($centers as $row)
                <div class="card p-4">
                    <div class="flex items-start justify-between">
                        <a href="{{ route('evacuation-centers.ec-board', $row['center']) }}" class="flex-1 min-w-0">
                            <p class="font-semibold text-sm text-gray-900 hover:underline underline-offset-2">{{ $row['center']->name }}</p>
                        </a>
                        <span class="badge shrink-0
                            {{ $row['center']->status === 'active' ? 'badge-success' : ($row['center']->status === 'full' ? 'badge-danger' : 'badge-neutral') }}">
                            {{ \Illuminate\Support\Str::headline($row['center']->status) }}
                        </span>
                    </div>
                    @if ($row['pendingCount'] > 0)
                        <p class="text-xs text-amber-800 font-medium mt-2 flex items-center gap-1">
                            <i class="ti ti-clock" style="font-size: 12px;" aria-hidden="true"></i>
                            {{ $row['pendingCount'] }} evacuee(s) waiting to sync
                        </p>
                    @endif
                    <div class="flex items-center gap-3 mt-3 pt-3 border-t border-gray-100">
                        <a href="{{ route('evacuation-centers.ec-board', $row['center']) }}" class="link text-sm flex items-center gap-1">
                            Open EC Board <i class="ti ti-chevron-right" style="font-size: 12px;" aria-hidden="true"></i>
                        </a>
                        <a href="{{ route('evacuation-centers.show', $row['center']) }}" class="text-sm text-gray-600 hover:text-gray-900 hover:underline underline-offset-2">
                            Center details
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
