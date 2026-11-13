<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Shared by doctor invitation acceptance and password reset: a guest proves ownership of the email with a token
 * and chooses a new password.
 */
class SetNewPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255', self::passwordRule()],
        ];
    }

    public static function passwordRule(): Password
    {
        return Password::min(8)->letters()->mixedCase()->numbers();
    }
}
