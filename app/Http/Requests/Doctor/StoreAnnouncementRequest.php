<?php

namespace App\Http\Requests\Doctor;

use App\Models\DoctorAnnouncement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A doctor or assistant posts an announcement. Which doctor it is for, and whether they may, is decided by the
 * controller from the workspace.
 */
class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.DoctorAnnouncement::TITLE_MAX_LENGTH],
            'body' => ['required', 'string', 'max:'.DoctorAnnouncement::BODY_MAX_LENGTH],
            'is_important' => ['sometimes', 'boolean'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today', 'before:'.now()->addYear()->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expires_on.after_or_equal' => 'Choose today or a later date.',
            'expires_on.before' => 'Choose a date within the next year.',
        ];
    }
}
