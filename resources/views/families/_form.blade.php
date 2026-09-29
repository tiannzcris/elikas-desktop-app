@php
    // Presence of $family (only ever passed by edit()) is what switches
    // this whole partial -- form target, submit label, pre-selected values,
    // and the members JS starts from -- into edit mode. create() always
    // passes null explicitly, so this stays a plain isset-free check.
    $isEditing = ! empty($family);
@endphp
<div class="modal modal-pop max-w-3xl" data-register-family-modal>
    <div class="modal-header">
        <div>
            <h1 class="modal-title">{{ $isEditing ? 'Edit pending registration' : 'Register a family' }}</h1>
            <p class="text-sm text-gray-600 mt-0.5">
                @if ($isEditing)
                    Still saved only on this device -- fix what's needed, then sync when you're back online.
                @else
                    Saved to this device immediately -- no internet needed. Sync later when you're back online.
                @endif
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="modal-close-btn btn-icon" aria-label="Close">
            <i class="ti ti-x" style="font-size: 18px;" aria-hidden="true"></i>
        </a>
    </div>

    <div class="form-errors callout callout-danger mx-6 mt-4" role="alert" @if (! $errors->any()) style="display: none;" @endif>
        {{ $errors->first() }}
    </div>

    <form method="POST" action="{{ $isEditing ? route('families.update', $family) : route('families.store') }}" class="flex flex-col gap-4 p-6">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif
        <p class="text-xs text-gray-600 -mb-1">Fields marked with <span class="text-red-700 font-semibold">*</span> are required.</p>
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 grid grid-cols-2 gap-4">
            <div>
                <label class="label">Barangay <span class="text-red-700">*</span></label>
                <select name="barangay_id" required class="input">
                    <option value="">Select barangay</option>
                    @foreach ($barangays as $b)
                        {{-- New registration (not editing): defaults to THIS
                             staff account's own barangay, if it has one (a
                             CSWD/admin account with no single barangay just
                             gets today's blank default). Barangay stays a
                             fully free choice either way -- an evacuee's home
                             barangay can genuinely differ from whichever
                             barangay the registering staff belongs to -- this
                             only narrows the default starting point, and by
                             extension (see app.js) the center dropdown that
                             populates from it, for the common case of
                             registering someone at your own barangay/center
                             during fast field work. $currentUser->barangay_id
                             is the CENTRAL server's own (remote) barangay id
                             from login, so it's compared against $b->remote_id
                             here, not $b->id (this cache table's own local id). --}}
                        <option value="{{ $b->id }}" data-remote-id="{{ $b->remote_id }}" @selected($isEditing ? $family->barangay_id === $b->id : $currentUser->barangay_id === $b->remote_id)>{{ $b->name }}</option>
                    @endforeach
                </select>
                <div class="mt-3">
                    <label class="label">Street/Sitio Address <span class="text-gray-600 font-normal">(optional)</span></label>
                    <input type="text" name="home_address" value="{{ old('home_address', $isEditing ? $family->home_address : '') }}" placeholder="e.g. Purok 3, Sitio Malinao" class="input">
                </div>
            </div>
            <div>
                <label class="label">Disaster event <span class="text-red-700">*</span></label>
                <select name="evacuation_event_id" required class="input">
                    <option value="">Select event</option>
                    @foreach ($events as $e)
                        <option value="{{ $e->id }}" @selected($isEditing && $family->evacuation_event_id === $e->id)>{{ $e->name }}</option>
                    @endforeach
                    @if ($isEditing && ! $events->contains('id', $family->evacuation_event_id))
                        {{-- The family's current event isn't in the valid (non-closed, cached) list -- e.g. exactly
                             the "deleted upstream, local cache never refreshed since" case this edit screen exists
                             to fix. Shown so the select doesn't just silently fall back to blank with no explanation,
                             but deliberately NOT selected: submitting without changing it re-fails validation with
                             the same clear error, prompting an actual choice of a valid event instead of a no-op save. --}}
                        <option value="{{ $family->evacuation_event_id }}" disabled>{{ $family->evacuationEvent->name ?? 'Unknown event' }} (no longer available -- pick a current event)</option>
                    @endif
                </select>
                @if ($events->isEmpty())
                    <p class="text-xs text-amber-800 mt-1">No events cached -- refresh reference data from the dashboard while online.</p>
                @endif
            </div>
            <div>
                <label class="label">Displacement type</label>
                <select name="displacement_type" id="displacement_type" required class="input">
                    <option value="inside_center" @selected(! $isEditing || $family->displacement_type === 'inside_center')>Inside an evacuation center</option>
                    <option value="outside_center" @selected($isEditing && $family->displacement_type === 'outside_center')>Outside (evacuated to relatives/other location)</option>
                </select>
            </div>
            <div id="center-field" @if ($isEditing && $family->displacement_type !== 'inside_center') style="display: none;" @endif>
                <label class="label">Evacuation center <span class="text-red-700">*</span></label>
                <select name="evacuation_center_id" class="input">
                    <option value="">Select center</option>
                </select>
                <p class="help">Populated once you pick a barangay above.</p>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700 col-span-2">
                <input type="checkbox" name="is_4ps_beneficiary" value="1" @checked($isEditing && $family->is_4ps_beneficiary)> Household is a 4Ps beneficiary
            </label>
        </div>

        <div>
            <div class="flex items-center justify-between mb-3">
                <h2 class="card-title">Household members</h2>
                <button type="button" id="add-member-btn" class="btn btn-sm btn-secondary">+ Add another member</button>
            </div>
            <div id="members-container" class="flex flex-col gap-4"></div>
        </div>

        <button type="submit" class="btn btn-primary w-fit">
            {{ $isEditing ? 'Save changes (offline)' : 'Save family (offline)' }}
        </button>
    </form>
</div>

<script type="application/json" id="register-family-data">{!! json_encode([
    'centers' => $centers ?? [],
    'knownHouseholds' => $knownHouseholds ?? [],
    'familiesSearchUrl' => route('families.index'),
    // The center <select>'s options are only ever populated by JS, on the
    // barangay <select>'s change event -- which never fires on page load,
    // so a pre-selected barangay alone wouldn't produce a pre-selected
    // center. Passing the target value separately lets initRegisterFamilyForm()
    // run that same population logic once up front when editing, then
    // select this id in the freshly-populated list.
    'currentCenterId' => $isEditing ? $family->evacuation_center_id : null,
    // Only present when editing -- see initRegisterFamilyForm() in app.js.
    // Sent as plain arrays/values (not Eloquent casts) so the JS side has
    // no model-specific shape to know about, matching the rest of this
    // script tag's already-plain data.
    'existingMembers' => $isEditing ? $family->evacuees->map(fn ($e) => [
        'first_name' => $e->first_name,
        'middle_name' => $e->middle_name,
        'last_name' => $e->last_name,
        'suffix' => $e->suffix,
        'sex' => $e->sex,
        'date_of_birth' => optional($e->date_of_birth)->format('Y-m-d'),
        'contact_number' => $e->contact_number,
        'is_head_of_family' => (bool) $e->is_head_of_family,
        'is_pwd' => (bool) $e->is_pwd,
        'pwd_type' => $e->pwd_type,
        'is_pregnant' => (bool) $e->is_pregnant,
        'is_lactating' => (bool) $e->is_lactating,
        'is_solo_parent' => (bool) $e->is_solo_parent,
        'is_indigenous_person' => (bool) $e->is_indigenous_person,
    ])->values() : null,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>
