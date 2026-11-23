<?php

namespace App\Http\Requests\Appointment;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'patient'
            && $this->user()->can('create', Appointment::class);
    }

    public function rules(): array
    {
        return [
            'consultation_type' => ['required', Rule::in(Appointment::bookableConsultationTypes())],
            // Eligibility (own completed appointment with this doctor) is decided by the booking service.
            'follow_up_of_id' => ['nullable', 'integer'],
        ];
    }
}
