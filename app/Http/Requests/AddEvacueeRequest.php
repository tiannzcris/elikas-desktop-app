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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('household_type') === 'existing') {
                $value = (string) $this->input('household_family_local_id');

                if (! $this->filled('household_family_local_id')) {
                    $validator->errors()->add('household_family_local_id', 'Select an existing household.');
                } elseif (str_starts_with($value, 'remote-')) {
                    if (! preg_match('/^remote-\d+$/', $value)) {
                        $validator->errors()->add('household_family_local_id', 'Invalid household selection.');
                    }
                } elseif (! ctype_digit($value) || ! Family::whereKey($value)->exists()) {
                    $validator->errors()->add('household_family_local_id', 'The selected household is invalid.');
                }
            }

            if ($this->input('household_type') === 'new' && ! $this->filled('new_household_head_name')) {
                $validator->errors()->add('new_household_head_name', 'Enter the new household\'s head name.');
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
