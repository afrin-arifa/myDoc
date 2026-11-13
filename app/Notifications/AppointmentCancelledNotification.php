<?php

namespace App\Notifications;

/**
 * Sent to the doctor when the patient cancels an appointment.
 */
class AppointmentCancelledNotification extends AppointmentMailNotification
{
    protected function subject(): string
    {
        return 'Appointment cancelled by patient – '.$this->appointment->reference();
    }

    protected function tone(): string
    {
        return 'warning';
    }

    protected function heading(): string
    {
        return 'Appointment cancelled';
    }

    protected function introduction(): string
    {
        return $this->appointment->patient->name.' has cancelled the appointment below. The time slot is available for booking again.';
    }

    protected function actionUrl(): string
    {
        return route('doctor.appointments.show', $this->appointment);
    }
}
