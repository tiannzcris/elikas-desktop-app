@extends('layouts.app')

@section('title', $barangay->name.' -- EC Board')
@section('nav-ec-board', 'active')

@section('content')
    <a href="{{ route('ec-board.index') }}" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1 mb-4">
        <i class="ti ti-arrow-left" style="font-size: 12px;" aria-hidden="true"></i> All barangays
    </a>

    <div class="mb-6">
        <h1 class="text-xl font-bold text-brand mb-1">{{ $barangay->name }}</h1>
        <p class="text-sm text-gray-500">Pick a center to open its live headcount board.</p>
    </div>

    @if ($centers->isEmpty())
        <div class="flex flex-col items-center text-center py-16">
            <i class="ti ti-building-community text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
            <p class="text-sm text-gray-400">No evacuation centers cached for this barangay.</p>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3">
            @foreach ($centers as $row)
                <div class="card-modern p-4 hover:shadow-md transition-shadow">
                    <div class="flex items-start justify-between">
                        <a href="{{ route('evacuation-centers.ec-board', $row['center']) }}" class="flex-1 min-w-0">
                            <p class="font-bold text-sm text-gray-800">{{ $row['center']->name }}</p>
                        </a>
                        <span class="text-xs font-semibold px-2.5 py-1 rounded-full shrink-0
                            {{ $row['center']->status === 'active' ? 'bg-green-50 text-green-700' : ($row['center']->status === 'full' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                            {{ \Illuminate\Support\Str::headline($row['center']->status) }}
                        </span>
                    </div>
                    @if ($row['pendingCount'] > 0)
                        <p class="text-xs text-amber-600 font-semibold mt-2 flex items-center gap-1">
                            <i class="ti ti-clock" style="font-size: 12px;" aria-hidden="true"></i>
                            {{ $row['pendingCount'] }} evacuee(s) waiting to sync
                        </p>
                    @endif
                    <div class="flex items-center gap-3 mt-3 pt-3 border-t border-gray-100">
                        <a href="{{ route('evacuation-centers.ec-board', $row['center']) }}" class="text-xs font-semibold text-brand hover:text-brand-dark flex items-center gap-1">
                            Open EC Board <i class="ti ti-chevron-right" style="font-size: 12px;" aria-hidden="true"></i>
                        </a>
                        <a href="{{ route('evacuation-centers.show', $row['center']) }}" class="text-xs text-gray-400 hover:text-gray-600">
                            Center details
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
