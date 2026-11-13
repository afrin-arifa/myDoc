<?php

namespace App\Services;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentBookingService
{
    public function __construct(
        private readonly AppointmentNotificationService $notifications,
        private readonly DoctorWorkspace $workspace,
        private readonly DoctorWorkspaceEventService $events,
    ) {}

    public function book(User $patient, int $slotId, string $consultationType): Appointment
    {
        if (! in_array($consultationType, ['physical', 'voice', 'video'], true)) {
            throw ValidationException::withMessages([
                'consultation_type' => 'Choose a supported consultation type.',
            ]);
        }

        return DB::transaction(function () use ($patient, $slotId, $consultationType): Appointment {
            $slot = AppointmentSlot::query()->lockForUpdate()->find($slotId);

            $slot?->loadMissing('doctorProfile.user');

            if (! $slot
                || $slot->status !== 'available'
                || $slot->starts_at->lte(CarbonImmutable::now())
                || ! $slot->doctorProfile?->isPubliclyListed()) {
                throw ValidationException::withMessages([
                    'slot' => 'This appointment slot is no longer available.',
                ]);
            }

            $appointment = Appointment::create([
                'appointment_slot_id' => $slot->id,
                'patient_id' => $patient->id,
                'status' => 'booked',
                'consultation_type' => $consultationType,
                'booked_at' => now(),
            ]);

            $slot->update(['status' => 'booked']);
            $this->notifications->record($appointment, AppointmentNotification::TYPE_BOOKED);
            $this->events->record(
                $patient,
                $slot->doctor_profile_id,
                DoctorWorkspaceEvent::ACTION_BOOKED,
                appointment: $appointment,
                toStatus: 'booked',
                context: ['source' => 'patient', 'slot_id' => $slot->id, 'starts_at' => $slot->starts_at->toIso8601String(), 'consultation_type' => $consultationType],
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

            if ($slot->starts_at->lte(CarbonImmutable::now()->addDay())) {
                throw ValidationException::withMessages([
                    'appointment' => 'Appointments can only be cancelled at least 24 hours before they start.',
                ]);
            }

            $previousStatus = $appointment->status;
            $appointment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            $slot->update(['status' => 'available']);
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
     * doctor booking in their own workspace.
     */
    public function bookForDoctor(User $actor, User $patient, int $slotId, string $consultationType, ?string $notes = null, ?DoctorProfile $doctorProfile = null): Appointment
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::BOOK_APPOINTMENTS);

        if (! in_array($consultationType, ['physical', 'voice', 'video'], true)) {
            throw ValidationException::withMessages([
                'consultation_type' => 'Choose a supported consultation type.',
            ]);
        }

        if ($patient->role !== 'patient' || $patient->deactivated_at !== null) {
            throw ValidationException::withMessages([
                'patient_id' => 'No eligible patient account was found.',
            ]);
        }

        return DB::transaction(function () use ($actor, $doctorProfile, $patient, $slotId, $consultationType, $notes): Appointment {
            $slot = AppointmentSlot::query()->lockForUpdate()->find($slotId);

            if (! $slot
                || $slot->doctor_profile_id !== $doctorProfile->id
                || $slot->status !== 'available'
                || $slot->starts_at->lte(CarbonImmutable::now())) {
                throw ValidationException::withMessages([
                    'slot' => 'This appointment slot is no longer available.',
                ]);
            }

            $appointment = Appointment::create([
                'appointment_slot_id' => $slot->id,
                'patient_id' => $patient->id,
                'status' => 'booked',
                'consultation_type' => $consultationType,
                'provider_notes' => $notes,
                'booked_at' => now(),
            ]);

            $slot->update(['status' => 'booked']);

            // No email is sent for doctor-created bookings (unchanged P1.2 behaviour); an opted-in patient gets an
            // SMS confirmation. The doctor made the booking, so they are not notified.
            $this->notifications->recordSms(
                $appointment,
                AppointmentNotification::TYPE_BOOKED,
                audiences: [AppointmentNotification::AUDIENCE_PATIENT],
            );
            $this->events->record(
                $actor,
                $doctorProfile,
                DoctorWorkspaceEvent::ACTION_BOOKED,
                appointment: $appointment,
                toStatus: 'booked',
                context: ['source' => 'staff', 'slot_id' => $slot->id, 'starts_at' => $slot->starts_at->toIso8601String(), 'consultation_type' => $consultationType],
            );

            return $appointment;
        });
    }
}
