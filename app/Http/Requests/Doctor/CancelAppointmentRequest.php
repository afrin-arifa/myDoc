<?php

namespace App\Http\Requests\Doctor;

use App\Models\Appointment;
use App\Services\DoctorAppointmentService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff cancellation (doctor or assigned Doctor Assistant). The reason is mandatory; surrounding whitespace is
 * trimmed by the global TrimStrings middleware, so a whitespace-only reason is rejected as empty.
 */
class CancelAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && $this->user()?->can('cancel', $appointment) === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:'.DoctorAppointmentService::CANCELLATION_REASON_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Enter the reason for cancelling this appointment.',
            'reason.max' => 'The cancellation reason may not be longer than '.DoctorAppointmentService::CANCELLATION_REASON_MAX_LENGTH.' characters.',
        ];
    }
}
