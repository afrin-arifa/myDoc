<?php

namespace App\Services;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentRescheduleHistory;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Doctor-side appointment actions. Every method takes the actor (the signed-in user performing the action) and the
 * doctor context (the doctor whose appointment it is). The actor must be allowed the specific ability for that
 * doctor (DoctorWorkspace / DoctorAccess); queries are scoped to the doctor context, and audit fields record the
 * actor, never the doctor on whose behalf an assistant acts.
 *
 * The doctor context may be omitted only by a doctor acting in their own workspace.
 */
class DoctorAppointmentService
{
    public function __construct(
        private readonly AppointmentNotificationService $notifications,
        private readonly DoctorWorkspace $workspace,
        private readonly DoctorWorkspaceEventService $events,
        private readonly PatientAppointmentRules $rules,
        private readonly AppointmentSlotRelease $slotRelease,
    ) {}

    public function complete(User $actor, Appointment $appointment, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::COMPLETE_APPOINTMENTS);

        DB::transaction(function () use ($actor, $doctorProfile, $appointment): void {
            $locked = $this->ownedAppointment($doctorProfile, $appointment->id);
            if ($locked->status !== 'confirmed') {
                throw ValidationException::withMessages(['status' => 'Only confirmed appointments can be completed.']);
            }
            $locked->update(['status' => 'completed']);
            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_COMPLETED, appointment: $locked, fromStatus: 'confirmed', toStatus: 'completed');
        });
    }

    /**
     * Move a booked or confirmed appointment to another free slot of the same doctor. Lock order: appointment, patient,
     * then both slots in id order (so two reschedules swapping slots cannot deadlock). The new slot is claimed and the
     * appointment moved before the old slot is released, so the old slot is only released once nothing holds it.
     */
    public function reschedule(User $actor, Appointment $appointment, int $newSlotId, ?string $reason = null, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::RESCHEDULE_APPOINTMENTS);

        DB::transaction(function () use ($actor, $doctorProfile, $appointment, $newSlotId, $reason): void {
            $locked = $this->ownedAppointment($doctorProfile, $appointment->id);
            if (! in_array($locked->status, ['booked', 'confirmed'], true)) {
                throw ValidationException::withMessages(['status' => 'Only booked or confirmed appointments can be rescheduled.']);
            }
            $this->rules->lockPatient($locked->patient()->firstOrFail());
            $slots = AppointmentSlot::query()->whereKey(array_unique([$locked->appointment_slot_id, $newSlotId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $oldSlot = $slots->get($locked->appointment_slot_id) ?? throw (new ModelNotFoundException)->setModel(AppointmentSlot::class);
            $newSlot = $slots->get($newSlotId) ?? throw (new ModelNotFoundException)->setModel(AppointmentSlot::class, [$newSlotId]);
            if ($newSlot->id === $oldSlot->id) {
                throw ValidationException::withMessages(['slot_id' => 'Choose a different appointment slot.']);
            }
            if ($newSlot->doctor_profile_id !== $doctorProfile->id || $newSlot->status !== 'available') {
                throw ValidationException::withMessages(['slot_id' => 'That slot is not available for this doctor.']);
            }
            if ($newSlot->starts_at->lte(now())) {
                throw ValidationException::withMessages(['slot_id' => 'Appointments must be scheduled in the future.']);
            }
            $this->rules->ensureNoOverlap($locked->patient, $newSlot, 'slot_id', PatientAppointmentRules::AUDIENCE_STAFF, ignoreAppointmentId: $locked->id);
            $newSlot->update(['status' => 'booked']);
            $history = AppointmentRescheduleHistory::create([
                'appointment_id' => $locked->id,
                'previous_slot_id' => $oldSlot->id,
                'new_slot_id' => $newSlot->id,
                'performed_by' => $actor->id,
                'previous_starts_at' => $oldSlot->starts_at,
                'new_starts_at' => $newSlot->starts_at,
                'reason' => $reason,
            ]);
            $locked->update(['appointment_slot_id' => $newSlot->id]);
            $this->slotRelease->release($oldSlot, AppointmentSlotRelease::REASON_RESCHEDULED, $locked, $actor);
            $this->notifications->record(
                $locked,
                AppointmentNotification::TYPE_RESCHEDULED,
                'reschedule:'.$history->id,
                ['reschedule_history_id' => $history->id]
            );
            $this->events->record(
                $actor,
                $doctorProfile,
                DoctorWorkspaceEvent::ACTION_RESCHEDULED,
                appointment: $locked,
                fromStatus: $locked->status,
                toStatus: $locked->status,
                context: [
                    'previous_slot_id' => $oldSlot->id,
                    'new_slot_id' => $newSlot->id,
                    'previous_starts_at' => $oldSlot->starts_at->toIso8601String(),
                    'new_starts_at' => $newSlot->starts_at->toIso8601String(),
                    'reschedule_history_id' => $history->id,
                ],
            );
        });

        if ($appointment->status === 'confirmed') {
            SyncAppointmentToGoogleCalendar::dispatch($appointment->id, 'sync')->afterCommit();
        }
    }

    /**
     * Confirm or reject a pending request. The appointment row is locked and its status re-checked under the lock
     * (expiry and reschedule take the same lock first), so the slot read here is the one the request holds. A request
     * whose slot has already started (starts_at <= now, Asia/Dhaka) can no longer be confirmed, even if the expiry
     * job has not run yet; it can still be rejected.
     *
     * @throws ValidationException
     */
    public function transition(User $actor, Appointment $appointment, string $status, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize(
            $actor,
            $doctorProfile,
            $status === 'rejected' ? DoctorAccess::REJECT_APPOINTMENTS : DoctorAccess::CONFIRM_APPOINTMENTS
        );

        DB::transaction(function () use ($actor, $doctorProfile, $appointment, $status): void {
            $appointment = Appointment::query()
                ->whereKey($appointment->id)
                ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $doctorProfile->id))
                ->with('slot')
                ->lockForUpdate()
                ->firstOrFail();

            if ($appointment->status !== 'booked' || ! in_array($status, ['confirmed', 'rejected'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This appointment cannot be moved to the requested status.',
                ]);
            }

            if ($status === 'confirmed' && $appointment->slot->starts_at->lte(now())) {
                throw ValidationException::withMessages([
                    'status' => 'This appointment time has already started, so the request can no longer be confirmed.',
                ]);
            }

            $appointment->update(['status' => $status]);

            if ($status === 'rejected') {
                $this->slotRelease->release($appointment->appointment_slot_id, AppointmentSlotRelease::REASON_REJECTED, $appointment, $actor);
            }

            $this->notifications->record(
                $appointment,
                $status === 'confirmed' ? AppointmentNotification::TYPE_CONFIRMED : AppointmentNotification::TYPE_REJECTED
            );
            $this->events->record(
                $actor,
                $doctorProfile,
                $status === 'confirmed' ? DoctorWorkspaceEvent::ACTION_CONFIRMED : DoctorWorkspaceEvent::ACTION_REJECTED,
                appointment: $appointment,
                fromStatus: 'booked',
                toStatus: $status,
            );
        });

        SyncAppointmentToGoogleCalendar::dispatch(
            $appointment->id,
            $status === 'confirmed' ? 'sync' : 'delete'
        )->afterCommit();
    }

    /**
     * Longest accepted cancellation reason, in characters.
     */
    public const CANCELLATION_REASON_MAX_LENGTH = 1000;

    /**
     * Staff cancellation by the doctor or an assistant currently assigned to them. Unlike patient cancellation there
     * is no 24-hour limit, but a reason is mandatory. Only booked or confirmed appointments can be cancelled.
     *
     * The appointment and its slot are locked, and the status is re-checked under the lock, so concurrent requests
     * cancel at most once (the second sees "cancelled" and fails with no notification or audit event). The slot is
     * released only when it belongs to this doctor and is still booked: a blocked slot stays blocked, and the unique
     * active-slot index prevents a second active appointment on it. The reason is stored on the appointment (staff
     * only), never in notifications or audit context.
     *
     * @throws ValidationException
     */
    public function cancel(User $actor, Appointment $appointment, string $reason, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::CANCEL_APPOINTMENTS);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::CANCELLATION_REASON_MAX_LENGTH) {
            throw ValidationException::withMessages(['reason' => 'Enter a cancellation reason of up to '.self::CANCELLATION_REASON_MAX_LENGTH.' characters.']);
        }

        DB::transaction(function () use ($actor, $doctorProfile, $appointment, $reason): void {
            $locked = $this->ownedAppointment($doctorProfile, $appointment->id);

            if (! in_array($locked->status, Appointment::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Only booked or confirmed appointments can be cancelled.']);
            }

            $slot = AppointmentSlot::query()->whereKey($locked->appointment_slot_id)->lockForUpdate()->firstOrFail();
            $previousStatus = $locked->status;

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $releaseSlot = (int) $slot->doctor_profile_id === (int) $doctorProfile->id
                && $this->slotRelease->release($slot, AppointmentSlotRelease::REASON_STAFF_CANCELLED, $locked, $actor);

            $this->notifications->record($locked, AppointmentNotification::TYPE_STAFF_CANCELLED, context: [
                'cancelled_by' => $actor->id,
                'cancelled_by_role' => (string) $actor->role,
            ]);
            $this->events->record(
                $actor,
                $doctorProfile,
                DoctorWorkspaceEvent::ACTION_CANCELLED,
                appointment: $locked,
                fromStatus: $previousStatus,
                toStatus: 'cancelled',
                context: ['source' => 'staff', 'slot_id' => $slot->id, 'starts_at' => $slot->starts_at->toIso8601String(), 'slot_released' => $releaseSlot],
            );
        });

        SyncAppointmentToGoogleCalendar::dispatch($appointment->id, 'delete')->afterCommit();
    }

    public function start(User $actor, Appointment $appointment, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::START_APPOINTMENTS);

        DB::transaction(function () use ($actor, $doctorProfile, $appointment): void {
            $appointment = Appointment::query()
                ->whereKey($appointment->id)
                ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $doctorProfile->id))
                ->with('slot')
                ->lockForUpdate()
                ->firstOrFail();

            if ($appointment->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'consultation' => 'Only confirmed appointments can be started.',
                ]);
            }

            $startsAt = CarbonImmutable::instance($appointment->slot->starts_at);
            $endsAt = CarbonImmutable::instance($appointment->slot->ends_at);
            $now = CarbonImmutable::now();
            $earlyStart = $startsAt->subMinutes((int) config('app.consultation_start_early_minutes', 15));

            if ($now->lt($earlyStart) || $now->gt($endsAt)) {
                throw ValidationException::withMessages([
                    'consultation' => 'This appointment is outside its consultation window.',
                ]);
            }

            if ($appointment->consultation_type !== 'physical') {
                throw ValidationException::withMessages([
                    'consultation' => 'No voice or video provider is configured for this appointment yet.',
                ]);
            }

            // Starting does not change the appointment's status; the event records that the consultation began.
            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_STARTED, appointment: $appointment, fromStatus: 'confirmed', toStatus: 'confirmed');
        });
    }

    private function ownedAppointment(DoctorProfile $doctorProfile, int $appointmentId): Appointment
    {
        return Appointment::query()
            ->whereKey($appointmentId)
            ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $doctorProfile->id))
            ->lockForUpdate()
            ->firstOrFail();
    }
}
