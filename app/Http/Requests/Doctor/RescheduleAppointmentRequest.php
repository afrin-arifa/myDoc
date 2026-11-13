<?php

namespace App\Http\Requests\Doctor;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment
            && $this->user()?->can('reschedule', $appointment) === true;
    }

    public function rules(): array
    {
        return [
            'slot_id' => ['required', 'integer', 'exists:appointment_slots,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
