<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Blocks and unblocks individual slots. The slot row is locked for the whole check-and-update, the same lock
 * booking and rescheduling take, so a slot can never be blocked and booked at the same time.
 *
 * The actor must be allowed to manage slots for the doctor context (the doctor, or an assistant assigned to them),
 * and the slot must belong to that doctor. The context may be omitted only by a doctor in their own workspace.
 */
class SlotBlockingService
{
    public function __construct(
        private readonly DoctorWorkspace $workspace,
        private readonly DoctorWorkspaceEventService $events,
    ) {}

    public function block(User $actor, AppointmentSlot $slot, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SLOTS);

        DB::transaction(function () use ($actor, $doctorProfile, $slot): void {
            $locked = $this->lockOwnedFutureSlot($doctorProfile, $slot);

            if ($locked->status !== 'available' || $this->hasActiveAppointment($locked)) {
                throw ValidationException::withMessages([
                    'slot' => 'Only free slots can be blocked. Slots with a booked or confirmed appointment must be rescheduled or cancelled instead.',
                ]);
            }

            $locked->update(['status' => 'blocked']);
            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_SLOT_BLOCKED, subject: $locked, fromStatus: 'available', toStatus: 'blocked', context: ['starts_at' => $locked->starts_at->toIso8601String()]);
        });
    }

    public function unblock(User $actor, AppointmentSlot $slot, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SLOTS);

        DB::transaction(function () use ($actor, $doctorProfile, $slot): void {
            $locked = $this->lockOwnedFutureSlot($doctorProfile, $slot);

            if ($locked->status !== 'blocked') {
                throw ValidationException::withMessages(['slot' => 'This slot is not blocked.']);
            }

            $locked->update(['status' => 'available']);
            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_SLOT_UNBLOCKED, subject: $locked, fromStatus: 'blocked', toStatus: 'available', context: ['starts_at' => $locked->starts_at->toIso8601String()]);
        });
    }

    private function lockOwnedFutureSlot(DoctorProfile $doctorProfile, AppointmentSlot $slot): AppointmentSlot
    {
        $locked = AppointmentSlot::query()
            ->whereKey($slot->id)
            ->where('doctor_profile_id', $doctorProfile->id)
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
