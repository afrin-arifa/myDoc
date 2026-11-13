<?php

namespace App\Http\Requests\Admin;

use App\Models\AssistantAssignment;
use App\Rules\BangladeshiMobileNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invite a new Doctor Assistant. Only name, email and phone are accepted: the role, status and password are set by
 * the provisioning service, never from input.
 */
class StoreAdminAssistantRequest extends FormRequest
{
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
