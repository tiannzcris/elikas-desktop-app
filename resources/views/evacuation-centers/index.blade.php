@extends('layouts.app')

@section('title', 'Evacuation Centers')
@section('nav-evacuation-centers', 'active')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-brand mb-1">Evacuation Centers</h1>
        <p class="text-sm text-gray-500">Pick a center to add evacuees or review its headcount breakdown.</p>
    </div>

    @if ($centers->isEmpty())
        <div class="flex flex-col items-center text-center py-16">
            <i class="ti ti-building-community text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
            <p class="text-sm text-gray-400">No evacuation centers cached on this device yet -- refresh reference data while online.</p>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3">
            @foreach ($centers as $row)
                <a href="{{ route('evacuation-centers.show', $row['center']) }}" class="card-modern p-4 hover:shadow-md transition-shadow">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-bold text-sm text-gray-800">{{ $row['center']->name }}</p>
                            <p class="text-xs text-gray-500 mt-0.5">{{ $row['barangayName'] }}</p>
                        </div>
                        <span class="text-xs font-semibold px-2.5 py-1 rounded-full shrink-0
                            {{ $row['center']->status === 'active' ? 'bg-green-50 text-green-700' : ($row['center']->status === 'full' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                            {{ \Illuminate\Support\Str::headline($row['center']->status) }}
                        </span>
                    </div>
                    @if ($row['pendingCount'] > 0)
                        <p class="text-xs text-amber-600 font-semibold mt-3 flex items-center gap-1">
                            <i class="ti ti-clock" style="font-size: 12px;" aria-hidden="true"></i>
                            {{ $row['pendingCount'] }} evacuee(s) waiting to sync
                        </p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
@endsection
