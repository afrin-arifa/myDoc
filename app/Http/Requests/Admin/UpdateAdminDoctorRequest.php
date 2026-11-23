<?php

namespace App\Http\Requests\Admin;

use App\Models\DoctorProfile;
use App\Rules\BangladeshiMobileNumber;
use App\Services\ConsultationFee;
use App\Services\DoctorPhotoStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->route('doctor');

        return $profile instanceof DoctorProfile && $this->user()?->can('update', $profile) === true
            && $this->user()->role === 'admin';
    }

    public function rules(): array
    {
        $profile = $this->route('doctor');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($profile->user_id)],
            'phone' => ['nullable', 'string', 'max:30', new BangladeshiMobileNumber],
            'address' => ['nullable', 'string', 'max:1000'],
            'professional_title' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'qualifications' => ['nullable', 'string', 'max:500'],
            'photo' => DoctorPhotoStorage::rules(),
            'remove_photo' => ['sometimes', 'boolean'],
            'license_number' => ['required', 'string', 'max:255', Rule::unique('doctor_profiles', 'license_number')->ignore($profile)],
            'consultation_fee' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.ConsultationFee::MAX],
            // Optional; never more than the regular fee. Empty means follow-ups cost the consultation fee.
            'follow_up_fee' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'lte:consultation_fee'],
            'years_of_experience' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_accepting_patients' => ['sometimes', 'boolean'],
            'specialty_ids' => ['nullable', 'array'],
            'specialty_ids.*' => ['integer', 'exists:specialties,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'follow_up_fee.lte' => 'The follow-up fee cannot be more than the consultation fee.',
            'consultation_fee.decimal' => 'Enter the consultation fee with at most two decimal places.',
            'follow_up_fee.decimal' => 'Enter the follow-up fee with at most two decimal places.',
        ];
    }

    /**
     * Store recognisable Bangladeshi mobile numbers in the normalized 8801XXXXXXXXX form.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone')) && ($phone = BangladeshiMobileNumber::normalize($this->input('phone'))) !== null) {
            $this->merge(['phone' => $phone]);
        }
    }
}
