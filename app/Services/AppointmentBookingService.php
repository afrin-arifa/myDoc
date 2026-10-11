<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentBookingService
{
    public function book(User $patient, int $slotId, string $consultationType): Appointment
    {
        if (! in_array($consultationType, ['physical', 'voice', 'video'], true)) {
            throw ValidationException::withMessages([
                'consultation_type' => 'Choose a supported consultation type.',
            ]);
        }

        return DB::transaction(function () use ($patient, $slotId, $consultationType): Appointment {
            $slot = AppointmentSlot::query()->lockForUpdate()->find($slotId);

            $slot?->loadMissing('doctorProfile');

            if (! $slot
                || $slot->status !== 'available'
                || $slot->starts_at->lte(CarbonImmutable::now())
                || ! $slot->doctorProfile?->is_accepting_patients) {
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

            $appointment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            $slot->update(['status' => 'available']);
        });
    }
}
