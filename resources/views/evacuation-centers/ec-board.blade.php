@extends('layouts.app')

@section('title', 'EC Information Board -- '.$center->name)
@section('nav-ec-board', 'active')

@section('content')
    <div class="flex items-center justify-between gap-3 mb-4">
        {{-- Always returns to THIS center's own barangay in the EC
             Board flow -- resolved from the center's own
             barangay_remote_id (see EvacuationCenterController::
             ecBoard()), not a query param, so it's correct regardless
             of how this page was reached. Falls back to the barangay
             list if that barangay somehow isn't cached locally. --}}
        <a href="{{ $backBarangay ? route('ec-board.centers', $backBarangay) : route('ec-board.index') }}" class="btn btn-secondary">
            <i class="ti ti-arrow-left" style="font-size: 15px;" aria-hidden="true"></i> Back to {{ $barangayName }}
        </a>
        {{-- Lets staff sync directly from this page without navigating
             away to Registered Families -- redirects back to this SAME
             center+event afterward (see FamilyController::sync()'s own
             resolveSyncRedirect()), so the board reflects the sync
             immediately. --}}
        @include('partials._sync_button', ['returnToCenterId' => $center->id, 'returnToEventId' => $selectedEventId])
    </div>

    @if ($events->isEmpty())
        <div class="card p-5 mb-6">
            <p class="text-xs font-medium text-gray-600">EC Information Board</p>
            <h1 class="text-lg font-semibold text-gray-900">{{ $center->name }}</h1>
            <p class="text-sm text-gray-600 mb-4">Barangay {{ $barangayName }}</p>
            <div class="callout callout-warning">
                No disaster events cached on this device -- refresh reference data while online before adding evacuees here.
            </div>
        </div>
    @else
        {{-- The board on the left and the Add evacuee panel beside it,
             sticky and scrolling on its own on wide windows so it stays in
             reach while the board scrolls. On narrower windows the board
             comes first -- it names the center and event being added to --
             and the panel follows it. --}}
        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_22rem] gap-5 items-start mb-5">
            <section class="card ecb-sheet" aria-label="EC Information Board">
                <div class="px-5 pt-4 pb-3 flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-600">EC Information Board</p>
                        <h1 class="text-lg font-semibold text-gray-900 leading-snug">{{ $center->name }}</h1>
                        <p class="text-sm text-gray-600">
                            Barangay <span class="font-medium text-gray-900">{{ $barangayName }}</span>
                            &middot; <a href="{{ route('evacuation-centers.show', $center) }}" class="link">center details</a>
                        </p>
                    </div>
                    <form method="GET" action="{{ route('evacuation-centers.ec-board', $center) }}" class="flex flex-col">
                        <label for="ecb-event" class="label-sm">Event</label>
                        <select id="ecb-event" name="event" onchange="this.form.submit()" class="input w-auto min-w-[13rem]">
                            @foreach ($events as $e)
                                <option value="{{ $e->id }}" @selected($selectedEventId === $e->id)>{{ $e->name }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                <div id="ecb-figures">
                    @include('evacuation-centers._board_figures')
                </div>
            </section>

            <section class="card px-4 pt-4 xl:sticky xl:top-0 xl:max-h-[calc(100vh-7rem)] xl:overflow-y-auto" data-ec-board-entry-form aria-label="Add evacuee">
                <h2 class="card-title">Add evacuee</h2>
                <p class="text-xs text-gray-600 mt-0.5 mb-3">Saved on this device first -- name and birthdate can be added later.</p>
                <div class="form-errors callout callout-danger mb-3" role="alert" @if (! $errors->any()) style="display: none;" @endif>
                    {{ $errors->first() }}
                </div>
                <form method="POST" action="{{ route('ec-board-entries.store', $center) }}" class="flex flex-col">
                    @csrf
                    @include('evacuation-centers._entry_fields', ['entry' => null, 'fixedEventId' => $selectedEventId, 'submitLabel' => 'Add evacuee (offline)'])
                </form>
            </section>
        </div>

        <section class="card mb-5" aria-label="Pending entries">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                <h2 class="card-title">Pending entries for this event</h2>
                @if ($pendingEntries->isNotEmpty())
                    <span class="section-count section-count-pending">{{ $pendingEntries->count() }}</span>
                @endif
            </div>
            @if ($pendingEntries->isEmpty())
                <p class="px-5 py-4 text-sm text-gray-600">Nothing waiting to sync for this event.</p>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($pendingEntries as $entry)
                        <li class="px-5 py-2.5">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold text-sm text-gray-900 truncate">{{ $entry->householdLabel() }}</p>
                                    <p class="text-xs text-gray-600">
                                        {{ \Illuminate\Support\Str::headline($entry->sex) }} &middot;
                                        {{ $ageBrackets[$entry->age_bracket] ?? $entry->age_bracket }} &middot;
                                        added {{ $entry->created_at->format('M j, g:i A') }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <span class="badge badge-warning mr-1">
                                        <i class="ti ti-clock" style="font-size: 12px;" aria-hidden="true"></i> Waiting to sync
                                    </span>
                                    <a href="{{ route('ec-board-entries.edit', $entry) }}" data-modal-trigger="ec-board-entry" class="btn-icon" aria-label="Edit" title="Edit">
                                        <i class="ti ti-pencil" style="font-size: 14px;" aria-hidden="true"></i>
                                    </a>
                                    <form method="POST" action="{{ route('ec-board-entries.destroy', $entry) }}" onsubmit="if (!confirm('Remove this pending entry from this device? This cannot be undone.')) { event.stopPropagation(); return false; }">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon btn-icon-danger" aria-label="Delete" title="Delete">
                                            <i class="ti ti-trash" style="font-size: 14px;" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            @if ($entry->sync_error)
                                <p class="text-xs text-red-700 mt-1.5">{{ $entry->sync_error }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- "Quick Departure": the reverse of Add Evacuee, kept to one row at
             the bottom -- mirrors the web dashboard's own Quick Departure,
             but UNLIKE Add Evacuee this has NO offline path at all (see
             EvacuationCenterController::quickDeparture()'s own docblock
             for why: it needs the central server's own true current
             "who's here" set, which this device's local cache can't
             guarantee reflects). Reuses the same data-sync-control/
             data-sync-button/data-sync-offline-warning wiring as Sync Now
             in app.js's updateSyncButtons(). --}}
        <section class="card p-4" data-quick-departure-form data-sync-control aria-label="Quick departure">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 mb-3">
                <h2 class="card-title">Quick departure</h2>
                <p class="text-xs text-gray-600">Marks that many people as departed, oldest arrivals in the bracket first. Needs an internet connection.</p>
            </div>

            <div data-quick-departure-errors class="callout callout-danger mb-3" role="alert" style="display: none;"></div>

            <div class="grid grid-cols-2 lg:grid-cols-[7rem_minmax(0,1fr)_5.5rem_minmax(0,1fr)_auto] gap-3 items-end">
                <div>
                    <label class="label-sm" for="qd-sex">Sex</label>
                    <select id="qd-sex" data-quick-departure-sex class="input">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div>
                    <label class="label-sm" for="qd-age">Age group</label>
                    <select id="qd-age" data-quick-departure-age-bracket class="input">
                        @foreach ($ageBrackets as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label-sm" for="qd-quantity">Quantity</label>
                    <input id="qd-quantity" type="number" min="1" value="1" data-quick-departure-quantity class="input">
                </div>
                <div>
                    <label class="label-sm" for="qd-reason">Reason</label>
                    <select id="qd-reason" data-quick-departure-status class="input">
                        <option value="returned_home">Returned home</option>
                        <option value="transferred">Transferred elsewhere</option>
                    </select>
                </div>
                <div class="col-span-2 lg:col-span-1">
                    <button type="button" data-sync-button data-quick-departure-submit
                        class="btn btn-neutral">
                        Mark as departed
                    </button>
                </div>
            </div>
            <p data-sync-offline-warning class="items-center gap-1 text-xs text-amber-800 mt-2" style="display: none;">
                <i class="ti ti-alert-triangle" style="font-size: 12px;" aria-hidden="true"></i> Quick departure requires an internet connection.
            </p>
            <p data-quick-departure-success class="text-xs text-green-800 font-medium mt-2" role="status" style="display: none;">&check; Marked as departed.</p>
        </section>
    @endif
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        window.ELIKAS.initEcBoardEntryForm(document.querySelector('[data-ec-board-entry-form]'));

        // Re-renders the board's figures from the central server (see
        // EvacuationCenterController::refreshBoard()). cache: 'no-store' +
        // a cache-busting _ param -- same fix already proven on
        // households-refresh (see loadRemoteHouseholds() in app.js): the
        // board must reflect what was actually just synced, never a stale
        // browser-cached response for the same URL. Silently does nothing
        // on any failure (offline, timeout, session expired) -- the board
        // already on screen, with its "As of", is left as is.
        const refreshBoard = () => {
            fetch(`{{ route('evacuation-centers.board-refresh', $center) }}?event={{ (int) $selectedEventId }}&_=${Date.now()}`, { cache: 'no-store' })
                .then((r) => (r.ok ? r.text() : null))
                .then((html) => {
                    const figures = document.getElementById('ecb-figures');
                    if (!html || !figures) return;
                    figures.innerHTML = html;
                    window.ELIKAS.localizeTimes(figures);
                })
                .catch(() => {});
        };

        // Fires AFTER this page (and its own CSS/JS/fonts) has already
        // finished loading -- deliberately not part of the page's own
        // server-side render. See EvacuationCenterController::ecBoard()'s
        // docblock for the bug this fixes: a live network call blocking
        // that render starved this page's own concurrent asset requests
        // while offline, leaving it unstyled.
        @if ($selectedEventId)
            refreshBoard();
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

                    // Reflects the just-completed departure in the board's
                    // server figures -- the same refresh already called
                    // once on page load above.
                    refreshBoard();
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
