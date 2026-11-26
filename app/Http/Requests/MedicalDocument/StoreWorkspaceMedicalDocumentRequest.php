<?php

namespace App\Http\Requests\MedicalDocument;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A doctor's assistant uploads a document (usually a prescription) for a patient in that doctor's care. The file
 * rules are the patient upload's; the patient is chosen by their URL key and checked by the controller against
 * the doctor's care relationship. Documents uploaded by staff are never linked to an appointment.
 */
class StoreWorkspaceMedicalDocumentRequest extends StoreMedicalDocumentRequest
{
    /**
     * Who may upload is decided in the controller, which has the doctor context.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['appointment_id']);

        return [...$rules, 'patient' => ['required', 'string', 'max:20']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'patient.required' => 'Choose the patient this document is for.'];
    }

    public function patientId(): int
    {
        return User::idFromUrlKey($this->validated('patient')) ?? 0;
    }
}
