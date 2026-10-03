@php
    // Shared by the inline "Add evacuee" card and the edit modal -- one
    // implementation of the form, not two. Mirrors the web dashboard's own
    // Add Evacuee: four sections in the order staff answer them, and a
    // pinned "Will be recorded" read-back beside the save button.
    $isEditing = ! empty($entry);

    // The entry that CREATED its household edits that household's own
    // answers, and stays tied to it (see EcBoardEntryController::update()).
    $isOriginated = $isEditing && $entry->originated_household;
    $ownHousehold = $isOriginated ? $entry->household : null;
    $isLegacyNew = $isEditing && ! $isOriginated && ! $entry->household_family_local_id && ! $entry->existing_household_remote_id;

    $mode = ($isOriginated || $isLegacyNew) ? 'new' : 'existing';

    $headIsSelfNew = $isEditing ? ($isOriginated ? (bool) $entry->head_is_self : true) : true;
    $headIsSelfExisting = $isEditing && ! $isOriginated && (bool) $entry->head_is_self;
    $householdName = $isOriginated ? $ownHousehold?->name : ($isLegacyNew ? $entry->new_household_head_name : '');
    $triState = fn (?bool $value) => $value === null ? '' : ($value ? '1' : '0');

    $flagsSet = $isEditing ? collect(array_keys(\App\Models\EcBoardEntry::SECTORAL_FLAGS))->filter(fn ($f) => $entry->{$f} === true)->count() : 0;
    $submitLabel = $submitLabel ?? 'Save';
@endphp

