{{-- Where the working event stands, for one kind of action ($what, one of
     App\Support\EventLock's): the one lock design while it is monitored,
     closed or none is open; "Registration is open" once CSWD has started
     the evacuation ($showOpen). While monitored, an official can tell CSWD
     that families are arriving ($arriving). --}}
@php
    $what = $what ?? \App\Support\EventLock::REGISTERING;
    $stateEvent = $event ?? \App\Models\EvacuationEvent::working();
    $lockReason = \App\Support\EventLock::reason($stateEvent, $what);
    $stateUser = $currentUser ?? \App\Models\LocalAuth::current();
@endphp
@if ($lockReason)
    <x-lock-notice :message="$lockReason" class="mb-6" data-event-lock>
        @if (($arriving ?? false) && $stateEvent?->isMonitoring() && $stateUser?->isBarangayOfficial() && $stateUser->can('evacuees.manage'))
            {{-- POST /evacuation-events/{id}/families-arriving: CSWD is told; nothing else changes. --}}
            <form method="POST" action="{{ route('events.families-arriving') }}" class="flex flex-wrap items-end gap-2" data-families-arriving
                data-confirm-title="Tell CSWD that families are arriving?" data-confirm="CSWD gets a notification. Registering still opens only when CSWD starts the evacuation." data-confirm-label="Tell CSWD">
                @csrf
                <div class="min-w-[16rem] flex-1">
                    <label for="arriving-note" class="label">Families already at a center? Tell CSWD (optional note)</label>
                    <input id="arriving-note" name="note" maxlength="500" class="input" placeholder="For example: about 10 families at the covered court">
                </div>
                <button type="submit" class="btn btn-secondary" data-online-only>
                    <i class="ti ti-speakerphone" aria-hidden="true"></i> Families are arriving
                </button>
            </form>
        @endif
    </x-lock-notice>
@elseif (($showOpen ?? false) && $stateEvent?->isActive())
    <div class="callout callout-success flex items-start gap-3 mb-6" role="status" data-registration-open>
        <i class="ti ti-door-enter shrink-0 mt-0.5" style="font-size: 18px;" aria-hidden="true"></i>
        <p><span class="font-semibold">Registration is open</span> for "{{ $stateEvent->name }}". CSWD started the evacuation: add families as they arrive.</p>
    </div>
@endif
