@extends('layouts.app')

@section('title', 'Registered families')
@section('nav-families', 'active')

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-brand mb-1">Registered families</h1>
            <p class="text-sm text-gray-500">Everything registered on this device, synced or not.</p>
        </div>
        <div class="flex items-start gap-3">
            <form method="POST" action="{{ route('families.sync') }}">
                @csrf
                <button type="submit" class="btn-modern flex items-center gap-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-sm text-gray-700 px-4 py-2.5">
                    <i class="ti ti-cloud-upload" style="font-size: 15px;" aria-hidden="true"></i> Sync now
                </button>
            </form>
            <div>
                {{-- De-emphasized on purpose -- EC Board's "Add evacuee" is
                     now the primary, fast-entry path for someone physically
                     at a center (see the sidebar's "EC Board" nav item).
                     This stays fully functional for the cases it's still
                     the right tool for -- see the helper text below. --}}
                <a href="{{ route('families.create') }}" data-modal-trigger="register-family" class="btn-modern flex items-center gap-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-sm text-gray-700 px-4 py-2.5">
                    <i class="ti ti-user-plus" style="font-size: 15px;" aria-hidden="true"></i> Register a family
                </a>
                <p class="text-xs text-gray-400 mt-1 max-w-[220px]">For households outside a center, or full detailed registration directly</p>
            </div>
        </div>
    </div>

    {{-- Stays visible on every drill-down level (and search results) --
         a failed sync is exactly the kind of thing that must never
         require extra navigation to even notice. The flat list this page
         used to be showed every family's sync_error directly on its own
         card; this replaces that visibility, not removes it. --}}
    @if ($syncErrors->isNotEmpty())
        <div class="bg-red-50 border border-red-100 rounded-2xl p-4 mb-6">
            <p class="text-sm font-bold text-red-700 mb-2 flex items-center gap-1.5">
                <i class="ti ti-alert-triangle" style="font-size: 15px;" aria-hidden="true"></i>
                {{ $syncErrors->count() }} {{ Str::plural('family', $syncErrors->count()) }} failed to sync
            </p>
            <div class="flex flex-col gap-1.5">
                @foreach ($syncErrors as $errored)
                    <a href="{{ route('families.index', ['barangay' => $errored->barangay_id, 'center' => $errored->evacuation_center_id ?? 'none']) }}" class="text-xs text-red-600 hover:underline">
                        {{ $errored->barangay->name ?? 'Unknown barangay' }} &middot; {{ $errored->evacuationCenter->name ?? 'Outside center / unassigned' }}: {{ $errored->sync_error }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Global search: independent of the barangay -> center -> family
         drill-down below -- finds a family by any member's name no matter
         which barangay/center they're actually in, matching the web
         dashboard's own "family reunification lookups never get slower
         because of the drill-down" principle. --}}
    <form method="GET" action="{{ route('families.index') }}" class="relative mb-6">
        <i class="ti ti-search absolute left-3 top-1/2 text-gray-400" style="font-size: 15px; transform: translateY(-50%);" aria-hidden="true"></i>
        <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Search any family by member name, regardless of barangay or center..." class="w-full border border-gray-200 rounded-xl pl-9 pr-3 py-2.5 text-sm">
    </form>

    @if (($view ?? null) === 'search')
        <div class="flex items-center justify-between mb-4">
            <p class="text-sm text-gray-500">
                {{ $families->count() }} result(s) for "<span class="font-medium text-gray-700">{{ $search }}</span>"
            </p>
            <a href="{{ route('families.index') }}" class="text-xs text-brand hover:underline">Clear search</a>
        </div>

        @include('families._family_cards', ['families' => $families, 'emptyMessage' => 'No family matches that name.'])
    @else
        {{-- Breadcrumb: "All barangays" is always clickable to jump back to
             the landing view; the current level's own label is plain text. --}}
        <nav class="flex items-center gap-1.5 text-sm text-gray-500 mb-4">
            @if (($view ?? null) === 'barangay')
                <span class="font-medium text-gray-700">All barangays</span>
            @else
                <a href="{{ route('families.index') }}" class="hover:text-brand hover:underline">All barangays</a>
            @endif

            @isset($barangay)
                <i class="ti ti-chevron-right" style="font-size: 12px;" aria-hidden="true"></i>
                @if (($view ?? null) === 'center')
                    <span class="font-medium text-gray-700">{{ $barangay->name }}</span>
                @else
                    <a href="{{ route('families.index', ['barangay' => $barangay->id]) }}" class="hover:text-brand hover:underline">{{ $barangay->name }}</a>
                @endif
            @endisset

            @if (($view ?? null) === 'family')
                <i class="ti ti-chevron-right" style="font-size: 12px;" aria-hidden="true"></i>
                <span class="font-medium text-gray-700">{{ $center->name ?? 'Outside center / unassigned' }}</span>
            @endif
        </nav>

        @if (($view ?? null) === 'barangay')
            @if ($barangaySummary->isEmpty())
                <div class="flex flex-col items-center text-center py-16">
                    <i class="ti ti-users text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
                    <p class="text-sm text-gray-400">No families registered on this device yet.</p>
                </div>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($barangaySummary as $row)
                        @php($ecBoardPending = $ecBoardPendingByBarangay[$row->barangay->remote_id ?? null] ?? 0)
                        <a href="{{ route('families.index', ['barangay' => $row->barangay_id]) }}" class="card-modern p-4 flex items-center justify-between hover:shadow-md transition-shadow">
                            <div>
                                <p class="font-bold text-sm text-gray-800">{{ $row->barangay->name ?? 'Unknown barangay' }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ $row->family_count }} {{ Str::plural('family', $row->family_count) }}</p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                @if ($ecBoardPending > 0)
                                    {{-- Aggregate only at this level (spans possibly
                                         several centers within the barangay) -- not a
                                         link itself, since there's no single EC Board
                                         page to jump to yet; drilling into the barangay
                                         below shows exactly which center(s). --}}
                                    <span class="flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700">
                                        <i class="ti ti-clipboard-list" style="font-size: 12px;" aria-hidden="true"></i>
                                        {{ $ecBoardPending }} EC Board pending
                                    </span>
                                @endif
                                <i class="ti ti-chevron-right text-gray-400" style="font-size: 18px;" aria-hidden="true"></i>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        @elseif (($view ?? null) === 'center')
            @if ($centerSummary->isEmpty())
                <div class="flex flex-col items-center text-center py-16">
                    <i class="ti ti-building-community text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
                    <p class="text-sm text-gray-400">No families registered in {{ $barangay->name }} yet.</p>
                </div>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($centerSummary as $row)
                        @php($ecBoardPending = $row->evacuation_center_id ? ($ecBoardPendingByCenter[$row->evacuation_center_id] ?? 0) : 0)
                        <div class="card-modern p-4 flex items-center justify-between hover:shadow-md transition-shadow">
                            <a href="{{ route('families.index', ['barangay' => $barangay->id, 'center' => $row->evacuation_center_id ?? 'none']) }}" class="flex-1 flex items-center justify-between min-w-0">
                                <div>
                                    <p class="font-bold text-sm text-gray-800">{{ $row->evacuationCenter->name ?? 'Outside center / unassigned' }}</p>
                                    <p class="text-xs text-gray-500 mt-0.5">{{ $row->family_count }} {{ Str::plural('family', $row->family_count) }}</p>
                                </div>
                            </a>
                            <div class="flex items-center gap-2 shrink-0 ml-3">
                                {{-- A separate link, not nested inside the card's own
                                     link above -- this one goes straight to the EC
                                     Board page where these pending entries actually
                                     live and can be managed, not to this drill-down's
                                     own family list (which will never show them). --}}
                                @if ($ecBoardPending > 0)
                                    <a href="{{ route('evacuation-centers.ec-board', $row->evacuation_center_id) }}" class="flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 hover:bg-amber-100">
                                        <i class="ti ti-clipboard-list" style="font-size: 12px;" aria-hidden="true"></i>
                                        {{ $ecBoardPending }} EC Board pending
                                    </a>
                                @endif
                                <i class="ti ti-chevron-right text-gray-400" style="font-size: 18px;" aria-hidden="true"></i>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @else
            @include('families._family_cards', ['families' => $families, 'emptyMessage' => 'No families here yet.'])
        @endif
    @endif
@endsection
