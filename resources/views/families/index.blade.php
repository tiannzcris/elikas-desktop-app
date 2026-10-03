@extends('layouts.app')

@section('title', 'Registered families')
@section('nav-families', 'active')

@section('content')
    <div class="page-header">
        <div>
            <h1 class="page-title">Registered families</h1>
            <p class="page-subtitle">Every family recorded on this device, from every barangay.</p>
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
        <div class="callout callout-danger p-4 mb-6" role="alert">
            <p class="text-sm font-semibold text-red-800 mb-2 flex items-center gap-1.5">
                <i class="ti ti-alert-triangle" style="font-size: 15px;" aria-hidden="true"></i>
                {{ $syncErrors->count() }} {{ Str::plural('family', $syncErrors->count()) }} failed to sync
            </p>
            <div class="flex flex-col gap-1.5">
                @foreach ($syncErrors as $errored)
                    <a href="{{ route('families.index') }}#not-yet-synced" class="text-xs text-red-800 hover:underline">
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
        <i class="ti ti-search absolute left-3 top-1/2 text-gray-500" style="font-size: 15px; transform: translateY(-50%);" aria-hidden="true"></i>
        <input type="text" name="search" value="{{ $search ?? '' }}" aria-label="Search families" placeholder="Search any family by member name, regardless of barangay or center..." class="input pl-9 py-2.5">
    </form>

    @if (($view ?? null) === 'search')
        <div class="flex items-center justify-between mb-4">
            <p class="text-sm text-gray-600">
                {{ $families->count() }} result(s) for "<span class="font-medium text-gray-900">{{ $search }}</span>"
            </p>
            <a href="{{ route('families.index') }}" class="link text-sm">Clear search</a>
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
                            <p class="text-xs text-gray-600">{{ $empty }}</p>
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
                <i class="ti ti-clock text-amber-700" aria-hidden="true"></i>
                Not yet synced <span class="section-count section-count-pending">{{ $pendingCount }}</span>
            </h2>
            <p class="text-xs text-gray-600 mb-4">Saved on this device only. They reach the central server on the next sync.</p>

            @if ($ecBoardPendingByCenter->isNotEmpty())
                {{-- People added on an EC Board -- entries, not families, so
                     a count per center that links to where they're managed. --}}
                <div class="flex flex-col gap-2 mb-4">
                    @foreach ($ecBoardPendingByCenter as $row)
                        <a href="{{ route('evacuation-centers.ec-board', $row->center) }}" class="flex items-center justify-between gap-3 rounded-lg bg-amber-50 border border-amber-200 px-4 py-2.5 text-sm text-amber-900 hover:bg-amber-100">
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
                <p class="flex items-center gap-2 text-sm text-gray-600 card p-4">
                    <i class="ti ti-cloud-check text-green-700" style="font-size: 16px;" aria-hidden="true"></i>
                    No family is waiting -- every family on this device has synced.
                </p>
            @else
                <div class="flex flex-col gap-5">
                    @foreach ($pendingByBarangay as $barangayName => $pendingFamilies)
                        <div>
                            <p class="group-label">
                                {{ $barangayName }} <span class="text-gray-500 font-normal">({{ $pendingFamilies->count() }})</span>
                            </p>
                            @include('families._family_cards', ['families' => $pendingFamilies, 'emptyMessage' => ''])
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section id="synced">
            <h2 class="section-heading">
                <i class="ti ti-cloud-check text-green-700" aria-hidden="true"></i>
                Synced <span class="section-count">{{ $syncedCount }}</span>
            </h2>
            <p class="text-xs text-gray-600 mb-4">Already on the central server, kept here for offline lookup.</p>

            @if ($barangaySummary->isEmpty())
                <div class="empty-state py-12">
                    <i class="ti ti-users" aria-hidden="true"></i>
                    <p>No synced families on this device yet.</p>
                </div>
            @else
                <p class="callout callout-info text-xs mb-4">
                    Your barangay is shown first. Other barangays are included so you can help register displaced residents temporarily staying in your area, or view city-wide activity.
                </p>
                <div class="flex flex-col gap-3">
                    @foreach ($barangaySummary as $row)
                        @php($isOwnBarangay = $currentUser->barangay_id !== null && ($row->barangay->remote_id ?? null) === $currentUser->barangay_id)
                        <a href="{{ route('families.index', ['barangay' => $row->barangay_id]) }}" class="card card-link p-4 flex items-center justify-between {{ $isOwnBarangay ? 'border-brand-200' : '' }}">
                            <div>
                                <p class="font-semibold text-sm text-gray-900 flex items-center gap-1.5">
                                    {{ $row->barangay->name ?? 'Unknown barangay' }}
                                    @if ($isOwnBarangay)
                                        <span class="badge badge-info">Your barangay</span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-600 mt-0.5">{{ $row->family_count }} synced {{ Str::plural('family', $row->family_count) }}</p>
                            </div>
                            <i class="ti ti-chevron-right text-gray-500" style="font-size: 18px;" aria-hidden="true"></i>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        {{-- Below the landing view is the Synced drill-down only -- pending
             families all live in the landing view's own section. --}}
        <nav class="flex items-center gap-1.5 text-sm text-gray-600 mb-4" aria-label="Breadcrumb">
            <a href="{{ route('families.index') }}#synced" class="hover:text-brand hover:underline">Synced</a>
            <i class="ti ti-chevron-right" style="font-size: 12px;" aria-hidden="true"></i>
            @if (($view ?? null) === 'center')
                <span class="font-medium text-gray-900">{{ $barangay->name }}</span>
            @else
                <a href="{{ route('families.index', ['barangay' => $barangay->id]) }}" class="hover:text-brand hover:underline">{{ $barangay->name }}</a>
                <i class="ti ti-chevron-right" style="font-size: 12px;" aria-hidden="true"></i>
                <span class="font-medium text-gray-900">{{ $center->name ?? 'Outside center / unassigned' }}</span>
            @endif
        </nav>

        @if (($view ?? null) === 'center')
            @if ($centerSummary->isEmpty())
                <div class="empty-state">
                    <i class="ti ti-building-community" aria-hidden="true"></i>
                    <p>No synced families from {{ $barangay->name }} yet.</p>
                </div>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($centerSummary as $row)
                        <a href="{{ route('families.index', ['barangay' => $barangay->id, 'center' => $row->evacuation_center_id ?? 'none']) }}" class="card card-link p-4 flex items-center justify-between">
                            <div>
                                <p class="font-semibold text-sm text-gray-900 flex items-center gap-1.5">
                                    {{ $row->evacuationCenter->name ?? 'Outside center / unassigned' }}
                                    {{-- This row means "{{ $barangay->name }}'s families staying
                                         here", not "belongs to {{ $barangay->name }}" -- flagged
                                         whenever the center's OWN barangay differs, so it's never
                                         mistaken for a center physically located in this barangay
                                         (see FamilyController::centerSummary()'s own docblock). --}}
                                    @if ($row->locatedInDifferentBarangay)
                                        <span class="badge badge-warning">
                                            Located in {{ $row->locatedInDifferentBarangay }}
                                        </span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-600 mt-0.5">{{ $row->family_count }} synced {{ Str::plural('family', $row->family_count) }}</p>
                            </div>
                            <i class="ti ti-chevron-right text-gray-500 shrink-0 ml-3" style="font-size: 18px;" aria-hidden="true"></i>
                        </a>
                    @endforeach
                </div>
            @endif
        @else
            @include('families._family_cards', ['families' => $families, 'emptyMessage' => 'No synced families here yet.'])
        @endif
    @endif
@endsection
