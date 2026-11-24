<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Auth\SetNewPasswordRequest;
use App\Models\AssistantAssignment;
use App\Rules\BangladeshiMobileNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a Doctor Assistant, either with an invitation email (the default) or with a first password chosen by the admin.
 * A password is accepted only with setup=password. The role and status are set by the provisioning service, never
 * from input.
 */
class StoreAdminAssistantRequest extends FormRequest
{
    public const SETUP_INVITATION = 'invitation';

    public const SETUP_PASSWORD = 'password';

    public function authorize(): bool
    {
        return $this->user()?->can('manage', AssistantAssignment::class) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30', new BangladeshiMobileNumber],
            'setup' => ['sometimes', Rule::in([self::SETUP_INVITATION, self::SETUP_PASSWORD])],
            'password' => ['exclude_unless:setup,'.self::SETUP_PASSWORD, 'required', 'string', 'confirmed', 'max:255', SetNewPasswordRequest::passwordRule()],
            // Only an active account can be assigned, so doctors are chosen here only with a password.
            'doctor_profile_ids' => ['exclude_unless:setup,'.self::SETUP_PASSWORD, 'nullable', 'array', 'max:50'],
            'doctor_profile_ids.*' => ['exclude_unless:setup,'.self::SETUP_PASSWORD, 'integer', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }

        if (is_string($this->input('phone')) && ($phone = BangladeshiMobileNumber::normalize($this->input('phone'))) !== null) {
            $this->merge(['phone' => $phone]);
        }
    }
}
