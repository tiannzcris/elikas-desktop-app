<?php

namespace App\Http\Requests;

use App\Models\EcBoardEntry;
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
     */
    public function rules(): array
    {
        return [
            'evacuation_event_id' => ['required', 'integer', 'exists:evacuation_events,id'],
            'sex' => ['required', 'in:male,female'],
            'age_bracket' => ['required', 'in:'.implode(',', array_keys(EcBoardEntry::AGE_BRACKETS))],
            'household_type' => ['required', 'in:existing,new'],
            'household_family_local_id' => ['nullable', 'required_if:household_type,existing', 'integer', 'exists:families,id'],
            'new_household_head_name' => ['nullable', 'required_if:household_type,new', 'string', 'max:150'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('household_type') === 'existing' && ! $this->filled('household_family_local_id')) {
                $validator->errors()->add('household_family_local_id', 'Select an existing household.');
            }

            if ($this->input('household_type') === 'new' && ! $this->filled('new_household_head_name')) {
                $validator->errors()->add('new_household_head_name', 'Enter the new household\'s head name.');
            }
        });
    }

    /**
     * Only the field matching the chosen household_type is ever persisted --
     * keeps a stray value left over from switching the radio back and forth
     * client-side from ending up saved alongside the one that actually
     * applies.
     */
    public function householdFields(): array
    {
        return $this->input('household_type') === 'existing'
            ? ['household_family_local_id' => $this->input('household_family_local_id'), 'new_household_head_name' => null]
            : ['household_family_local_id' => null, 'new_household_head_name' => $this->input('new_household_head_name')];
    }
}
