<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Rules\BangladeshiMobileNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [Rule::requiredIf(! $this->keepsWalkIn()), 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone' => ['nullable', 'string', 'max:30', new BangladeshiMobileNumber],
            'address' => ['nullable', 'string', 'max:1000'],
            'role' => ['required', 'in:patient,doctor,admin'],
            'deactivated' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A walk-in patient may stay without an email as long as they stay a patient.
     */
    private function keepsWalkIn(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && $user->is_walk_in && $user->email === null && $this->input('role') === 'patient';
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
}
