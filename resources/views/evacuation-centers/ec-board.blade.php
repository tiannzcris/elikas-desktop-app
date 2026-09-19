@extends('layouts.app')

@section('title', 'EC Information Board -- '.$center->name)
@section('nav-ec-board', 'active')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            {{-- Always returns to THIS center's own barangay in the EC
                 Board flow -- resolved from the center's own
                 barangay_remote_id (see EvacuationCenterController::
                 ecBoard()), not a query param, so it's correct regardless
                 of how this page was reached. Falls back to the barangay
                 list if that barangay somehow isn't cached locally. --}}
            <a href="{{ $backBarangay ? route('ec-board.centers', $backBarangay) : route('ec-board.index') }}" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1 mb-2">
                <i class="ti ti-arrow-left" style="font-size: 12px;" aria-hidden="true"></i> Back to {{ $barangayName }}
            </a>
            <h1 class="text-xl font-bold text-brand mb-1">EC Information Board</h1>
            <p class="text-sm text-gray-500">
                {{ $barangayName }} &middot; {{ $center->name }}
                &middot; <a href="{{ route('evacuation-centers.show', $center) }}" class="text-gray-400 hover:text-brand underline">center details</a>
            </p>
        </div>

        <div class="flex items-start gap-3">
            @if ($events->isNotEmpty())
                <form method="GET" action="{{ route('evacuation-centers.ec-board', $center) }}">
                    <select name="event" onchange="this.form.submit()" class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white text-gray-700">
                        @foreach ($events as $e)
                            <option value="{{ $e->id }}" @selected($selectedEventId === $e->id)>{{ $e->name }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
            {{-- Lets staff sync directly from this page without navigating
                 away to Registered Families -- redirects back to this SAME
                 center+event afterward (see FamilyController::sync()'s own
                 resolveSyncRedirect()), so the breakdown/pending counts
                 below reflect the sync immediately. --}}
            @include('partials._sync_button', ['returnToCenterId' => $center->id, 'returnToEventId' => $selectedEventId])
        </div>
    </div>

    @if ($events->isEmpty())
        <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 mb-6 text-sm text-amber-800">
            No disaster events cached on this device -- refresh reference data while online before adding evacuees here.
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="card-modern p-4">
                <div class="flex items-center gap-2 mb-3">
                    <i class="ti ti-cloud-check text-gray-400" style="font-size: 16px;" aria-hidden="true"></i>
                    <p class="text-sm font-bold text-gray-700">As of last sync</p>
                    <span id="breakdown-refreshing-badge" class="text-xs text-gray-400" style="display: none;">(refreshing…)</span>
                </div>
                <p class="text-xs text-gray-400 mb-3">The central server's own live tally -- refreshes automatically a moment after this page loads if this device is online, otherwise shows whatever was last fetched for this center and event.</p>
                <div id="last-known-breakdown-table">
                    @include('evacuation-centers._breakdown_table', ['matrix' => $lastKnownBreakdown, 'ageBrackets' => $breakdownAgeBrackets])
                </div>
            </div>
            <div class="card-modern p-4">
                <div class="flex items-center gap-2 mb-3">
                    <i class="ti ti-device-desktop text-amber-500" style="font-size: 16px;" aria-hidden="true"></i>
                    <p class="text-sm font-bold text-gray-700">Added on this device (pending sync)</p>
                </div>
                <p class="text-xs text-gray-400 mb-3">Only this device's own not-yet-synced entries for this event. Other devices may have their own pending entries not reflected here until everything reaches the central server.</p>
                @include('evacuation-centers._breakdown_table', ['matrix' => $pendingBreakdown, 'ageBrackets' => $breakdownAgeBrackets])
            </div>
        </div>

        <div class="card-modern p-4 mb-6" data-ec-board-entry-form>
            <h2 class="text-sm font-bold text-gray-700 mb-3">Add evacuee</h2>
            <div class="form-errors bg-red-50 text-red-700 text-sm rounded-xl p-3 mb-3" @if (! $errors->any()) style="display: none;" @endif>
                {{ $errors->first() }}
            </div>
            <form method="POST" action="{{ route('ec-board-entries.store', $center) }}" class="flex flex-col gap-4">
                @csrf
                @include('evacuation-centers._entry_fields', ['entry' => null])
                <button type="submit" class="btn-modern btn-primary-modern bg-brand hover:bg-brand-dark text-white text-sm px-4 py-2.5 w-fit">
                    Add evacuee (offline)
                </button>
            </form>
        </div>

        <div>
            <h2 class="text-sm font-bold text-gray-700 mb-3">Pending entries for this event</h2>
            @if ($pendingEntries->isEmpty())
                <p class="text-sm text-gray-400">Nothing waiting to sync for this event.</p>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($pendingEntries as $entry)
                        <div class="card-modern p-4">
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="font-bold text-sm text-gray-800">{{ $entry->householdLabel() }}</p>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        {{ \Illuminate\Support\Str::headline($entry->sex) }} &middot;
                                        {{ $ageBrackets[$entry->age_bracket] ?? $entry->age_bracket }}
                                    </p>
                                    <p class="text-xs text-gray-400 mt-1">Added {{ $entry->created_at->format('M j, Y g:i A') }}</p>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700">
                                        <i class="ti ti-clock" style="font-size: 12px;" aria-hidden="true"></i> Waiting to sync
                                    </span>
                                    <a href="{{ route('ec-board-entries.edit', $entry) }}" data-modal-trigger="ec-board-entry" class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-brand hover:bg-gray-100" aria-label="Edit" title="Edit">
                                        <i class="ti ti-pencil" style="font-size: 14px;" aria-hidden="true"></i>
                                    </a>
                                    <form method="POST" action="{{ route('ec-board-entries.destroy', $entry) }}" onsubmit="if (!confirm('Remove this pending entry from this device? This cannot be undone.')) { event.stopPropagation(); return false; }">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-red-600 hover:bg-red-50" aria-label="Delete" title="Delete">
                                            <i class="ti ti-trash" style="font-size: 14px;" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            @if ($entry->sync_error)
                                <p class="text-xs text-red-500 mt-2 border-t border-gray-100 pt-2">{{ $entry->sync_error }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Sectoral/4Ps figures -- a manually-reported aggregate, kept
             deliberately separate from the age/sex breakdown above:
             sectoral flags (PWD, pregnant, etc.) aren't known at "Add
             Evacuee" time, so unlike age/sex this can never be derived
             from individual entries (see EvacuationCenterQuickCount's own
             docblock, mirroring the same reasoning already established on
             the web dashboard). Saves entirely to this device first, same
             as "Add evacuee" above -- no live call from this form, synced
             on the next "Sync now". --}}
        <div class="card-modern p-4 mt-6">
            <h2 class="text-sm font-bold text-gray-700 mb-1">Sectoral group breakdown</h2>
            <p class="text-xs text-gray-400 mb-3">Manually-reported figures for this event -- separate from the age/sex breakdown above, and not generated from individual evacuee entries.</p>

            @if ($quickCount)
                @if (! $quickCount->isSynced())
                    <p class="inline-flex items-center gap-1.5 text-xs text-amber-700 bg-amber-50 px-2.5 py-1.5 rounded-lg mb-3">
                        <i class="ti ti-clock" style="font-size: 13px;" aria-hidden="true"></i> Saved on this device, not yet synced.
                    </p>
                @else
                    <p class="text-xs text-gray-400 mb-3">Last synced {{ $quickCount->synced_at->format('M j, Y g:i A') }}.</p>
                @endif
                @if ($quickCount->sync_error)
                    <p class="text-xs text-red-500 mb-3">{{ $quickCount->sync_error }}</p>
                @endif
            @else
                <p class="text-xs text-gray-400 mb-3">Not yet reported for this event.</p>
            @endif

            <form method="POST" action="{{ route('evacuation-centers.sectoral.update', $center) }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="evacuation_event_id" value="{{ $selectedEventId }}">

                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1" for="beneficiaries-4ps">4Ps beneficiary families</label>
                    <input type="number" min="0" name="beneficiaries_4ps" id="beneficiaries-4ps" value="{{ $quickCount->beneficiaries_4ps ?? 0 }}" class="w-28 border border-gray-200 rounded-xl px-3 py-2 text-sm">
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-gray-400 text-left">
                                <th class="pb-2 font-medium">Sectoral group</th>
                                <th class="pb-2 font-medium w-24">Male</th>
                                <th class="pb-2 font-medium w-24">Female</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $sectoralRows = $quickCount ? $quickCount->sectoralGroups->keyBy('sectoral_group') : collect(); @endphp
                            @foreach ($sectoralGroups as $groupKey => $groupLabel)
                                <tr class="border-t border-gray-100">
                                    <td class="py-1.5 text-gray-600">
                                        {{ $groupLabel }}
                                        <input type="hidden" name="sectoral_groups[{{ $loop->index }}][sectoral_group]" value="{{ $groupKey }}">
                                    </td>
                                    <td class="py-1.5">
                                        <input type="number" min="0" name="sectoral_groups[{{ $loop->index }}][male_count]" value="{{ $sectoralRows[$groupKey]->male_count ?? 0 }}" class="w-20 border border-gray-200 rounded-xl px-2 py-1.5 text-xs">
                                    </td>
                                    <td class="py-1.5">
                                        <input type="number" min="0" name="sectoral_groups[{{ $loop->index }}][female_count]" value="{{ $sectoralRows[$groupKey]->female_count ?? 0 }}" class="w-20 border border-gray-200 rounded-xl px-2 py-1.5 text-xs">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="btn-modern btn-primary-modern bg-brand hover:bg-brand-dark text-white text-sm px-4 py-2.5 w-fit">
                    Save sectoral figures (offline)
                </button>
            </form>
        </div>
    @endif
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        window.ELIKAS.initEcBoardEntryForm(document.querySelector('[data-ec-board-entry-form]'));

        // Fires AFTER this page (and its own CSS/JS/fonts) has already
        // finished loading -- deliberately not part of the page's own
        // server-side render. See EvacuationCenterController::ecBoard()'s
        // docblock for the bug this fixes: a live network call blocking
        // that render starved this page's own concurrent asset requests
        // while offline, leaving it unstyled. Silently does nothing on
        // any failure (offline, timeout, session expired) -- the
        // server-rendered table already on screen is left exactly as is.
        @if ($selectedEventId)
            fetch('{{ route('evacuation-centers.breakdown-refresh', $center) }}?event={{ $selectedEventId }}')
                .then((r) => (r.ok ? r.text() : null))
                .then((html) => {
                    if (html) document.getElementById('last-known-breakdown-table').innerHTML = html;
                })
                .catch(() => {});
        @endif
    });
</script>
@endsection
