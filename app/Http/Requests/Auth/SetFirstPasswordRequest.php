<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

/**
 * A patient replaces the one-time password the clinic gave them. The new password must meet the staff-set password
 * strength and may not be the one-time password itself.
 */
class SetFirstPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->mustChangePassword() === true && $this->user()->deactivated_at === null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', 'max:255', SetNewPasswordRequest::passwordRule()],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('password') && Hash::check($this->string('password')->toString(), $this->user()->password)) {
                    $validator->errors()->add('password', 'Choose a new password, not the one-time password from the clinic.');
                }
            },
        ];
    }
}
