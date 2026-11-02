<?php

namespace App\Http\Requests\Doctor;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreDoctorAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'doctor';
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:users,id'],
            'slot_id' => ['required', 'integer', 'exists:appointment_slots,id'],
            'consultation_type' => ['required', 'in:physical,voice,video'],
            'provider_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function passedValidation(): void
    {
        abort_unless(
            User::query()->whereKey($this->integer('patient_id'))->where('role', 'patient')->exists(),
            422,
            'Only registered patients can be booked.'
        );
    }
}
