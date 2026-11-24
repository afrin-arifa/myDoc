<?php

namespace App\Http\Requests\Doctor;

use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Rules\BangladeshiMobileNumber;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use App\Services\WalkInPatientService;
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
            'patient_id' => ['nullable', 'required_without_all:patient_email,walk_in_name,walk_in_phone', 'prohibits:patient_email,walk_in_name,walk_in_phone', 'integer'],
            'patient_email' => ['nullable', 'required_without_all:patient_id,walk_in_name,walk_in_phone', 'prohibits:walk_in_name,walk_in_phone', 'string', 'email', 'max:255'],
            // Somebody without an account; never combined with an existing patient.
            'walk_in_name' => ['nullable', 'required_with:walk_in_phone', 'prohibits:follow_up_of_id', 'string', 'max:255'],
            'walk_in_phone' => ['nullable', 'required_with:walk_in_name', 'string', 'max:30', new BangladeshiMobileNumber],
            'walk_in_sms' => ['sometimes', 'boolean'],
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
            'patient_id.required_without_all' => 'Select a patient, enter a patient email address or add a walk-in patient.',
            'patient_email.required_without_all' => 'Select a patient, enter a patient email address or add a walk-in patient.',
            'patient_id.prohibits' => 'Choose one way to add the patient, not several.',
            'patient_email.prohibits' => 'Choose one way to add the patient, not several.',
            'walk_in_name.required_with' => 'Enter the patient\'s name.',
            'walk_in_name.prohibits' => 'A follow-up is booked for the patient of the first appointment.',
            'walk_in_phone.required_with' => 'Enter the patient\'s mobile number.',
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

                if ($this->isWalkIn()) {
                    $existing = app(WalkInPatientService::class)->existingPatient($this->resolvedDoctorProfile, $this->string('walk_in_name')->toString(), $this->string('walk_in_phone')->toString());

                    if ($existing !== null && ! $existing->is_walk_in) {
                        $validator->errors()->add('walk_in_name', 'This person is already in the patient list with an account. Choose them under Returning patient.');
                    }

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

    public function isWalkIn(): bool
    {
        return $this->filled('walk_in_name');
    }

    /**
     * The registered patient, or null for a walk-in.
     */
    public function patient(): ?User
    {
        return $this->resolvedPatient;
    }

    /**
     * Store recognisable Bangladeshi mobile numbers in the normalized 8801XXXXXXXXX form.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('walk_in_phone')) && ($phone = BangladeshiMobileNumber::normalize($this->input('walk_in_phone'))) !== null) {
            $this->merge(['walk_in_phone' => $phone]);
        }
    }

    /**
     * The doctor the appointment is booked for (authorized in authorize()).
     */
    public function doctorProfile(): DoctorProfile
    {
        return $this->resolvedDoctorProfile;
    }
}
