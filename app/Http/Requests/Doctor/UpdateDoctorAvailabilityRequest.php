<?php

namespace App\Http\Requests\Doctor;

use App\Models\DoctorAvailability;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDoctorAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $availability = $this->route('availability');
        $doctorProfile = app(DoctorWorkspace::class)->forRequest($this, DoctorAccess::MANAGE_SCHEDULE);

        return $availability instanceof DoctorAvailability
            && $doctorProfile !== null
            && (int) $availability->doctor_profile_id === $doctorProfile->id
            && $this->user()->can('update', $availability);
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $availability = $this->route('availability');
                $profileId = $availability->doctor_profile_id;

                $overlaps = DoctorAvailability::query()
                    ->where('doctor_profile_id', $profileId)
                    ->where('day_of_week', $this->integer('day_of_week'))
                    ->where('id', '!=', $availability->id)
                    ->where('start_time', '<', $this->input('end_time'))
                    ->where('end_time', '>', $this->input('start_time'))
                    ->exists();

                if ($overlaps) {
                    $validator->errors()->add('start_time', 'This availability overlaps an existing schedule.');
                }
            },
        ];
    }
}
