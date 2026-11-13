<?php

namespace App\Notifications;

/**
 * Sent to the patient when the doctor confirms their appointment.
 */
class AppointmentConfirmedNotification extends AppointmentMailNotification
{
    protected function subject(): string
    {
        return 'Your appointment is confirmed – '.$this->date($this->appointment->slot);
    }

    protected function tone(): string
    {
        return 'success';
    }

    protected function heading(): string
    {
        return 'Appointment confirmed';
    }

    protected function introduction(): string
    {
        return $this->doctorName().' has confirmed your appointment. The details are below.';
    }

    protected function closing(): ?string
    {
        return 'If you can no longer attend, please cancel from your appointment page at least 24 hours in advance.';
    }

    protected function actionUrl(): string
    {
        return route('patient.appointments.show', $this->appointment);
    }
}
