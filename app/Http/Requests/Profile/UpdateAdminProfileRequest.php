<?php

namespace App\Http\Requests\Profile;

use App\Rules\BangladeshiMobileNumber;
use App\Services\UserPhotoStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An administrator edits their own details. Role and account status are never accepted here.
 */
class UpdateAdminProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin' && $this->user()->deactivated_at === null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user())],
            'phone' => ['nullable', 'string', 'max:30', new BangladeshiMobileNumber],
            'address' => ['nullable', 'string', 'max:1000'],
            'photo' => UserPhotoStorage::rules(),
            'remove_photo' => ['sometimes', 'boolean'],
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
}
