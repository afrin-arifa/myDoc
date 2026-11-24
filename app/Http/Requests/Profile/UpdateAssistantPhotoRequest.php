<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use App\Services\UserPhotoStorage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A Doctor Assistant changes their own photo, and nothing else: their other details are managed by administrators.
 */
class UpdateAssistantPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ASSISTANT && $this->user()->deactivated_at === null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'photo' => UserPhotoStorage::rules(),
            'remove_photo' => ['sometimes', 'boolean'],
        ];
    }
}
