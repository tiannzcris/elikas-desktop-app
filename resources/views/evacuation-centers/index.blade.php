@extends('layouts.app')

@section('title', 'Evacuation Centers')

@section('content')
    <div class="page-header">
        <div>
            <h1 class="page-title">Evacuation Centers</h1>
            <p class="page-subtitle">Pick a center to add evacuees or review its headcount breakdown.</p>
        </div>
    </div>

    @if ($centersByBarangay->isEmpty())
        <div class="empty-state">
            <i class="ti ti-building-community" aria-hidden="true"></i>
            <p>No evacuation centers cached on this device yet -- refresh reference data while online.</p>
        </div>
    @else
        {{-- Grouped by barangay -- simpler than the full barangay -> center
             -> family drill-down Registered Families uses, since this list
             never needs to go past barangay -> centers -> one center's own
             detail page (which already exists). --}}
        <div class="flex flex-col gap-6">
            @foreach ($centersByBarangay as $barangayName => $rows)
                <div>
                    <p class="group-label">
                        {{ $barangayName }} <span class="text-gray-500 font-normal">({{ $rows->count() }})</span>
                    </p>
                    <div class="grid grid-cols-2 gap-3">
                        @foreach ($rows as $row)
                            <a href="{{ route('evacuation-centers.show', $row['center']) }}" class="card card-link p-4">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <p class="font-semibold text-sm text-gray-900">{{ $row['center']->name }}</p>
                                    </div>
                                    <span class="badge shrink-0
                                        {{ $row['center']->status === 'active' ? 'badge-success' : ($row['center']->status === 'full' ? 'badge-danger' : 'badge-neutral') }}">
                                        {{ \Illuminate\Support\Str::headline($row['center']->status) }}
                                    </span>
                                </div>
                                @if ($row['pendingCount'] > 0)
                                    <p class="text-xs text-amber-800 font-medium mt-3 flex items-center gap-1">
                                        <i class="ti ti-clock" style="font-size: 12px;" aria-hidden="true"></i>
                                        {{ $row['pendingCount'] }} evacuee(s) waiting to sync
                                    </p>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
