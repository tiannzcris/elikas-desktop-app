@extends('layouts.app')

@section('title', $center->name)

@section('content')
    <a href="{{ route('evacuation-centers.index') }}" class="back-link">
        <i class="ti ti-arrow-left" style="font-size: 14px;" aria-hidden="true"></i> All centers
    </a>

    {{-- Prominent, first thing on the page -- staff arriving here to add an
         evacuee (the common case during an active disaster) should
         immediately see where to go. EC Board itself now lives on its own
         dedicated page (see ec-board.blade.php) so this page can stay
         focused on the center's own basic/static info, matching the exact
         same split just done on the web dashboard. --}}
    <a href="{{ route('evacuation-centers.ec-board', $center) }}" class="card card-link flex items-center justify-between border-brand-200 bg-brand-50 p-4 mb-6 hover:border-brand hover:bg-brand-50 group">
        <div class="flex items-center gap-2.5">
            <div class="icon-chip bg-white">
                <i class="ti ti-clipboard-list" style="font-size: 18px;" aria-hidden="true"></i>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-900">EC Information Board</p>
                <p class="text-xs text-gray-600">Live headcount, age/sex breakdown, and "Add Evacuee"</p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @if ($pendingCount > 0)
                <span class="badge badge-warning">{{ $pendingCount }} pending</span>
            @endif
            <i class="ti ti-chevron-right text-brand-700 group-hover:translate-x-0.5 transition-transform" style="font-size: 18px;" aria-hidden="true"></i>
        </div>
    </a>

    <div class="card p-5">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="page-title">{{ $center->name }}</h1>
                <p class="page-subtitle">{{ $barangayName }}</p>
            </div>
            <span class="badge shrink-0
                {{ $center->status === 'active' ? 'badge-success' : ($center->status === 'full' ? 'badge-danger' : 'badge-neutral') }}">
                {{ \Illuminate\Support\Str::headline($center->status) }}
            </span>
        </div>

        {{-- This device only ever caches a center's name/barangay/status --
             address, capacity, camp manager, and the facilities checklist
             (all shown on the web dashboard's own basic-info page) are not
             part of this app's reference-data cache yet, so there is
             nothing further to show here honestly rather than fabricate
             placeholder values for them. --}}
        <p class="text-xs text-gray-600 mt-4 border-t border-gray-100 pt-3">
            Address, capacity, camp manager, and facilities details aren't cached on this device yet -- those are only available on the web dashboard while online.
        </p>
    </div>
@endsection
