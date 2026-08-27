@extends('layouts.app')

@section('title', 'All Evacuees')
@section('nav-evacuees', 'active')

@section('content')
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold text-brand mb-1">All Evacuees</h1>
            <p class="text-sm text-gray-500">
                @if ($search !== '' || $barangayFilter !== '')
                    Showing filtered results &middot; <a href="{{ route('evacuees.index') }}" class="text-brand underline">clear filters</a>
                @else
                    Full roster from the central server, cached here for offline viewing.
                @endif
            </p>
        </div>
        <a href="{{ route('evacuees.index') }}" class="btn-modern flex items-center gap-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-sm text-gray-700 px-4 py-2.5">
            <i class="ti ti-refresh" style="font-size: 15px;" aria-hidden="true"></i> Refresh
        </a>
    </div>

    @if ($barangayOptions->count() > 1)
        <form method="GET" action="{{ route('evacuees.index') }}" class="flex items-center gap-3 mb-6">
            <select name="barangay" onchange="this.form.submit()" class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white text-gray-700">
                <option value="">All barangays</option>
                @foreach ($barangayOptions as $b)
                    <option value="{{ $b }}" @selected($barangayFilter === $b)>{{ $b }}</option>
                @endforeach
            </select>
            <div class="relative flex-1 max-w-xs">
                <i class="ti ti-search absolute left-3 top-1/2 text-gray-400" style="font-size: 15px; transform: translateY(-50%);" aria-hidden="true"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search by name" class="w-full border border-gray-200 rounded-xl pl-9 pr-3 py-2.5 text-sm">
            </div>
            <button type="submit" class="btn-modern btn-primary-modern bg-brand hover:bg-brand-dark text-white text-sm px-4 py-2.5">Search</button>
        </form>
    @else
        <form method="GET" action="{{ route('evacuees.index') }}" class="flex items-center gap-3 mb-6">
            <div class="relative flex-1 max-w-xs">
                <i class="ti ti-search absolute left-3 top-1/2 text-gray-400" style="font-size: 15px; transform: translateY(-50%);" aria-hidden="true"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search by name" class="w-full border border-gray-200 rounded-xl pl-9 pr-3 py-2.5 text-sm">
            </div>
            <button type="submit" class="btn-modern btn-primary-modern bg-brand hover:bg-brand-dark text-white text-sm px-4 py-2.5">Search</button>
        </form>
    @endif

    {{-- Always shown when offline/stale, even with nothing cached yet --
         staff should never mistake an empty offline view for "there is
         truly no data" when the real reason is just "never loaded". --}}
    @if ($isStale)
        <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 mb-6">
            <div class="flex items-center gap-2">
                <i class="ti ti-cloud-off text-amber-600" style="font-size: 16px;" aria-hidden="true"></i>
                <p class="text-sm font-medium text-amber-800">This list needs an internet connection to fully update.</p>
            </div>
            <p class="text-xs text-amber-700 mt-1.5">
                @if ($lastSyncedAt)
                    Showing what was last loaded: {{ $lastSyncedAt->format('M j, Y g:i A') }}.
                @else
                    Nothing has been loaded on this device yet -- connect to the internet at least once to load the list.
                @endif
            </p>
        </div>
    @elseif ($lastSyncedAt)
        <div class="card-modern p-4 mb-6">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" style="background: #DCFCE7;">
                    <i class="ti ti-cloud-check" style="font-size: 15px; color: #178A43;" aria-hidden="true"></i>
                </div>
                <p class="text-sm font-bold text-gray-800">Up to date, refreshed {{ $lastSyncedAt->format('M j, Y g:i A') }}</p>
            </div>
        </div>
    @endif

    @if ($evacueesByBarangay->isEmpty())
        <div class="flex flex-col items-center text-center py-16">
            <i class="ti ti-users text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
            <p class="text-sm text-gray-400">
                @if ($search !== '' || $barangayFilter !== '')
                    No cached match for these filters.
                @elseif ($isStale)
                    No families to show on this device yet.
                @else
                    No evacuees registered yet.
                @endif
            </p>
        </div>
    @else
        <div class="flex flex-col gap-6">
            @foreach ($evacueesByBarangay as $barangayName => $records)
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
                        {{ $barangayName }} <span class="text-gray-400 font-normal normal-case">({{ $records->count() }})</span>
                    </p>
                    <div class="flex flex-col gap-3">
                        @foreach ($records as $record)
                            <div class="card-modern p-4">
                                <p class="font-bold text-sm text-gray-800">{{ $record->head_name ?? 'Unnamed family' }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ $record->member_count }} member(s)</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
