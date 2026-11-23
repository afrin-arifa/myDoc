<?php

namespace App\Services;

use App\Events\AppointmentSlotReleased;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one place that makes an appointment slot bookable again.
 *
 * Every workflow that frees a slot calls release() inside its own transaction, after it has changed the appointment:
 * patient cancellation, staff cancellation, rejection, rescheduling (the old slot), pending-request expiry, and
 * unblocking. The slot row is locked here (callers usually already hold the lock; taking it again in the same
 * transaction is harmless), and the slot is released only when:
 *  - it is in the state that reason releases from ("booked" for an appointment ending, "blocked" for unblocking),
 *    so releasing twice is a no-op and a blocked slot is never opened by an appointment ending; and
 *  - no booked or confirmed appointment still uses it, so slot state always matches appointment state.
 *
 * A successful release dispatches AppointmentSlotReleased after the transaction commits.
 */
class AppointmentSlotRelease
{
    public const REASON_PATIENT_CANCELLED = 'patient_cancelled';

    public const REASON_STAFF_CANCELLED = 'staff_cancelled';

    public const REASON_REJECTED = 'rejected';

    public const REASON_RESCHEDULED = 'rescheduled';

    public const REASON_EXPIRED = 'expired';

    public const REASON_UNBLOCKED = 'unblocked';

    /**
     * Reasons that end an appointment's hold on its slot; everything else releases a blocked slot.
     *
     * @var list<string>
     */
    public const APPOINTMENT_REASONS = [
        self::REASON_PATIENT_CANCELLED, self::REASON_STAFF_CANCELLED, self::REASON_REJECTED,
        self::REASON_RESCHEDULED, self::REASON_EXPIRED,
    ];

    /**
     * Release the slot if it is still held. Returns whether it was released (false when it was already released, is
     * blocked, or is still used by an active appointment).
     *
     * @throws LogicException When called outside a transaction or with an unknown reason.
     */
    public function release(AppointmentSlot|int $slot, string $reason, ?Appointment $appointment = null, ?User $actor = null): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Slots must be released inside the transaction that changes the appointment or slot.');
        }

        if (! in_array($reason, [...self::APPOINTMENT_REASONS, self::REASON_UNBLOCKED], true)) {
            throw new LogicException("Unknown slot release reason [{$reason}].");
        }

        $slotId = $slot instanceof AppointmentSlot ? (int) $slot->id : $slot;
        $locked = AppointmentSlot::query()->whereKey($slotId)->lockForUpdate()->first();
        $fromStatus = $reason === self::REASON_UNBLOCKED ? 'blocked' : 'booked';

        if ($locked === null || $locked->status !== $fromStatus || $this->stillHeld($locked)) {
            return false;
        }

        $locked->update(['status' => 'available']);

        if ($slot instanceof AppointmentSlot) {
            $slot->setRawAttributes($locked->getAttributes(), true);
        }

        AppointmentSlotReleased::dispatch(
            (int) $locked->id,
            (int) $locked->doctor_profile_id,
            $locked->starts_at->toIso8601String(),
            $reason,
            $appointment?->id,
            $actor?->id,
        );

        return true;
    }

    private function stillHeld(AppointmentSlot $slot): bool
    {
        return Appointment::query()
            ->where('appointment_slot_id', $slot->id)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->exists();
    }
}
