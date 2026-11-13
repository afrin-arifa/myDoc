<?php

namespace App\Policies;

use App\Models\AppointmentSlot;
use App\Models\User;
use App\Services\DoctorAccess;

/**
 * Slots are managed by their doctor, or by a Doctor Assistant currently assigned to that (active) doctor. Admins
 * keep their existing read-only role and do not block slots. Business rules (only free, future slots) are enforced
 * by SlotBlockingService. The doctor is taken from the slot record, never from request input.
 */
class AppointmentSlotPolicy
{
    public function __construct(private readonly DoctorAccess $access) {}

    public function block(User $user, AppointmentSlot $appointmentSlot): bool
    {
        return $this->access->canActFor($user, $appointmentSlot->doctor_profile_id, DoctorAccess::MANAGE_SLOTS);
    }

    public function unblock(User $user, AppointmentSlot $appointmentSlot): bool
    {
        return $this->access->canActFor($user, $appointmentSlot->doctor_profile_id, DoctorAccess::MANAGE_SLOTS);
    }
}
