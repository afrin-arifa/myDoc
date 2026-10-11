<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        $profile = $this->route('doctor');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($profile->user_id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'professional_title' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'license_number' => ['required', 'string', 'max:255', Rule::unique('doctor_profiles', 'license_number')->ignore($profile)],
            'consultation_fee' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'years_of_experience' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_accepting_patients' => ['sometimes', 'boolean'],
            'specialty_ids' => ['nullable', 'array'],
            'specialty_ids.*' => ['integer', 'exists:specialties,id'],
        ];
    }
}
