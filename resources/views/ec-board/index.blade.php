@extends('layouts.app')

@section('title', 'EC Board')
@section('nav-ec-board', 'active')

@section('content')
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">EC Board</h1>
            @if ($barangayName)
                <p class="page-subtitle">Evacuation centers of Barangay {{ $barangayName }}</p>
            @endif
        </div>
    </div>

    @if ($centers->isEmpty())
        <div class="card">
            <div class="empty-state">
                <i class="ti ti-building-community" aria-hidden="true"></i>
                <p>No evacuation centers on this device yet.</p>
                <form method="POST" action="{{ route('reference-data.refresh') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Refresh reference data</button>
                </form>
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($centers as $row)
                <a href="{{ route('evacuation-centers.ec-board', $row['center']) }}" class="card card-link card-body flex flex-col gap-2" data-center-card>
                    <div class="flex items-start justify-between gap-2">
                        <p class="card-title min-w-0"><x-center-name :center="$row['center']" /></p>
                        <span class="badge shrink-0 {{ $row['center']->status === 'active' ? 'badge-success' : ($row['center']->status === 'full' ? 'badge-danger' : 'badge-neutral') }}">
                            {{ \Illuminate\Support\Str::headline($row['center']->status) }}
                        </span>
                    </div>
                    @if ($row['pendingCount'] > 0)
                        <p class="text-sm text-amber-800 font-medium">{{ $row['pendingCount'] }} waiting to sync</p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
@endsection
