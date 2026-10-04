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
        <div class="flex flex-wrap items-center justify-end gap-2">
            @include('partials._sync_button', ['returnToCenterId' => $center->id, 'returnToEventId' => $selectedEventId])
            {{-- Add evacuee opens in a pop-up over the board, the same as
                 the web dashboard's EC Board. --}}
            @if ($events->isNotEmpty() && ! $selectedEventClosed)
                <button type="button" class="btn btn-primary" data-open-board-modal="add-evacuee-modal" aria-haspopup="dialog">
                    <i class="ti ti-user-plus" style="font-size: 16px;" aria-hidden="true"></i> Add evacuee
                </button>
            @endif
        </div>
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
            <section class="card ecb-sheet mb-5" aria-label="EC Information Board">
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
                                <option value="{{ $e->id }}" @selected($selectedEventId === $e->id)>{{ $e->name }}{{ $e->isClosed() ? ' (closed)' : '' }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                {{-- Shown here only because entries for it are still
                     waiting on this device (see EvacuationCenterController::
                     ecBoard()): they can't sync any more. --}}
                @if ($selectedEventClosed)
                    <p class="callout callout-warning mx-5 mb-3 flex items-start gap-2" role="note" data-closed-event-note>
                        <i class="ti ti-lock shrink-0 mt-0.5" style="font-size: 16px;" aria-hidden="true"></i>
                        <span>This event is closed. Evacuees can no longer be added to it, so the entries below that are still waiting on this device can't sync. Check them, then delete them from this device.</span>
                    </p>
                @endif

                <div id="ecb-figures">
                    @include('evacuation-centers._board_figures')
                </div>
            </section>


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


        {{-- Add evacuee, in a pop-up: its header and the read-back + save
             button stay put while the questions scroll. Still saved on this
             device first, exactly as before; after a save the page reloads
             and the pop-up opens again, ready for the next person (see
             EcBoardEntryController::store()'s ecBoardEntryAdded flash). --}}
        @unless ($selectedEventClosed)
        <div id="add-evacuee-modal" class="board-modal-backdrop" style="display: none;">
            <div class="modal modal-pop max-w-lg max-h-[90vh] flex flex-col overflow-hidden" data-ec-board-entry-form
                role="dialog" aria-modal="true" aria-labelledby="add-evacuee-title">
                <div class="modal-header shrink-0">
                    <div class="min-w-0">
                        <h2 id="add-evacuee-title" class="modal-title">Add evacuee</h2>
                        <p class="text-xs font-medium text-gray-700 mt-0.5">{{ $center->name }} &middot; {{ $events->firstWhere('id', $selectedEventId)?->name }}</p>
                        <p class="text-xs text-gray-600">Saved on this device first -- name and birthdate can be added later.</p>
                    </div>
                    <button type="button" class="btn-icon -mr-1.5" data-close-board-modal aria-label="Close">
                        <i class="ti ti-x" style="font-size: 18px;" aria-hidden="true"></i>
                    </button>
                </div>
                {{-- Only for a barangay official on a center in ANOTHER
                     barangay: a reminder, never a block, kept above the
                     scrolling questions so it stays in view -- the same as
                     the web dashboard's EC Board. --}}
                @if ($currentUser->isBarangayOfficial() && $currentUser->barangay_id && (int) $center->barangay_remote_id !== (int) $currentUser->barangay_id)
                    <p class="callout callout-warning shrink-0 mx-5 mt-3 flex items-start gap-2" role="note" data-other-barangay-note>
                        <i class="ti ti-map-pin shrink-0 mt-0.5" style="font-size: 16px;" aria-hidden="true"></i>
                        <span>This center is in <span class="font-semibold">{{ $barangayName }}</span>. Add only people who are staying at this center.</span>
                    </p>
                @endif
                <div class="flex-1 min-h-0 overflow-y-auto px-5 pt-4">
                    <p data-entry-added class="callout callout-success mb-3" role="status" @if (! session('ecBoardEntryAdded')) style="display: none;" @endif>&check; Added on this device -- the form is ready for the next one.</p>
                    <div class="form-errors callout callout-danger mb-3" role="alert" @if (! ($errors->any() && old('_board_form') === 'add-evacuee')) style="display: none;" @endif>
                        {{ $errors->first() }}
                    </div>
                    <form method="POST" action="{{ route('ec-board-entries.store', $center) }}" class="flex flex-col">
                        @csrf
                        <input type="hidden" name="_board_form" value="add-evacuee">
                        @include('evacuation-centers._entry_fields', ['entry' => null, 'fixedEventId' => $selectedEventId, 'submitLabel' => 'Add evacuee (offline)'])
                    </form>
                </div>
            </div>
        </div>
        @endunless

    @endif
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        window.ELIKAS.initEcBoardEntryForm(document.querySelector('[data-ec-board-entry-form]'));

        // --- Pop-ups -----------------------------------------------------
        // Add evacuee opens over the board, the same as the web
        // dashboard: the X, Close, Escape and a click on the dimmed
        // backdrop close them; focus goes to the first field on open, stays
        // inside while open, and returns to the button that opened it.
        let openModal = null;
        let opener = null;
        const focusables = (el) => [...el.querySelectorAll('button, input, select, textarea, summary, a[href]')]
            .filter((f) => ! f.disabled && f.getClientRects().length);

        const openBoardModal = (id, from = null) => {
            openModal = document.getElementById(id);
            if (! openModal) return;
            opener = from;
            openModal.style.display = 'flex';
            focusables(openModal).find((f) => f.matches('select, input:not([type=hidden])'))?.focus();
        };
        const closeBoardModal = () => {
            if (! openModal) return;
            openModal.style.display = 'none';
            openModal = null;
            opener?.focus();
        };

        document.querySelectorAll('[data-open-board-modal]').forEach((button) => {
            button.addEventListener('click', () => openBoardModal(button.dataset.openBoardModal, button));
        });
        document.querySelectorAll('.board-modal-backdrop').forEach((backdrop) => {
            backdrop.addEventListener('click', (e) => {
                if (e.target === backdrop || e.target.closest('[data-close-board-modal]')) closeBoardModal();
            });
        });
        document.addEventListener('keydown', (e) => {
            if (! openModal) return;
            if (e.key === 'Escape') { closeBoardModal(); return; }
            if (e.key !== 'Tab') return;
            const items = focusables(openModal);
            if (! items.length) return;
            if (e.shiftKey && document.activeElement === items[0]) { e.preventDefault(); items[items.length - 1].focus(); }
            else if (! e.shiftKey && document.activeElement === items[items.length - 1]) { e.preventDefault(); items[0].focus(); }
        });

        // Just saved an entry (the page reloads after each save), or the
        // save came back with errors: open Add evacuee again, ready for
        // the next person or showing what to fix. The save is normally
        // sent by fetch (app.js's wireModalSubmit()), which uses up the
        // redirect's flash, so it's remembered in sessionStorage across
        // the reload; the flash covers a save without JavaScript.
        const addEvacueeModal = document.getElementById('add-evacuee-modal');
        addEvacueeModal?.addEventListener('elikas:saved', () => {
            try { sessionStorage.setItem('elikas-reopen-add-evacuee', '1'); } catch (e) { /* storage blocked: just don't reopen */ }
        });
        let justSaved = false;
        try {
            justSaved = sessionStorage.getItem('elikas-reopen-add-evacuee') === '1';
            sessionStorage.removeItem('elikas-reopen-add-evacuee');
        } catch (e) { /* storage blocked */ }
        if (justSaved && addEvacueeModal) addEvacueeModal.querySelector('[data-entry-added]').style.display = 'block';
        if (addEvacueeModal && (justSaved || @json(session('ecBoardEntryAdded') || ($errors->any() && old('_board_form') === 'add-evacuee')))) {
            openBoardModal('add-evacuee-modal', document.querySelector('[data-open-board-modal="add-evacuee-modal"]'));
        }

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
    });
</script>
@endsection
