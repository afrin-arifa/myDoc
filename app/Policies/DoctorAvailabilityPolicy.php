<?php

namespace App\Policies;

use App\Models\DoctorAvailability;
use App\Models\User;

/**
 * Doctors manage only their own weekly availability. Ownership is compared with a fresh lookup of the
 * authenticated doctor's profile, so a modified availability ID or form field cannot reach another doctor.
 */
class DoctorAvailabilityPolicy
{
    public function create(User $user): bool
    {
        return $this->activeDoctorProfileId($user) !== null;
    }

    public function update(User $user, DoctorAvailability $doctorAvailability): bool
    {
        return $this->owns($user, $doctorAvailability);
    }

    public function delete(User $user, DoctorAvailability $doctorAvailability): bool
    {
        return $this->owns($user, $doctorAvailability);
    }

    private function owns(User $user, DoctorAvailability $doctorAvailability): bool
    {
        $profileId = $this->activeDoctorProfileId($user);

        return $profileId !== null && $doctorAvailability->doctor_profile_id === $profileId;
    }

    private function activeDoctorProfileId(User $user): ?int
    {
        if ($user->role !== 'doctor' || $user->deactivated_at !== null) {
            return null;
        }

        return $user->doctorProfile()->value('id');
    }
}
