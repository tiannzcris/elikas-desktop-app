<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RegisterFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mirrors the central server's RegisterFamilyRequest as closely as
     * makes sense for a local-only save -- exists: checks here run against
     * THIS device's local SQLite cache tables (barangays/evacuation_events/
     * evacuation_centers), not the central database, since that's Laravel's
     * default connection for this whole project.
     */
    public function rules(): array
    {
        return [
            'evacuation_event_id' => ['required', 'integer', 'exists:evacuation_events,id'],
            'barangay_id' => ['required', 'integer', 'exists:barangays,id'],
            'home_address' => ['nullable', 'string', 'max:255'],
            'displacement_type' => ['required', 'in:inside_center,outside_center'],
            'evacuation_center_id' => ['nullable', 'required_if:displacement_type,inside_center', 'integer', 'exists:evacuation_centers,id'],
            'is_4ps_beneficiary' => ['boolean'],

            'members' => ['required', 'array', 'min:1'],
            'members.*.first_name' => ['required', 'string', 'max:100'],
            'members.*.middle_name' => ['nullable', 'string', 'max:100'],
            'members.*.last_name' => ['required', 'string', 'max:100'],
            'members.*.suffix' => ['nullable', 'string', 'max:10'],
            'members.*.sex' => ['required', 'in:male,female'],
            'members.*.date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'members.*.civil_status' => ['nullable', 'in:single,married,widowed,separated,divorced'],
            'members.*.contact_number' => ['nullable', 'string', 'max:20'],
            'members.*.is_pwd' => ['boolean'],
            'members.*.pwd_type' => ['nullable', 'required_if:members.*.is_pwd,true', 'string', 'max:100'],
            'members.*.is_pregnant' => ['boolean'],
            'members.*.is_lactating' => ['boolean'],
            'members.*.is_solo_parent' => ['boolean'],
            'members.*.is_indigenous_person' => ['boolean'],
            'members.*.is_4ps_beneficiary' => ['boolean'],
            'members.*.is_head_of_family' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $members = $this->input('members', []);
            $headCount = collect($members)->filter(fn ($m) => ! empty($m['is_head_of_family']))->count();

            if ($headCount !== 1) {
                $validator->errors()->add(
                    'members',
                    "Exactly one member must be marked as head of family (found {$headCount})."
                );
            }
        });
    }
}
