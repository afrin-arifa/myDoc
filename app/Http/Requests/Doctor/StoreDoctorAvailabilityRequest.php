<?php

namespace App\Http\Requests\Doctor;

use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDoctorAvailabilityRequest extends FormRequest
{
    private ?DoctorProfile $resolvedDoctorProfile = null;

    /**
     * The doctor context must allow managing the schedule. A doctor who has not created a profile yet passes, and
     * gets the existing "create your profile" validation message instead.
     */
    public function authorize(): bool
    {
        $this->resolvedDoctorProfile = app(DoctorWorkspace::class)->forRequest($this, DoctorAccess::MANAGE_SCHEDULE);

        return $this->resolvedDoctorProfile === null
            || $this->user()->can('manageSchedule', $this->resolvedDoctorProfile);
    }

    /**
     * The doctor the availability is created for.
     */
    public function doctorProfile(): DoctorProfile
    {
        return $this->resolvedDoctorProfile;
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'is_active' => ['sometimes', 'boolean'],
            // Must be one of the doctor's active locations; DoctorAvailabilityService checks it under the schedule lock.
            'doctor_location_id' => ['required', 'integer'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $profileId = $this->resolvedDoctorProfile?->id;

                if (! $profileId) {
                    $validator->errors()->add('availability', 'Create your doctor profile before adding availability.');

                    return;
                }

                $overlaps = DoctorAvailability::query()
                    ->where('doctor_profile_id', $profileId)
                    ->where('day_of_week', $this->integer('day_of_week'))
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
