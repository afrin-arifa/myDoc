<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DoctorAppointmentService
{
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
}