<div class="flex flex-col" data-entry-fields>
    {{-- The board's Add evacuee panel adds to the event the board is
         showing (its own picker sits in the board header); the edit modal
         lets the event be changed. --}}
    @if (! empty($fixedEventId))
        <input type="hidden" name="evacuation_event_id" value="{{ $fixedEventId }}">
    @else
        <div class="pb-3">
            <label class="label-sm">Disaster event</label>
            <select name="evacuation_event_id" required class="input">
                <option value="">Select event</option>
                @foreach ($events as $e)
                    <option value="{{ $e->id }}" @selected($isEditing ? $entry->evacuation_event_id === $e->id : ($selectedEventId ?? null) === $e->id)>{{ $e->name }}</option>
                @endforeach
            </select>
            @if ($events->isEmpty())
                <p class="text-xs text-amber-800 mt-1">No events cached -- refresh reference data while online.</p>
            @endif
        </div>
    @endif

    {{-- 1. The person being added. --}}
    <fieldset class="entry-section">
        <legend class="entry-section-title">Who is this person?</legend>
        <div class="grid grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] gap-3">
            <div>
                <label class="label-sm">Age group</label>
                <select name="age_bracket" required class="input">
                    <option value="">Select age group</option>
                    @foreach ($ageBrackets as $key => $label)
                        <option value="{{ $key }}" @selected($isEditing && $entry->age_bracket === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label-sm">Sex</label>
                <select name="sex" required class="input">
                    <option value="">Select sex</option>
                    <option value="male" @selected($isEditing && $entry->sex === 'male')>Male</option>
                    <option value="female" @selected($isEditing && $entry->sex === 'female')>Female</option>
                </select>
            </div>
        </div>
        {{-- Shown whenever this person is being recorded as the household
             head, right under the two answers that then describe the head. --}}
        <p class="entry-head-note mt-2 items-start gap-1.5 text-xs text-brand-800" style="display: none;">
            <i class="ti ti-user-check shrink-0 mt-px" style="font-size: 14px;" aria-hidden="true"></i>
            <span>This person's age group and sex will be used for the family head.</span>
        </p>
    </fieldset>

    {{-- 2. Their household. --}}
    <fieldset class="entry-section">
        <legend class="entry-section-title">Family</legend>

        @if ($isOriginated)
            {{-- This entry created the household, so it can't be moved to
                 another one -- only the household's own answers change. --}}
            <input type="hidden" name="household_type" value="new">
            <p class="text-xs text-gray-600 mb-2">This person was added with a new family -- you can correct its details below.</p>
        @else
            <div class="bg-gray-100 border border-gray-200 rounded-lg p-0.5 grid grid-cols-2 text-sm mb-3" role="radiogroup" aria-label="Family">
                <label class="entry-mode-option cursor-pointer text-center rounded-md px-2 py-1.5 font-medium text-gray-700 hover:text-gray-900 has-[:checked]:bg-brand has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-700">
                    <input type="radio" name="household_type" value="existing" class="household-type-radio sr-only" @checked($mode === 'existing')> Already here
                </label>
                <label class="entry-mode-option cursor-pointer text-center rounded-md px-2 py-1.5 font-medium text-gray-700 hover:text-gray-900 has-[:checked]:bg-brand has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-700">
                    <input type="radio" name="household_type" value="new" class="household-type-radio sr-only" @checked($mode === 'new')> New family
                </label>
            </div>

            <div class="household-existing-field flex flex-col gap-2" @if ($mode !== 'existing') style="display: none;" @endif>
                <select name="household_family_local_id" aria-label="Family already at this center" class="household-select input">
                    <option value="">Select family</option>
                    @foreach ($households as $h)
                        {{-- data-head-open: whether this household can still take a
                             head -- none linked yet, or it's this very entry. --}}
                        <option value="{{ $h->id }}"
                            data-head-open="{{ (! $h->hasLinkedHead() || ($isEditing && (int) $h->head_ec_board_entry_id === $entry->id)) ? '1' : '0' }}"
                            @selected($isEditing && $entry->household_family_local_id === $h->id)>
                            {{ $h->displayName() }}
                        </option>
                    @endforeach
                    {{-- Households known on the central server but not yet
                         cached locally are appended here by JS after an
                         on-demand fetch while online (see initEcBoardEntryForm()
                         in app.js) -- never blocks this list offline. --}}
                    @if ($isEditing && $entry->existing_household_remote_id)
                        <option value="remote-{{ $entry->existing_household_remote_id }}" data-head-open="{{ $entry->head_is_self ? '1' : '0' }}" selected>{{ $entry->new_household_head_name }}</option>
                    @endif
                </select>
                <p class="household-empty-hint text-xs text-gray-600" @if ($households->isNotEmpty()) style="display: none;" @endif>No families registered at this center yet.</p>
                <p class="household-loading-hint text-xs text-gray-600" style="display: none;">Checking the central server for more families...</p>
                {{-- Only for a household with no head linked yet -- the real
                     head arriving later. An existing head is never replaced. --}}
                <label class="entry-existing-head items-start gap-2 text-sm text-gray-700" style="display: none;">
                    <input type="checkbox" name="head_is_self" value="1" data-head-self="existing" class="mt-0.5" @checked($headIsSelfExisting) @disabled($mode !== 'existing')>
                    <span>This person is the family head <span class="block text-xs text-gray-600">This family has no head linked yet.</span></span>
                </label>
            </div>
            <input type="hidden" name="household_label" class="household-label-input" value="{{ $isEditing && $entry->existing_household_remote_id ? $entry->new_household_head_name : '' }}">
            <input type="hidden" class="household-refresh-url" value="{{ route('evacuation-centers.households-refresh', $center) }}">
            <input type="hidden" class="household-local-remote-ids" value="{{ $households->pluck('remote_id')->filter()->implode(',') }}">
        @endif

        {{-- Asked once per NEW household, never per person. "Not yet known"
             is always allowed and is stored as null, never guessed as "no". --}}
        <div class="household-new-field flex flex-col gap-3" @if ($mode !== 'new') style="display: none;" @endif>
            <div>
                <label class="label-sm">Family name</label>
                <input type="text" name="new_household_head_name" value="{{ $householdName }}" placeholder="e.g. Juan Dela Cruz" class="input">
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="head_is_self" value="1" data-head-self="new" @checked($headIsSelfNew) @disabled($mode !== 'new')> This person is the family head
            </label>
            <div>
                <label class="label-sm">Only one family head? (single-headed)</label>
                <select name="is_single_headed" class="input">
                    <option value="" @selected($triState($ownHousehold?->is_single_headed) === '')>Not yet known</option>
                    <option value="1" @selected($triState($ownHousehold?->is_single_headed) === '1')>Yes</option>
                    <option value="0" @selected($triState($ownHousehold?->is_single_headed) === '0')>No</option>
                </select>
            </div>
        </div>
    </fieldset>

    {{-- 3. Only when the head is someone OTHER than this person: set apart
         (dashed inset) so these answers can't be mistaken for this
         person's own. --}}
    <div class="entry-head-section entry-section" role="group" aria-label="About the actual family head" style="display: none;">
        <div class="border border-dashed border-gray-400 bg-gray-50 rounded-lg p-3">
            <p class="text-xs font-semibold text-gray-800">About the actual family head</p>
            <p class="text-xs text-gray-600 mt-0.5 mb-2">Someone other than the person you're adding. Used until they're added and linked.</p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label-sm">Head's sex</label>
                    <select name="head_sex" class="input">
                        <option value="" @selected(($ownHousehold?->head_sex ?? '') === '')>Not yet known</option>
                        <option value="male" @selected($ownHousehold?->head_sex === 'male')>Male</option>
                        <option value="female" @selected($ownHousehold?->head_sex === 'female')>Female</option>
                    </select>
                </div>
                <div>
                    <label class="label-sm">Head is a minor?</label>
                    <select name="head_is_minor" class="input">
                        <option value="" @selected($triState($ownHousehold?->head_is_minor) === '')>Not yet known</option>
                        <option value="1" @selected($triState($ownHousehold?->head_is_minor) === '1')>Yes (under 18)</option>
                        <option value="0" @selected($triState($ownHousehold?->head_is_minor) === '0')>No</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- 4. Optional sectoral flags for THIS one person -- collapsed so the
         common case stays fast; opened when editing an entry that has some.
         Unticked means "not recorded", not "no". Pregnant/lactating are
         hidden and cleared for a male evacuee. --}}
    <div class="entry-section">
        <details class="entry-sectoral border border-gray-200 rounded-lg" @if ($flagsSet) open @endif>
            <summary class="cursor-pointer select-none px-3 py-2.5 text-sm text-gray-700">
                Sectoral details <span class="text-gray-600">(optional)</span>
                <span class="entry-sectoral-count badge badge-info ml-1" @if (! $flagsSet) style="display: none;" @endif>{{ $flagsSet }} ticked</span>
            </summary>
            <div class="grid grid-cols-2 gap-x-4 gap-y-2 px-3 pb-2 pt-1 text-sm text-gray-700">
                @foreach (\App\Models\EcBoardEntry::SECTORAL_FLAGS as $flag => [$label])
                    <label class="flex items-center gap-2" @if (in_array($flag, \App\Models\EcBoardEntry::FEMALE_ONLY_FLAGS, true)) data-female-only @endif>
                        <input type="checkbox" name="{{ $flag }}" value="1" class="entry-sectoral-flag" @checked($isEditing && $entry->{$flag} === true)> {{ $label }}
                    </label>
                @endforeach
            </div>
            <p class="px-3 pb-3 text-xs text-gray-600">Tick only what you know. Leaving a box unticked records nothing -- it doesn't mean "no".</p>
        </details>
    </div>

    {{-- Pinned to the bottom of whatever is scrolling (the page, or the
         modal), so the read-back and the save button stay in view however
         many questions are open above them. --}}
    <div class="sticky bottom-0 z-10 bg-white mt-4 pt-3 pb-4 border-t border-gray-200">
        <div class="rounded-lg bg-brand-50 border border-brand-100 px-3 py-2.5 mb-3" aria-live="polite">
            <p class="text-xs font-semibold text-gray-900 mb-1">Will be recorded</p>
            <ul class="entry-summary text-xs text-gray-800 space-y-0.5"></ul>
        </div>
        <button type="submit" class="btn btn-primary w-full py-2.5">
            {{ $submitLabel }}
        </button>
    </div>
</div>
