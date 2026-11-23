<?php

namespace App\Http\Requests\Doctor;

use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDoctorAppointmentRequest extends FormRequest
{
    private ?User $resolvedPatient = null;

    private ?DoctorProfile $resolvedDoctorProfile = null;

    public function authorize(): bool
    {
        $this->resolvedDoctorProfile = app(DoctorWorkspace::class)->forRequest($this, DoctorAccess::BOOK_APPOINTMENTS);

        return $this->resolvedDoctorProfile !== null
            && $this->user()->can('bookAppointments', $this->resolvedDoctorProfile);
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['nullable', 'required_without:patient_email', 'prohibits:patient_email', 'integer'],
            'patient_email' => ['nullable', 'required_without:patient_id', 'string', 'email', 'max:255'],
            'patient_label' => ['nullable', 'string', 'max:500'],
            'slot_id' => ['required', 'integer', 'exists:appointment_slots,id'],
            // Eligibility (this patient's completed appointment with this doctor) is decided by the booking service.
            'follow_up_of_id' => ['nullable', 'integer'],
            'consultation_type' => ['required', Rule::in(Appointment::bookableConsultationTypes())],
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

                $profileId = $this->resolvedDoctorProfile?->id;
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

    /**
     * The doctor the appointment is booked for (authorized in authorize()).
     */
    public function doctorProfile(): DoctorProfile
    {
        return $this->resolvedDoctorProfile;
    }
}
