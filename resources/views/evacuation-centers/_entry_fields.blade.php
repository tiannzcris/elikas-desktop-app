@php
    // Shared by the inline "Add Evacuee" card on the center detail page and
    // the edit modal below -- exactly the same field set either way, same
    // reasoning as families/_form.blade.php's own $isEditing split: one
    // implementation to keep in sync, not two.
    $isEditing = ! empty($entry);
    $isExistingHousehold = $isEditing && $entry->household_family_local_id !== null;
@endphp
<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="text-sm text-gray-600 block mb-1">Disaster event</label>
        <select name="evacuation_event_id" required class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
            <option value="">Select event</option>
            @foreach ($events as $e)
                <option value="{{ $e->id }}" @selected($isEditing ? $entry->evacuation_event_id === $e->id : ($selectedEventId ?? null) === $e->id)>{{ $e->name }}</option>
            @endforeach
        </select>
        @if ($events->isEmpty())
            <p class="text-xs text-amber-600 mt-1">No events cached -- refresh reference data while online.</p>
        @endif
    </div>
    <div>
        <label class="text-sm text-gray-600 block mb-1">Sex</label>
        <select name="sex" required class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
            <option value="">Select sex</option>
            <option value="male" @selected($isEditing && $entry->sex === 'male')>Male</option>
            <option value="female" @selected($isEditing && $entry->sex === 'female')>Female</option>
        </select>
    </div>
    <div class="col-span-2">
        <label class="text-sm text-gray-600 block mb-1">Age bracket</label>
        <select name="age_bracket" required class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
            <option value="">Select age bracket</option>
            @foreach ($ageBrackets as $key => $label)
                <option value="{{ $key }}" @selected($isEditing && $entry->age_bracket === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-span-2 bg-gray-50 border border-gray-100 rounded-2xl p-3">
        <p class="text-sm text-gray-600 mb-2">Household</p>
        <div class="flex gap-4 text-sm text-gray-700 mb-3">
            <label class="flex items-center gap-1.5">
                <input type="radio" name="household_type" value="existing" class="household-type-radio" @checked($isEditing ? $isExistingHousehold : true)> Existing household
            </label>
            <label class="flex items-center gap-1.5">
                <input type="radio" name="household_type" value="new" class="household-type-radio" @checked($isEditing && ! $isExistingHousehold)> New household
            </label>
        </div>
        <div class="household-existing-field" @if ($isEditing && ! $isExistingHousehold) style="display: none;" @endif>
            <select name="household_family_local_id" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                <option value="">Select household</option>
                @foreach ($households as $h)
                    <option value="{{ $h->id }}" @selected($isEditing && $entry->household_family_local_id === $h->id)>
                        {{ $h->evacuees->firstWhere('is_head_of_family', true)?->full_name ?? 'Household #'.$h->id }}
                    </option>
                @endforeach
            </select>
            @if ($households->isEmpty())
                <p class="text-xs text-gray-400 mt-1">No households registered at this center yet.</p>
            @endif
        </div>
        <div class="household-new-field" @if (! ($isEditing && ! $isExistingHousehold)) style="display: none;" @endif>
            <input type="text" name="new_household_head_name" value="{{ $isEditing ? $entry->new_household_head_name : '' }}" placeholder="New household head's full name" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
        </div>
    </div>
</div>
