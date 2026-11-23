<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorWorkspaceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Closes pending ("booked") requests whose slot has started without the doctor approving them: the appointment
 * becomes "expired" (kept as history, never deleted), its slot is released through AppointmentSlotRelease, and a
 * system audit event records it. Confirmed appointments are never touched.
 *
 * Each appointment is expired in its own transaction, locking the appointment and then its slot (the same order as
 * cancellation), and the status and start time are checked again under the lock. Running it twice, or alongside a
 * doctor confirming or rejecting the same request, therefore changes each appointment at most once.
 *
 * The patient is told by email (and SMS if they opted in), recorded in the same transaction so a rolled-back expiry
 * sends nothing. The doctor is not notified. Pending requests have no Google Calendar event, so there is nothing to
 * remove there.
 */
class PendingAppointmentExpiry
{
    public function __construct(
        private readonly AppointmentSlotRelease $slotRelease,
        private readonly DoctorWorkspaceEventService $events,
        private readonly AppointmentNotificationService $notifications,
    ) {}

    /**
     * Expire every pending request whose slot starts at or before $now (default: now, in the application timezone).
     *
     * @return int Appointments expired by this run.
     */
    public function expireDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $expired = 0;

        Appointment::query()
            ->where('status', 'booked')
            ->whereHas('slot', fn (Builder $slots) => $slots->where('starts_at', '<=', $now))
            ->select('appointments.id')
            ->chunkById(100, function ($appointments) use ($now, &$expired): void {
                foreach ($appointments as $appointment) {
                    $expired += (int) $this->expire($appointment->id, $now);
                }
            });

        return $expired;
    }

    /**
     * Expire one request if it is still pending and its slot has started. Returns whether it was expired.
     */
    public function expire(int $appointmentId, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($appointmentId, $now): bool {
            $appointment = Appointment::query()->whereKey($appointmentId)->lockForUpdate()->first();

            if ($appointment === null || $appointment->status !== 'booked') {
                return false;
            }

            $slot = AppointmentSlot::query()->whereKey($appointment->appointment_slot_id)->lockForUpdate()->first();

            if ($slot === null || $slot->starts_at->gt($now)) {
                return false;
            }

            $appointment->update(['status' => Appointment::STATUS_EXPIRED]);
            $released = $this->slotRelease->release($slot, AppointmentSlotRelease::REASON_EXPIRED, $appointment);

            $this->events->recordSystem(
                $slot->doctor_profile_id,
                DoctorWorkspaceEvent::ACTION_EXPIRED,
                appointment: $appointment,
                fromStatus: 'booked',
                toStatus: Appointment::STATUS_EXPIRED,
                context: ['source' => 'expiry', 'slot_id' => $slot->id, 'starts_at' => $slot->starts_at->toIso8601String(), 'slot_released' => $released],
            );
            $this->notifications->record($appointment, AppointmentNotification::TYPE_EXPIRED);

            return true;
        });
    }
}
