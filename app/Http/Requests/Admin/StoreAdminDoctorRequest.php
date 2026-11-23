<?php

namespace App\Http\Requests\Admin;

use App\Models\DoctorProfile;
use App\Rules\BangladeshiMobileNumber;
use App\Services\ConsultationFee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin doctor creation. There is deliberately no password, role or status input: the role is assigned on the
 * server and the doctor sets their own password through the invitation.
 */
class StoreAdminDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DoctorProfile::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30', new BangladeshiMobileNumber],
            'professional_title' => ['required', 'string', 'max:255'],
            'license_number' => ['required', 'string', 'max:255', Rule::unique('doctor_profiles', 'license_number')],
            'consultation_fee' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.ConsultationFee::MAX],
            // Optional; never more than the regular fee. Empty means follow-ups cost the consultation fee.
            'follow_up_fee' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'lte:consultation_fee'],
            'years_of_experience' => ['nullable', 'integer', 'min:0', 'max:100'],
            'qualifications' => ['nullable', 'string', 'max:500'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'is_accepting_patients' => ['sometimes', 'boolean'],
            'specialty_ids' => ['nullable', 'array'],
            'specialty_ids.*' => ['integer', 'distinct', Rule::exists('specialties', 'id')->where('is_active', true)],
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

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone')) && ($phone = BangladeshiMobileNumber::normalize($this->input('phone'))) !== null) {
            $this->merge(['phone' => $phone]);
        }
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
