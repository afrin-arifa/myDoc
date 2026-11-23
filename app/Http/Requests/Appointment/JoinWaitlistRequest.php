<?php

namespace App\Http\Requests\Appointment;

use App\Models\Appointment;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Joining a doctor's waiting list. Shapes are checked here; the doctor, location, date range, limits and follow-up
 * link are checked again by WaitlistService under the patient lock.
 */
class JoinWaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'patient' && $this->user()->deactivated_at === null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'time_of_day' => ['nullable', Rule::in(array_keys(WaitlistEntry::TIMES_OF_DAY))],
            'doctor_location_id' => ['nullable', 'integer'],
            'consultation_type' => ['required', Rule::in(Appointment::bookableConsultationTypes())],
            'follow_up_of_id' => ['nullable', 'integer'],
        ];
    }
}
