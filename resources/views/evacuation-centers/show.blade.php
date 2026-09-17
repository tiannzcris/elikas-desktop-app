@extends('layouts.app')

@section('title', $center->name)
@section('nav-evacuation-centers', 'active')

@section('content')
    <a href="{{ route('evacuation-centers.index') }}" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1 mb-4">
        <i class="ti ti-arrow-left" style="font-size: 12px;" aria-hidden="true"></i> All centers
    </a>

    {{-- Prominent, first thing on the page -- staff arriving here to add an
         evacuee (the common case during an active disaster) should
         immediately see where to go. EC Board itself now lives on its own
         dedicated page (see ec-board.blade.php) so this page can stay
         focused on the center's own basic/static info, matching the exact
         same split just done on the web dashboard. --}}
    <a href="{{ route('evacuation-centers.ec-board', $center) }}" class="flex items-center justify-between bg-blue-50 border border-brand/30 rounded-2xl p-4 mb-6 hover:border-brand group">
        <div class="flex items-center gap-2.5">
            <div class="w-9 h-9 rounded-lg bg-white flex items-center justify-center shrink-0">
                <i class="ti ti-clipboard-list text-brand" style="font-size: 18px;" aria-hidden="true"></i>
            </div>
            <div>
                <p class="text-sm font-bold text-gray-800">EC Information Board</p>
                <p class="text-xs text-gray-500">Live headcount, age/sex breakdown, and "Add Evacuee"</p>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @if ($pendingCount > 0)
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700">{{ $pendingCount }} pending</span>
            @endif
            <i class="ti ti-chevron-right text-brand group-hover:translate-x-0.5" style="font-size: 18px;" aria-hidden="true"></i>
        </div>
    </a>

    <div class="card-modern p-4">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-xl font-bold text-brand mb-1">{{ $center->name }}</h1>
                <p class="text-sm text-gray-500">{{ $barangayName }}</p>
            </div>
            <span class="text-xs font-semibold px-2.5 py-1 rounded-full shrink-0
                {{ $center->status === 'active' ? 'bg-green-50 text-green-700' : ($center->status === 'full' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                {{ \Illuminate\Support\Str::headline($center->status) }}
            </span>
        </div>

        {{-- This device only ever caches a center's name/barangay/status --
             address, capacity, camp manager, and the facilities checklist
             (all shown on the web dashboard's own basic-info page) are not
             part of this app's reference-data cache yet, so there is
             nothing further to show here honestly rather than fabricate
             placeholder values for them. --}}
        <p class="text-xs text-gray-400 mt-4 border-t border-gray-100 pt-3">
            Address, capacity, camp manager, and facilities details aren't cached on this device yet -- those are only available on the web dashboard while online.
        </p>
    </div>
@endsection
