<?php

namespace App\Http\Requests\Doctor;

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
            'consultation_fee' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'years_of_experience' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_accepting_patients' => ['sometimes', 'boolean'],
            'specialty_ids' => ['nullable', 'array'],
            'specialty_ids.*' => ['integer', 'exists:specialties,id'],
        ];
    }
}
