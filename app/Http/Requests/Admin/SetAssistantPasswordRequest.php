<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Auth\SetNewPasswordRequest;
use App\Models\AssistantAssignment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An administrator sets an assistant's password. Errors use their own bag so they appear at this form only.
 */
class SetAssistantPasswordRequest extends FormRequest
{
    protected $errorBag = 'assistantPassword';

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
            'password' => ['required', 'string', 'confirmed', 'max:255', SetNewPasswordRequest::passwordRule()],
        ];
    }
}
