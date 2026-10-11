<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
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
}
