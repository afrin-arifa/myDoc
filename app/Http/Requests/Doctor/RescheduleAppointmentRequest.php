<?php

namespace App\Http\Requests\Doctor;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'doctor'
            && Appointment::query()
                ->whereKey($this->route('appointment')?->id)
                ->whereHas('slot', fn ($query) => $query->where('doctor_profile_id', $this->user()->doctorProfile?->id))
                ->exists();
    }

    public function rules(): array
    {
        return [
            'slot_id' => ['required', 'integer', 'exists:appointment_slots,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
