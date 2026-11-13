<?php

namespace App\Http\Requests\Profile;

use App\Rules\BangladeshiMobileNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePatientProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'patient';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user())],
            'phone' => ['nullable', 'required_if_accepted:sms_notifications_enabled', 'string', 'max:30', new BangladeshiMobileNumber],
            'sms_notifications_enabled' => ['sometimes', 'boolean'],
            'address' => ['nullable', 'string', 'max:1000'],
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required_if_accepted' => 'Add your mobile number to receive appointment SMS.',
        ];
    }
}
