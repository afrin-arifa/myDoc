<?php

namespace App\Policies;

use App\Models\DoctorAvailability;
use App\Models\User;
use App\Services\DoctorAccess;

/**
 * Weekly availability is managed by its doctor, or by a Doctor Assistant currently assigned to that (active)
 * doctor. Ownership comes from the availability record itself, so a modified ID or form field cannot reach
 * another doctor.
 */
class DoctorAvailabilityPolicy
{
    public function __construct(private readonly DoctorAccess $access) {}

    /**
     * Creating from the doctor's own workspace. This check has no doctor to act for, so it covers doctors only;
     * creating on a doctor's behalf is authorized with DoctorProfilePolicy::manageSchedule.
     */
    public function create(User $user): bool
    {
        return $user->role === 'doctor'
            && $this->access->canActFor($user, (int) $user->doctorProfile()->value('id'), DoctorAccess::MANAGE_SCHEDULE);
    }

    public function update(User $user, DoctorAvailability $doctorAvailability): bool
    {
        return $this->access->canActFor($user, $doctorAvailability->doctor_profile_id, DoctorAccess::MANAGE_SCHEDULE);
    }

    public function delete(User $user, DoctorAvailability $doctorAvailability): bool
    {
        return $this->access->canActFor($user, $doctorAvailability->doctor_profile_id, DoctorAccess::MANAGE_SCHEDULE);
    }
}
