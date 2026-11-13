<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

/**
 * Decides who may act on an appointment. Business rules (status transitions, cancellation deadlines,
 * slot availability, consultation windows) stay in the services and Form Requests.
 */
class AppointmentPolicy
{
    /**
     * Any active patient, doctor or admin may open an appointment list; each list is scoped to what they may view.
     */
    public function viewAny(User $user): bool
    {
        return $this->isActive($user) && in_array($user->role, ['patient', 'doctor', 'admin'], true);
    }

    /**
     * Admins see every appointment; patients and doctors see only their own.
     */
    public function view(User $user, Appointment $appointment): bool
    {
        return $this->isActiveRole($user, 'admin')
            || $this->isOwningPatient($user, $appointment)
            || $this->isOwningDoctor($user, $appointment);
    }

    /**
     * Patients book their own appointments and doctors with a profile book manually. Admins do not create appointments.
     */
    public function create(User $user): bool
    {
        return $this->isActiveRole($user, 'patient')
            || ($this->isActiveRole($user, 'doctor') && $user->doctorProfile !== null);
    }

    /**
     * Only the patient who owns the appointment may cancel it.
     */
    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->isOwningPatient($user, $appointment);
    }

    public function confirm(User $user, Appointment $appointment): bool
    {
        return $this->isOwningDoctor($user, $appointment);
    }

    public function reject(User $user, Appointment $appointment): bool
    {
        return $this->isOwningDoctor($user, $appointment);
    }

    public function reschedule(User $user, Appointment $appointment): bool
    {
        return $this->isOwningDoctor($user, $appointment);
    }

    public function complete(User $user, Appointment $appointment): bool
    {
        return $this->isOwningDoctor($user, $appointment);
    }

    public function start(User $user, Appointment $appointment): bool
    {
        return $this->isOwningDoctor($user, $appointment);
    }

    private function isOwningPatient(User $user, Appointment $appointment): bool
    {
        return $this->isActiveRole($user, 'patient') && $appointment->patient_id === $user->id;
    }

    /**
     * Ownership is derived from the stored slot, never from request input.
     */
    private function isOwningDoctor(User $user, Appointment $appointment): bool
    {
        if (! $this->isActiveRole($user, 'doctor')) {
            return false;
        }

        $profileId = $user->doctorProfile?->id;
        $slotDoctorProfileId = $appointment->slot()->value('doctor_profile_id');

        return $profileId !== null && $slotDoctorProfileId !== null && (int) $slotDoctorProfileId === $profileId;
    }

    private function isActiveRole(User $user, string $role): bool
    {
        return $this->isActive($user) && $user->role === $role;
    }

    private function isActive(User $user): bool
    {
        return $user->deactivated_at === null;
    }
}
