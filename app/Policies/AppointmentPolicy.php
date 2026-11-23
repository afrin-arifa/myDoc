<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use App\Services\DoctorAccess;

/**
 * Decides who may act on an appointment. Business rules (status transitions, cancellation deadlines,
 * slot availability, consultation windows) stay in the services and Form Requests.
 *
 * Doctor-side abilities are decided by DoctorAccess for the doctor who owns the appointment's slot: the doctor
 * themselves, or a Doctor Assistant currently assigned to that (active) doctor, each limited to their own abilities.
 */
class AppointmentPolicy
{
    public function __construct(private readonly DoctorAccess $access) {}

    /**
     * Any active patient, doctor or admin may open an appointment list; each list is scoped to what they may view.
     * Assistant lists are scoped per doctor and are authorized against that doctor (see DoctorProfilePolicy).
     */
    public function viewAny(User $user): bool
    {
        return $this->isActive($user) && in_array($user->role, ['patient', 'doctor', 'admin'], true);
    }

    /**
     * Admins see every appointment; patients see their own; the doctor and their assigned assistants see the
     * doctor's appointments.
     */
    public function view(User $user, Appointment $appointment): bool
    {
        return $this->isActiveRole($user, 'admin')
            || $this->isOwningPatient($user, $appointment)
            || $this->actsForAppointmentDoctor($user, $appointment, DoctorAccess::VIEW_APPOINTMENTS);
    }

    /**
     * Patients book for themselves; doctors book from their own workspace. Booking on a doctor's behalf (assistants)
     * needs the doctor, so it is authorized with DoctorProfilePolicy::bookAppointments instead.
     */
    public function create(User $user): bool
    {
        return $this->isActiveRole($user, 'patient')
            || ($this->isActiveRole($user, 'doctor') && $user->doctorProfile !== null);
    }

    /**
     * Patients cancel their own appointments; the doctor and their assigned assistants cancel the doctor's
     * appointments. The cancellation rules themselves (the patient's 24-hour notice, the staff reason) live in
     * AppointmentBookingService and DoctorAppointmentService.
     */
    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->isOwningPatient($user, $appointment)
            || $this->actsForAppointmentDoctor($user, $appointment, DoctorAccess::CANCEL_APPOINTMENTS);
    }

    public function confirm(User $user, Appointment $appointment): bool
    {
        return $this->actsForAppointmentDoctor($user, $appointment, DoctorAccess::CONFIRM_APPOINTMENTS);
    }

    public function reject(User $user, Appointment $appointment): bool
    {
        return $this->actsForAppointmentDoctor($user, $appointment, DoctorAccess::REJECT_APPOINTMENTS);
    }

    public function reschedule(User $user, Appointment $appointment): bool
    {
        return $this->actsForAppointmentDoctor($user, $appointment, DoctorAccess::RESCHEDULE_APPOINTMENTS);
    }

    public function complete(User $user, Appointment $appointment): bool
    {
        return $this->actsForAppointmentDoctor($user, $appointment, DoctorAccess::COMPLETE_APPOINTMENTS);
    }

    public function start(User $user, Appointment $appointment): bool
    {
        return $this->actsForAppointmentDoctor($user, $appointment, DoctorAccess::START_APPOINTMENTS);
    }

    private function isOwningPatient(User $user, Appointment $appointment): bool
    {
        return $this->isActiveRole($user, 'patient') && $appointment->patient_id === $user->id;
    }

    /**
     * The doctor is read from the appointment's slot in the database, never from request input.
     */
    private function actsForAppointmentDoctor(User $user, Appointment $appointment, string $ability): bool
    {
        $slotDoctorProfileId = $appointment->slot()->value('doctor_profile_id');

        return $slotDoctorProfileId !== null && $this->access->canActFor($user, (int) $slotDoctorProfileId, $ability);
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
