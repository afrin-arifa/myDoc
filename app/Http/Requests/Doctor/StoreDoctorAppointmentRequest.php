<?php

namespace App\Http\Requests\Doctor;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDoctorAppointmentRequest extends FormRequest
{
    private ?User $resolvedPatient = null;

    public function authorize(): bool
    {
        return $this->user()?->role === 'doctor'
            && $this->user()->can('create', Appointment::class);
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['nullable', 'required_without:patient_email', 'prohibits:patient_email', 'integer'],
            'patient_email' => ['nullable', 'required_without:patient_id', 'string', 'email', 'max:255'],
            'patient_label' => ['nullable', 'string', 'max:500'],
            'slot_id' => ['required', 'integer', 'exists:appointment_slots,id'],
            'consultation_type' => ['required', 'in:physical,voice,video'],
            'provider_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patient_id.required_without' => 'Select a patient or enter a patient email address.',
            'patient_email.required_without' => 'Select a patient or enter a patient email address.',
            'patient_id.prohibits' => 'Select an existing patient or enter an email address, not both.',
        ];
    }

    /**
     * Resolve the patient. A patient chosen by ID must already have an appointment history with this doctor;
     * a first-time patient can only be booked by their exact email address. Both paths require an active
     * patient account, and failures share one message so the form cannot be used to probe accounts.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $profileId = $this->user()?->doctorProfile?->id;
                $patients = User::query()->activePatients();

                $this->resolvedPatient = match (true) {
                    $profileId === null => null,
                    $this->filled('patient_id') => $patients
                        ->withAppointmentsForDoctor($profileId)
                        ->whereKey($this->integer('patient_id'))
                        ->first(),
                    default => $patients
                        ->where('email', $this->string('patient_email')->trim()->toString())
                        ->first(),
                };

                if ($this->resolvedPatient === null) {
                    $validator->errors()->add(
                        $this->filled('patient_id') ? 'patient_id' : 'patient_email',
                        'No eligible patient account was found.'
                    );
                }
            },
        ];
    }

    public function patient(): User
    {
        return $this->resolvedPatient;
    }
}
