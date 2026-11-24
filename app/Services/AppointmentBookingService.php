<?php

namespace App\Services;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentBookingService
{
    public function __construct(
        private readonly AppointmentNotificationService $notifications,
        private readonly DoctorWorkspace $workspace,
        private readonly DoctorWorkspaceEventService $events,
        private readonly PatientAppointmentRules $rules,
        private readonly AppointmentSlotRelease $slotRelease,
    ) {}

    /**
     * Book a free slot for the patient. If the slot is already held by an active appointment of this same patient
     * (for example a second click on the booking button), that appointment is returned instead of an error; its
     * wasRecentlyCreated flag is false, so callers can tell the two cases apart.
     *
     * The patient row is locked first, so simultaneous requests by the same patient are checked one after another
     * against the active-appointment limit and the overlap rule (PatientAppointmentRules).
     *
     * With $followUpOfId the request is a follow-up of that appointment, which must be the patient's own completed
     * appointment with the slot's doctor and have no other active follow-up; otherwise it is refused.
     *
     * A "held" slot can only be booked with $waitlistOfferId: the open offer that holds it for this patient. The offer
     * is locked after the slot (the same order the waiting list uses), so accepting and expiring cannot both win.
     */
    public function book(User $patient, int $slotId, string $consultationType, ?int $followUpOfId = null, ?int $waitlistOfferId = null): Appointment
    {
        $this->ensureBookableConsultationType($consultationType);

        return DB::transaction(function () use ($patient, $slotId, $consultationType, $followUpOfId, $waitlistOfferId): Appointment {
            $this->rules->lockPatient($patient);
            $slot = AppointmentSlot::query()->lockForUpdate()->find($slotId);
            $offer = $waitlistOfferId === null ? null : WaitlistOffer::query()->with('entry')->lockForUpdate()->find($waitlistOfferId);

            $slot?->loadMissing('doctorProfile.user');

            $existing = $slot?->status === 'booked'
                ? Appointment::query()
                    ->where('appointment_slot_id', $slot->id)
                    ->where('patient_id', $patient->id)
                    ->whereIn('status', Appointment::ACTIVE_STATUSES)
                    ->first()
                : null;

            if ($existing !== null) {
                return $existing;
            }

            $heldForThisOffer = $slot !== null
                && $offer?->isOpen() === true
                && $slot->status === 'held'
                && (int) $offer->appointment_slot_id === (int) $slot->id
                && (int) $offer->entry->patient_id === (int) $patient->id;

            if ($waitlistOfferId !== null && ! $heldForThisOffer) {
                throw ValidationException::withMessages([
                    'offer' => 'This offer is no longer available. You are still on the waiting list unless you left it.',
                ]);
            }

            if (! $slot
                || ($slot->status !== 'available' && ! $heldForThisOffer)
                || $slot->starts_at->lte(CarbonImmutable::now())
                || ! $slot->doctorProfile?->isPubliclyListed()) {
                throw ValidationException::withMessages([
                    'slot' => 'This appointment slot is no longer available.',
                ]);
            }

            $original = $this->rules->followUpOriginal($patient, $followUpOfId, (int) $slot->doctor_profile_id);
            $this->rules->ensureWithinActiveLimit($patient, 'slot');
            $this->rules->ensureNoOverlap($patient, $slot, 'slot');
            $fee = ConsultationFee::forNewAppointment((int) $slot->doctor_profile_id, $original !== null);

            $appointment = Appointment::create([
                'appointment_slot_id' => $slot->id,
                'patient_id' => $patient->id,
                'follow_up_of_id' => $original?->id,
                'status' => 'booked',
                'consultation_type' => $consultationType,
                // Snapshot of the fee that applies now; never recalculated afterwards.
                'consultation_fee' => $fee['fee'],
                'fee_type' => $fee['type'],
                'booked_at' => now(),
            ]);

            $slot->update(['status' => 'booked']);

            if ($offer !== null) {
                $offer->update(['status' => WaitlistOffer::STATUS_ACCEPTED, 'responded_at' => now()]);
                $offer->entry->update(['status' => WaitlistEntry::STATUS_BOOKED, 'booked_appointment_id' => $appointment->id]);
            }

            $this->notifications->record($appointment, AppointmentNotification::TYPE_BOOKED);
            $this->events->record(
                $patient,
                $slot->doctor_profile_id,
                DoctorWorkspaceEvent::ACTION_BOOKED,
                appointment: $appointment,
                toStatus: 'booked',
                context: [
                    'source' => $offer === null ? 'patient' : 'waitlist',
                    'slot_id' => $slot->id,
                    'starts_at' => $slot->starts_at->toIso8601String(),
                    'consultation_type' => $consultationType,
                    ...$this->followUpContext($original),
                    ...($offer === null ? [] : ['waitlist_offer_id' => (int) $offer->id]),
                ],
            );

            return $appointment;
        });
    }

