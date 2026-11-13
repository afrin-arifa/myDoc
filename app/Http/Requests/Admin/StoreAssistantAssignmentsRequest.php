<?php

namespace App\Http\Requests\Admin;

use App\Models\AssistantAssignment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Doctors to assign to an assistant. Eligibility (active doctor, active assistant, not already assigned) is decided
 * by AssistantAssignmentService inside its transaction, so it cannot change between validation and the write.
 */
class StoreAssistantAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', AssistantAssignment::class) === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'doctor_profile_ids' => ['required', 'array', 'min:1', 'max:50'],
            'doctor_profile_ids.*' => ['required', 'integer', 'distinct', 'exists:doctor_profiles,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doctor_profile_ids.required' => 'Choose at least one doctor.',
            'doctor_profile_ids.*.exists' => 'Only active doctors can be assigned.',
        ];
    }

    /**
     * @return list<int>
     */
    public function doctorProfileIds(): array
    {
        return array_map('intval', $this->validated('doctor_profile_ids'));
    }
}
