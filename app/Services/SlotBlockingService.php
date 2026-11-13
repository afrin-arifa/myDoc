<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Blocks and unblocks individual slots. The slot row is locked for the whole check-and-update, the same lock
 * booking and rescheduling take, so a slot can never be blocked and booked at the same time.
 */
class SlotBlockingService
{
    public function block(User $doctor, AppointmentSlot $slot): void
    {
        DB::transaction(function () use ($doctor, $slot): void {
            $locked = $this->lockOwnedFutureSlot($doctor, $slot);

            if ($locked->status !== 'available' || $this->hasActiveAppointment($locked)) {
                throw ValidationException::withMessages([
                    'slot' => 'Only free slots can be blocked. Slots with a booked or confirmed appointment must be rescheduled or cancelled instead.',
                ]);
            }

            $locked->update(['status' => 'blocked']);
        });
    }

    public function unblock(User $doctor, AppointmentSlot $slot): void
    {
        DB::transaction(function () use ($doctor, $slot): void {
            $locked = $this->lockOwnedFutureSlot($doctor, $slot);

            if ($locked->status !== 'blocked') {
                throw ValidationException::withMessages(['slot' => 'This slot is not blocked.']);
            }

            $locked->update(['status' => 'available']);
        });
    }

    private function lockOwnedFutureSlot(User $doctor, AppointmentSlot $slot): AppointmentSlot
    {
        $locked = AppointmentSlot::query()
            ->whereKey($slot->id)
            ->where('doctor_profile_id', $doctor->doctorProfile()->value('id'))
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->starts_at->lte(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['slot' => 'Past or started slots cannot be changed.']);
        }

        return $locked;
    }

    private function hasActiveAppointment(AppointmentSlot $slot): bool
    {
        return Appointment::query()
            ->where('appointment_slot_id', $slot->id)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->exists();
    }
}