    public function cancel(User $patient, int $appointmentId): void
    {
        DB::transaction(function () use ($patient, $appointmentId): void {
            $appointment = Appointment::query()
                ->where('patient_id', $patient->id)
                ->lockForUpdate()
                ->findOrFail($appointmentId);
            $slot = AppointmentSlot::query()->lockForUpdate()->findOrFail($appointment->appointment_slot_id);

            if (! in_array($appointment->status, ['booked', 'confirmed'], true)) {
                throw ValidationException::withMessages([
                    'appointment' => 'This appointment cannot be cancelled.',
                ]);
            }

            if (CarbonImmutable::now()->gte(Appointment::patientCancellationDeadline($slot->starts_at))) {
                throw ValidationException::withMessages([
                    'appointment' => 'Appointments can only be cancelled at least '.Appointment::PATIENT_CANCELLATION_NOTICE_HOURS.' hours before they start.',
                ]);
            }

            $previousStatus = $appointment->status;
            $appointment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            $this->slotRelease->release($slot, AppointmentSlotRelease::REASON_PATIENT_CANCELLED, $appointment, $patient);
            $this->notifications->record($appointment, AppointmentNotification::TYPE_CANCELLED);
            $this->events->record(
                $patient,
                $slot->doctor_profile_id,
                DoctorWorkspaceEvent::ACTION_CANCELLED,
                appointment: $appointment,
                fromStatus: $previousStatus,
                toStatus: 'cancelled',
                context: ['source' => 'patient', 'slot_id' => $slot->id, 'starts_at' => $slot->starts_at->toIso8601String()],
            );
        });

        SyncAppointmentToGoogleCalendar::dispatch($appointmentId, 'delete')->afterCommit();
    }

    /**
     * Book a slot on a doctor's behalf. The actor (the doctor, or an assistant assigned to them) must be allowed to
     * book for the doctor context, and the slot must belong to that doctor. The context may be omitted only by a
     * doctor booking in their own workspace. $followUpOfId follows the same rules as for patients (book()).
     */
    public function bookForDoctor(User $actor, User $patient, int $slotId, string $consultationType, ?string $notes = null, ?DoctorProfile $doctorProfile = null, ?int $followUpOfId = null): Appointment
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::BOOK_APPOINTMENTS);

        $this->ensureBookableConsultationType($consultationType);

        if ($patient->role !== 'patient' || $patient->deactivated_at !== null) {
            throw ValidationException::withMessages([
                'patient_id' => 'No eligible patient account was found.',
            ]);
        }

        return DB::transaction(function () use ($actor, $doctorProfile, $patient, $slotId, $consultationType, $notes, $followUpOfId): Appointment {
            $this->rules->lockPatient($patient);
            $slot = AppointmentSlot::query()->lockForUpdate()->find($slotId);

            if (! $slot
                || $slot->doctor_profile_id !== $doctorProfile->id
                || $slot->status !== 'available'
                || $slot->starts_at->lte(CarbonImmutable::now())) {
                throw ValidationException::withMessages([
                    'slot' => 'This appointment slot is no longer available.',
                ]);
            }

            $original = $this->rules->followUpOriginal($patient, $followUpOfId, (int) $doctorProfile->id, audience: PatientAppointmentRules::AUDIENCE_STAFF);
            $this->rules->ensureWithinActiveLimit($patient, 'slot', PatientAppointmentRules::AUDIENCE_STAFF);
            $this->rules->ensureNoOverlap($patient, $slot, 'slot', PatientAppointmentRules::AUDIENCE_STAFF);
            $fee = ConsultationFee::forNewAppointment($doctorProfile, $original !== null);

            $appointment = Appointment::create([
                'appointment_slot_id' => $slot->id,
                'patient_id' => $patient->id,
                'follow_up_of_id' => $original?->id,
                'status' => 'booked',
                'consultation_type' => $consultationType,
                'consultation_fee' => $fee['fee'],
                'fee_type' => $fee['type'],
                'provider_notes' => $notes,
                'booked_at' => now(),
            ]);

            $slot->update(['status' => 'booked']);

            $this->notifications->recordStaffBooking($appointment);
            $this->events->record(
                $actor,
                $doctorProfile,
                DoctorWorkspaceEvent::ACTION_BOOKED,
                appointment: $appointment,
                toStatus: 'booked',
                context: ['source' => 'staff', 'slot_id' => $slot->id, 'starts_at' => $slot->starts_at->toIso8601String(), 'consultation_type' => $consultationType, ...$this->followUpContext($original), ...($patient->is_walk_in ? ['walk_in' => true] : [])],
            );

            return $appointment;
        });
    }

    /**
     * @return array{follow_up_of_id?: int}
     */
    private function followUpContext(?Appointment $original): array
    {
        return $original === null ? [] : ['follow_up_of_id' => (int) $original->id];
    }

    /**
     * Refuse consultation types that are unknown or not bookable right now (voice and video need a call provider).
     *
     * @throws ValidationException
     */
    private function ensureBookableConsultationType(string $consultationType): void
    {
        if (! in_array($consultationType, Appointment::bookableConsultationTypes(), true)) {
            throw ValidationException::withMessages([
                'consultation_type' => 'Choose a supported consultation type.',
            ]);
        }
    }
}
