<?php

namespace App\Http\Requests\Doctor;

use App\Rules\BangladeshiMobileNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A doctor or assistant creates a patient account. Who may do so for which doctor is checked by the controller,
 * which has the doctor context.
 */
class RegisterPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email')]);
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email address already has a myDoc account. Book for the patient with their email instead.',
        ];
    }
}
