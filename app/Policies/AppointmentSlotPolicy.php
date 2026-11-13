<?php

namespace App\Policies;

use App\Models\AppointmentSlot;
use App\Models\User;

/**
 * Doctors manage only their own slots. Admins keep their existing read-only role and do not block slots.
 * Business rules (only free, future slots) are enforced by SlotBlockingService.
 */
class AppointmentSlotPolicy
{
    public function block(User $user, AppointmentSlot $appointmentSlot): bool
    {
        return $this->owns($user, $appointmentSlot);
    }

    public function unblock(User $user, AppointmentSlot $appointmentSlot): bool
    {
        return $this->owns($user, $appointmentSlot);
    }

    private function owns(User $user, AppointmentSlot $appointmentSlot): bool
    {
        if ($user->role !== 'doctor' || $user->deactivated_at !== null) {
            return false;
        }

        $profileId = $user->doctorProfile()->value('id');

        return $profileId !== null && $appointmentSlot->doctor_profile_id === $profileId;
    }
}
