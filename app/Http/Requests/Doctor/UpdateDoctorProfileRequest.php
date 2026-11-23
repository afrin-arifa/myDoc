<?php

namespace App\Http\Requests\Doctor;

use App\Services\ConsultationFee;
use App\Services\DoctorPhotoStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDoctorProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->user()?->doctorProfile;

        return $this->user()?->role === 'doctor'
            && ($profile === null || $this->user()->can('update', $profile));
    }

    public function rules(): array
    {
        $profileId = $this->user()?->doctorProfile?->id;

        return [
            'professional_title' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'qualifications' => ['nullable', 'string', 'max:500'],
            'photo' => DoctorPhotoStorage::rules(),
            'remove_photo' => ['sometimes', 'boolean'],
            'license_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('doctor_profiles', 'license_number')->ignore($profileId),
            ],
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
}
