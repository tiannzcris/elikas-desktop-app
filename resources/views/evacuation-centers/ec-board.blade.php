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
                {{-- Matches the real EC Information Board template's own row
                     order (mirrors the same fix already applied on the web
                     dashboard): this header-level count sits WITH
                     Barangay/Center/Event, not down with the Sectoral Group
                     table further below -- which has its own separate "4Ps
                     beneficiary" row (male/female), a different,
                     per-person-sex figure, not this one (see
                     EvacuationCenterQuickCount's own docblock). Lives
                     outside <form id="ecboard-sectoral-form"> below in the
                     DOM, but the form="" attribute on the input still
                     submits it with that form -- same element name/id, so
                     saveSectoral() needs no changes. --}}
                <div class="flex flex-col items-start">
                    <label for="beneficiaries-4ps" class="text-xs text-gray-400 mb-1">4Ps beneficiary families</label>
                    <input type="number" min="0" name="beneficiaries_4ps" id="beneficiaries-4ps" form="ecboard-sectoral-form"
                        value="{{ $quickCount->beneficiaries_4ps ?? 0 }}"
                        title="Saved together with the sectoral breakdown further down the page"
                        class="w-24 border border-gray-200 rounded-xl px-3 py-2 text-sm">
                </div>
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

        {{-- "Quick Departure": the reverse of Add Evacuee above -- mirrors
             the web dashboard's own Quick Departure card, but UNLIKE Add
             Evacuee this has NO offline path at all (see
             EvacuationCenterController::quickDeparture()'s own docblock
             for why: it needs the central server's own true current
             "who's here" set, which this device's local cache can't
             guarantee reflects). Reuses the exact same data-sync-control/
             data-sync-button/data-sync-offline-warning pattern already
             wired for Sync Now in app.js's updateSyncButtons() -- no new
             connectivity-detection logic here, same navigator.onLine
             signal as the header's Online/Offline badge. --}}
        <div class="card-modern p-4 mb-6" data-quick-departure-form>
            <h2 class="text-sm font-bold text-gray-700 mb-1">Quick departure</h2>
            <p class="text-xs text-gray-400 mb-3">Marks that many currently-evacuated people as departed -- oldest arrivals in the matching bracket first. Requires an internet connection; there's no offline queue for this action.</p>

            <div data-quick-departure-errors class="bg-red-50 text-red-700 text-sm rounded-xl p-3 mb-3" style="display: none;"></div>
            <p data-quick-departure-success class="text-xs text-green-600 font-medium mb-3" style="display: none;">&check; Marked as departed.</p>

            <div class="flex flex-col gap-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm text-gray-600 block mb-1">Sex</label>
                        <select data-quick-departure-sex class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm text-gray-600 block mb-1">Quantity</label>
                        <input type="number" min="1" value="1" data-quick-departure-quantity class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                    </div>
                    <div class="col-span-2">
                        <label class="text-sm text-gray-600 block mb-1">Age bracket</label>
                        <select data-quick-departure-age-bracket class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                            @foreach ($ageBrackets as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="text-sm text-gray-600 block mb-1">Reason</label>
                        <select data-quick-departure-status class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                            <option value="returned_home">Returned home</option>
                            <option value="transferred">Transferred elsewhere</option>
                        </select>
                    </div>
                </div>

                <div data-sync-control class="flex flex-col items-start gap-1">
                    <button type="button" data-sync-button data-quick-departure-submit
                        class="btn-modern flex items-center gap-1.5 bg-gray-800 hover:bg-gray-900 text-white text-sm px-4 py-2.5">
                        Mark as departed
                    </button>
                    <p data-sync-offline-warning class="items-center gap-1 text-xs text-amber-600" style="display: none;">
                        <i class="ti ti-alert-triangle" style="font-size: 12px;" aria-hidden="true"></i> Quick departure requires an internet connection.
                    </p>
                </div>
            </div>
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

            <form id="ecboard-sectoral-form" method="POST" action="{{ route('evacuation-centers.sectoral.update', $center) }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="evacuation_event_id" value="{{ $selectedEventId }}">

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

        // --- Quick Departure -------------------------------------------
        // Online-only by design (see EvacuationCenterController::
        // quickDeparture()'s own docblock) -- the button itself is
        // already disabled while offline via the shared data-sync-button
        // wiring in app.js's updateSyncButtons(), so a click here can
        // only happen while online; this still handles a mid-request
        // connection drop the same as any other network error below.
        const qdForm = document.querySelector('[data-quick-departure-form]');
        if (qdForm) {
            const qdSubmitBtn = qdForm.querySelector('[data-quick-departure-submit]');
            const qdErrors = qdForm.querySelector('[data-quick-departure-errors]');
            const qdSuccess = qdForm.querySelector('[data-quick-departure-success]');

            qdSubmitBtn.addEventListener('click', async () => {
                qdErrors.style.display = 'none';
                qdSuccess.style.display = 'none';

                const payload = {
                    evacuation_event_id: {{ (int) $selectedEventId }},
                    age_bracket: qdForm.querySelector('[data-quick-departure-age-bracket]').value,
                    sex: qdForm.querySelector('[data-quick-departure-sex]').value,
                    quantity: Number(qdForm.querySelector('[data-quick-departure-quantity]').value) || 0,
                    status: qdForm.querySelector('[data-quick-departure-status]').value,
                };

                qdSubmitBtn.disabled = true;
                qdSubmitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                qdSubmitBtn.textContent = 'Marking...';

                try {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                    const response = await fetch('{{ route('evacuation-centers.quick-departure', $center) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify(payload),
                    });
                    const body = await response.json().catch(() => ({}));

                    if (! response.ok) {
                        // Matches the backend's exact wording (e.g. the
                        // "Only N matching evacuee(s)..." 422) -- see
                        // CentralApiService::quickDeparture()'s own
                        // docblock. A field-level validation failure
                        // (422 with an "errors" object) falls back to
                        // listing those instead, same shape families
                        // sync already handles.
                        const messages = body.errors ? Object.values(body.errors).flat() : [body.message || 'The central server rejected this request.'];
                        qdErrors.innerHTML = messages.map((m) => `<p>${m}</p>`).join('');
                        qdErrors.style.display = 'block';
                        return;
                    }

                    qdForm.querySelector('[data-quick-departure-quantity]').value = 1;
                    qdSuccess.style.display = 'block';
                    setTimeout(() => { qdSuccess.style.display = 'none'; }, 2500);

                    // Reflects the just-completed departure in the "As of
                    // last sync" figures -- same live-refresh endpoint
                    // already called once on page load above. Quick
                    // Departure never touches this device's own pending
                    // (not-yet-synced) entries, so that other table is
                    // deliberately left untouched.
                    fetch('{{ route('evacuation-centers.breakdown-refresh', $center) }}?event={{ (int) $selectedEventId }}')
                        .then((r) => (r.ok ? r.text() : null))
                        .then((html) => {
                            if (html) document.getElementById('last-known-breakdown-table').innerHTML = html;
                        })
                        .catch(() => {});
                } catch (error) {
                    qdErrors.innerHTML = '<p>Could not reach the central server. Check your internet connection and try again.</p>';
                    qdErrors.style.display = 'block';
                } finally {
                    // Re-checks navigator.onLine rather than unconditionally
                    // re-enabling -- connectivity may have dropped mid-
                    // request, and updateSyncButtons() in app.js won't fire
                    // again on its own until the next online/offline event.
                    qdSubmitBtn.disabled = ! navigator.onLine;
                    qdSubmitBtn.classList.toggle('opacity-50', ! navigator.onLine);
                    qdSubmitBtn.classList.toggle('cursor-not-allowed', ! navigator.onLine);
                    qdSubmitBtn.textContent = 'Mark as departed';
                }
            });
        }
    });
</script>
@endsection
