<?php

namespace App\Services;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleHistory;
use App\Models\AppointmentSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DoctorAppointmentService
{
    public function complete(User $doctor, Appointment $appointment): void
    {
        DB::transaction(function () use ($doctor, $appointment): void {
            $locked = $this->ownedAppointment($doctor, $appointment->id);
            if ($locked->status !== 'confirmed') {
                throw ValidationException::withMessages(['status' => 'Only confirmed appointments can be completed.']);
            }
            $locked->update(['status' => 'completed']);
        });
    }

    public function reschedule(User $doctor, Appointment $appointment, int $newSlotId, ?string $reason = null): void
    {
        DB::transaction(function () use ($doctor, $appointment, $newSlotId, $reason): void {
            $locked = $this->ownedAppointment($doctor, $appointment->id);
            if (! in_array($locked->status, ['booked', 'confirmed'], true)) {
                throw ValidationException::withMessages(['status' => 'Only booked or confirmed appointments can be rescheduled.']);
            }
            $oldSlot = AppointmentSlot::query()->whereKey($locked->appointment_slot_id)->lockForUpdate()->firstOrFail();
            $newSlot = AppointmentSlot::query()->whereKey($newSlotId)->lockForUpdate()->firstOrFail();
            if ($newSlot->id === $oldSlot->id) {
                throw ValidationException::withMessages(['slot_id' => 'Choose a different appointment slot.']);
            }
            if ($newSlot->doctor_profile_id !== $doctor->doctorProfile?->id || $newSlot->status !== 'available') {
                throw ValidationException::withMessages(['slot_id' => 'That slot is not available for this doctor.']);
            }
            if ($newSlot->starts_at->lte(now())) {
                throw ValidationException::withMessages(['slot_id' => 'Appointments must be scheduled in the future.']);
            }
            $oldSlot->update(['status' => 'available']);
            $newSlot->update(['status' => 'booked']);
            AppointmentRescheduleHistory::create([
                'appointment_id' => $locked->id,
                'previous_slot_id' => $oldSlot->id,
                'new_slot_id' => $newSlot->id,
                'performed_by' => $doctor->id,
                'previous_starts_at' => $oldSlot->starts_at,
                'new_starts_at' => $newSlot->starts_at,
                'reason' => $reason,
            ]);
            $locked->update(['appointment_slot_id' => $newSlot->id]);
        });

        if ($appointment->status === 'confirmed') {
            SyncAppointmentToGoogleCalendar::dispatch($appointment->id, 'sync')->afterCommit();
        }
    }

    public function transition(User $doctor, Appointment $appointment, string $status): void
    {
        DB::transaction(function () use ($doctor, $appointment, $status): void {
            $appointment = Appointment::query()
                ->whereKey($appointment->id)
                ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $doctor->doctorProfile?->id))
                ->with('slot')
                ->lockForUpdate()
                ->firstOrFail();

            if ($appointment->status !== 'booked' || ! in_array($status, ['confirmed', 'rejected'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This appointment cannot be moved to the requested status.',
                ]);
            }

            $appointment->update(['status' => $status]);

            if ($status === 'rejected') {
                $appointment->slot()->lockForUpdate()->first()->update(['status' => 'available']);
            }
        });

        SyncAppointmentToGoogleCalendar::dispatch(
            $appointment->id,
            $status === 'confirmed' ? 'sync' : 'delete'
        )->afterCommit();
    }

    public function start(User $doctor, Appointment $appointment): void
    {
        DB::transaction(function () use ($doctor, $appointment): void {
            $appointment = Appointment::query()
                ->whereKey($appointment->id)
                ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $doctor->doctorProfile?->id))
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
        });
    }

    private function ownedAppointment(User $doctor, int $appointmentId): Appointment
    {
        return Appointment::query()
            ->whereKey($appointmentId)
            ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $doctor->doctorProfile?->id))
            ->lockForUpdate()
            ->firstOrFail();
    }
}
