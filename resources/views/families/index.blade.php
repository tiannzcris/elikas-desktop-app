@extends('layouts.app')

@section('title', 'Registered families')
@section('nav-families', 'active')

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-brand mb-1">Registered families</h1>
            <p class="text-sm text-gray-500">Every household recorded on this device, from every barangay.</p>
        </div>
        {{-- No "Register a family" button: EC Board's Add Evacuee is the
             entry path. families.create is still reachable by URL. --}}
        @include('partials._sync_button')
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
                    <a href="{{ route('families.index') }}#not-yet-synced" class="text-xs text-red-600 hover:underline">
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

        @if ($families->isEmpty())
            @include('families._family_cards', ['families' => $families, 'emptyMessage' => 'No family matches that name.'])
        @else
            @php([$searchSynced, $searchPending] = $families->partition(fn ($family) => $family->isSynced()))
            <div class="flex flex-col gap-8">
                @foreach ([['Not yet synced', $searchPending, 'No match waiting to sync.'], ['Synced', $searchSynced, 'No synced match.']] as [$label, $matches, $empty])
                    <section>
                        <h2 class="section-heading">{{ $label }} <span class="section-count">{{ $matches->count() }}</span></h2>
                        @if ($matches->isEmpty())
                            <p class="text-xs text-gray-400">{{ $empty }}</p>
                        @else
                            @include('families._family_cards', ['families' => $matches, 'emptyMessage' => ''])
                        @endif
                    </section>
                @endforeach
            </div>
        @endif
    @elseif (($view ?? null) === 'barangay')
        @php($pendingCount = $pendingByBarangay->sum(fn ($group) => $group->count()))
        @php($syncedCount = $barangaySummary->sum('family_count'))

        <section id="not-yet-synced" class="mb-10">
            <h2 class="section-heading">
                <i class="ti ti-clock text-amber-600" aria-hidden="true"></i>
                Not yet synced <span class="section-count section-count-pending">{{ $pendingCount }}</span>
            </h2>
            <p class="text-xs text-gray-500 mb-4">Saved on this device only. They reach the central server on the next sync.</p>

            @if ($ecBoardPendingByCenter->isNotEmpty())
                {{-- People added on an EC Board -- entries, not families, so
                     a count per center that links to where they're managed. --}}
                <div class="flex flex-col gap-2 mb-4">
                    @foreach ($ecBoardPendingByCenter as $row)
                        <a href="{{ route('evacuation-centers.ec-board', $row->center) }}" class="flex items-center justify-between gap-3 rounded-xl bg-amber-50 border border-amber-100 px-4 py-2.5 text-sm text-amber-800 hover:bg-amber-100">
                            <span class="flex items-center gap-2">
                                <i class="ti ti-clipboard-list" style="font-size: 15px;" aria-hidden="true"></i>
                                <span><span class="font-semibold">{{ $row->count }} {{ Str::plural('person', $row->count) }}</span> added on the EC Board at {{ $row->center->name }}</span>
                            </span>
                            <span class="flex items-center gap-1 text-xs font-semibold shrink-0">Open EC Board <i class="ti ti-chevron-right" style="font-size: 13px;" aria-hidden="true"></i></span>
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($pendingByBarangay->isEmpty())
                <p class="flex items-center gap-2 text-sm text-gray-500 card-modern p-4">
                    <i class="ti ti-cloud-check text-green-600" style="font-size: 16px;" aria-hidden="true"></i>
                    No family is waiting -- every family on this device has synced.
                </p>
            @else
                <div class="flex flex-col gap-5">
                    @foreach ($pendingByBarangay as $barangayName => $pendingFamilies)
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
                                {{ $barangayName }} <span class="text-gray-400 font-normal normal-case">({{ $pendingFamilies->count() }})</span>
                            </p>
                            @include('families._family_cards', ['families' => $pendingFamilies, 'emptyMessage' => ''])
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section id="synced">
            <h2 class="section-heading">
                <i class="ti ti-cloud-check text-green-600" aria-hidden="true"></i>
                Synced <span class="section-count">{{ $syncedCount }}</span>
            </h2>
            <p class="text-xs text-gray-500 mb-4">Already on the central server, kept here for offline lookup.</p>

            @if ($barangaySummary->isEmpty())
                <div class="flex flex-col items-center text-center py-12">
                    <i class="ti ti-users text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
                    <p class="text-sm text-gray-400">No synced families on this device yet.</p>
                </div>
            @else
                <p class="text-xs text-gray-500 bg-blue-50 border border-blue-100 rounded-xl p-3 mb-4">
                    Your barangay is shown first. Other barangays are included so you can help register displaced residents temporarily staying in your area, or view city-wide activity.
                </p>
                <div class="flex flex-col gap-3">
                    @foreach ($barangaySummary as $row)
                        @php($isOwnBarangay = $currentUser->barangay_id !== null && ($row->barangay->remote_id ?? null) === $currentUser->barangay_id)
                        <a href="{{ route('families.index', ['barangay' => $row->barangay_id]) }}" class="card-modern p-4 flex items-center justify-between hover:shadow-md transition-shadow {{ $isOwnBarangay ? 'ring-1 ring-brand/40' : '' }}">
                            <div>
                                <p class="font-bold text-sm text-gray-800 flex items-center gap-1.5">
                                    {{ $row->barangay->name ?? 'Unknown barangay' }}
                                    @if ($isOwnBarangay)
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-brand bg-blue-50 rounded-full px-2 py-0.5">Your barangay</span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ $row->family_count }} synced {{ Str::plural('family', $row->family_count) }}</p>
                            </div>
                            <i class="ti ti-chevron-right text-gray-400" style="font-size: 18px;" aria-hidden="true"></i>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        {{-- Below the landing view is the Synced drill-down only -- pending
             families all live in the landing view's own section. --}}
        <nav class="flex items-center gap-1.5 text-sm text-gray-500 mb-4">
            <a href="{{ route('families.index') }}#synced" class="hover:text-brand hover:underline">Synced</a>
            <i class="ti ti-chevron-right" style="font-size: 12px;" aria-hidden="true"></i>
            @if (($view ?? null) === 'center')
                <span class="font-medium text-gray-700">{{ $barangay->name }}</span>
            @else
                <a href="{{ route('families.index', ['barangay' => $barangay->id]) }}" class="hover:text-brand hover:underline">{{ $barangay->name }}</a>
                <i class="ti ti-chevron-right" style="font-size: 12px;" aria-hidden="true"></i>
                <span class="font-medium text-gray-700">{{ $center->name ?? 'Outside center / unassigned' }}</span>
            @endif
        </nav>

        @if (($view ?? null) === 'center')
            @if ($centerSummary->isEmpty())
                <div class="flex flex-col items-center text-center py-16">
                    <i class="ti ti-building-community text-gray-300 mb-3" style="font-size: 40px;" aria-hidden="true"></i>
                    <p class="text-sm text-gray-400">No synced families from {{ $barangay->name }} yet.</p>
                </div>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($centerSummary as $row)
                        <a href="{{ route('families.index', ['barangay' => $barangay->id, 'center' => $row->evacuation_center_id ?? 'none']) }}" class="card-modern p-4 flex items-center justify-between hover:shadow-md transition-shadow">
                            <div>
                                <p class="font-bold text-sm text-gray-800 flex items-center gap-1.5">
                                    {{ $row->evacuationCenter->name ?? 'Outside center / unassigned' }}
                                    {{-- This row means "{{ $barangay->name }}'s families staying
                                         here", not "belongs to {{ $barangay->name }}" -- flagged
                                         whenever the center's OWN barangay differs, so it's never
                                         mistaken for a center physically located in this barangay
                                         (see FamilyController::centerSummary()'s own docblock). --}}
                                    @if ($row->locatedInDifferentBarangay)
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-700 bg-amber-50 rounded-full px-2 py-0.5">
                                            Located in {{ $row->locatedInDifferentBarangay }}
                                        </span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ $row->family_count }} synced {{ Str::plural('family', $row->family_count) }}</p>
                            </div>
                            <i class="ti ti-chevron-right text-gray-400 shrink-0 ml-3" style="font-size: 18px;" aria-hidden="true"></i>
                        </a>
                    @endforeach
                </div>
            @endif
        @else
            @include('families._family_cards', ['families' => $families, 'emptyMessage' => 'No synced families here yet.'])
        @endif
    @endif
@endsection
