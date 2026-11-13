<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Auth\SetNewPasswordRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * A signed-in user changes their own password (shared by every role). Only the user's own account is ever updated,
 * and a deactivated account that still has a session is refused.
 *
 * Staff accounts (doctors, Doctor Assistants, admins) must keep the strength required when they accepted their
 * invitation or reset their password; patients keep the registration rule.
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->deactivated_at === null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'max:255', self::passwordRuleFor($this->user()->role)],
        ];
    }

    private static function passwordRuleFor(?string $role): Password
    {
        return $role === 'patient' ? Password::min(8) : SetNewPasswordRequest::passwordRule();
    }

    protected function passedValidation(): void
    {
        if (! Hash::check($this->string('current_password')->toString(), $this->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }
    }
}
