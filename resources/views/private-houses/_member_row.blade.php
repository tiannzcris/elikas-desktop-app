{{-- One other member of a private-house family (index $i). --}}
<div class="grid gap-3 sm:grid-cols-[1fr_1fr_8rem_6rem_auto] items-end" data-member-row>
    <div>
        <label class="label" for="m-{{ $i }}-first">First name</label>
        <input id="m-{{ $i }}-first" name="members[{{ $i }}][first_name]" value="{{ $m['first_name'] ?? '' }}" class="input" autocomplete="off">
    </div>
    <div>
        <label class="label" for="m-{{ $i }}-last">Last name</label>
        <input id="m-{{ $i }}-last" name="members[{{ $i }}][last_name]" value="{{ $m['last_name'] ?? '' }}" class="input" autocomplete="off">
    </div>
    <div>
        <label class="label" for="m-{{ $i }}-sex">Sex</label>
        <select id="m-{{ $i }}-sex" name="members[{{ $i }}][sex]" class="input">
            <option value="">Choose…</option>
            <option value="female" @selected(($m['sex'] ?? '') === 'female')>Female</option>
            <option value="male" @selected(($m['sex'] ?? '') === 'male')>Male</option>
        </select>
    </div>
    <div>
        <label class="label" for="m-{{ $i }}-age">Age</label>
        <input id="m-{{ $i }}-age" name="members[{{ $i }}][age]" type="number" min="0" max="120" value="{{ $m['age'] ?? '' }}" class="input">
    </div>
    <button type="button" class="btn-icon btn-icon-danger mb-0.5" data-remove-member aria-label="Remove this member">
        <i class="ti ti-x" aria-hidden="true"></i>
    </button>
</div>
