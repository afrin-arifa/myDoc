<?php

namespace App\Http\Requests\Appointment;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

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
            'consultation_type' => ['required', 'in:physical,voice,video'],
        ];
    }
}
