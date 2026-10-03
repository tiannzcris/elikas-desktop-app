<?php

namespace App\Http\Requests;

use App\Models\EcBoardEntry;
use App\Models\Family;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AddEvacueeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `household_type` is a form-only field (not stored) that drives which
     * of the two household fields is required -- mirrors how
     * RegisterFamilyRequest uses displacement_type to require
     * evacuation_center_id only when relevant.
     *
     * household_family_local_id carries either a plain local Family id
     * (a household already known to this device), or a string like
     * "remote-42" for a household picked from the LIVE central list (see
     * EvacuationCenterController::refreshHouseholds()) that has no local
     * row at all -- kept as a loose string rule here, with the actual
     * format validated in withValidator() below, since a plain `integer`
     * rule can't accept both shapes.
     */
    public function rules(): array
    {
        return [
            'evacuation_event_id' => ['required', 'integer', 'exists:evacuation_events,id'],
            'sex' => ['required', 'in:male,female'],
            'age_bracket' => ['required', 'in:'.implode(',', array_keys(EcBoardEntry::AGE_BRACKETS))],
            'household_type' => ['required', 'in:existing,new'],
            'household_family_local_id' => ['nullable', 'required_if:household_type,existing', 'string', 'max:30'],
            'new_household_head_name' => ['nullable', 'required_if:household_type,new', 'string', 'max:150'],
            // Optional sectoral checkboxes (see EcBoardEntry::SECTORAL_FLAGS)
            // -- a ticked box submits "1", an unticked one submits nothing.
            'is_pwd' => ['nullable', 'boolean'],
            'is_pregnant' => ['nullable', 'boolean'],
            'is_lactating' => ['nullable', 'boolean'],
            'is_solo_parent' => ['nullable', 'boolean'],
            'is_indigenous_person' => ['nullable', 'boolean'],
            'is_4ps_beneficiary' => ['nullable', 'boolean'],
            // Household head questions -- same fields and meaning as the
            // central server's addEvacuee(). "Not yet known" submits an
            // empty value, stored as null, never guessed as "no".
            'head_is_self' => ['nullable', 'boolean'],
            'is_single_headed' => ['nullable', 'boolean'],
            'head_is_minor' => ['nullable', 'boolean'],
            'head_sex' => ['nullable', 'in:male,female'],
        ];
    }

    /**
     * In the form's own words. The defaults are built from the field
     * names ("The new household head name field is required when
     * household type is new").
     */
    public function messages(): array
    {
        return [
            'household_family_local_id.required_if' => 'Select a family already at this center.',
            'new_household_head_name.required_if' => 'Enter the family name.',
        ];
    }

    public function attributes(): array
    {
        return [
            'household_family_local_id' => 'family',
            'new_household_head_name' => 'family name',
            'household_type' => 'family',
        ];
    }

    public function headIsSelf(): bool
    {
        return $this->boolean('head_is_self');
    }

    /**
     * A NEW household's one-time answers, as stored on its local Family.
     * When this person is the head, their own sex and age group describe
     * the head, so head_sex/head_is_minor stay null here (see
     * Family::headSex()/isChildHeaded(), which read the linked head first).
     */
    public function newHouseholdAnswers(): array
    {
        $headIsSelf = $this->headIsSelf();

        return [
            'is_single_headed' => $this->nullableBoolean('is_single_headed'),
            'head_sex' => $headIsSelf ? null : $this->input('head_sex'),
            'head_is_minor' => $headIsSelf ? null : $this->nullableBoolean('head_is_minor'),
        ];
    }

    private function nullableBoolean(string $key): ?bool
    {
        return $this->filled($key) ? $this->boolean($key) : null;
    }

    /**
     * All six sectoral flags as stored: true if ticked, otherwise null
     * ("not recorded" -- never false). Returned for every flag, not just
     * the ticked ones, so unticking a box while editing a pending entry
     * really clears it.
     */
    public function sectoralFields(): array
    {
        return collect(array_keys(EcBoardEntry::SECTORAL_FLAGS))
            ->mapWithKeys(fn ($flag) => [$flag => $this->boolean($flag) ? true : null])
            ->all();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('household_type') === 'existing') {
                $value = (string) $this->input('household_family_local_id');

                if (! $this->filled('household_family_local_id')) {
                    $validator->errors()->add('household_family_local_id', 'Select a family already at this center.');
                } elseif (str_starts_with($value, 'remote-')) {
                    if (! preg_match('/^remote-\d+$/', $value)) {
                        $validator->errors()->add('household_family_local_id', 'Invalid family selection.');
                    }
                } elseif (! ctype_digit($value) || ! Family::whereKey($value)->exists()) {
                    $validator->errors()->add('household_family_local_id', 'The selected family is invalid.');
                }
            }

            if ($this->input('household_type') === 'new' && ! $this->filled('new_household_head_name')) {
                $validator->errors()->add('new_household_head_name', 'Enter the family name.');
            }

            // Same guard as the central server's addEvacuee(), checked here
            // too so the mistake is caught on this device instead of only
            // surfacing later as a sync failure.
            if ($this->input('sex') === 'male') {
                foreach (EcBoardEntry::FEMALE_ONLY_FLAGS as $flag) {
                    if ($this->boolean($flag)) {
                        $validator->errors()->add($flag, 'Only a female evacuee can be marked pregnant or lactating.');
                    }
                }
            }
        });
    }

    /**
     * Only the fields matching the chosen household_type/source are ever
     * persisted -- keeps a stray value left over from switching the radio
     * back and forth client-side from ending up saved alongside the one
     * that actually applies.
     */
    public function householdFields(): array
    {
        if ($this->input('household_type') !== 'existing') {
            return [
                'household_family_local_id' => null,
                'existing_household_remote_id' => null,
                'new_household_head_name' => $this->input('new_household_head_name'),
            ];
        }

        $value = (string) $this->input('household_family_local_id');

        if (str_starts_with($value, 'remote-')) {
            return [
                'household_family_local_id' => null,
                'existing_household_remote_id' => (int) substr($value, 7),
                // Snapshot label purely for this device's own display (see
                // EcBoardEntry::householdLabel()) -- there's no local
                // Family row to look this back up from later. Kept in
                // sync with the select's chosen option text by the form's
                // own JS (see initEcBoardEntryForm() in app.js).
                'new_household_head_name' => $this->input('household_label'),
            ];
        }

        return [
            'household_family_local_id' => (int) $value,
            'existing_household_remote_id' => null,
            'new_household_head_name' => null,
        ];
    }
}
