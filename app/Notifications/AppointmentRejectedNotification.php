<?php

namespace App\Notifications;

/**
 * Sent to the patient when the doctor declines their appointment request.
 */
class AppointmentRejectedNotification extends AppointmentMailNotification
{
    protected function subject(): string
    {
        return 'Update on your appointment request '.$this->appointment->reference();
    }

    protected function tone(): string
    {
        return 'warning';
    }

    protected function heading(): string
    {
        return 'Appointment request not accepted';
    }

    protected function introduction(): string
    {
        return 'We are sorry, but '.$this->doctorName().' is unable to accept your appointment request for the time below.';
    }

    protected function closing(): ?string
    {
        return 'You are welcome to choose another available time from the doctor directory.';
    }

    protected function actionUrl(): string
    {
        return route('patient.appointments.show', $this->appointment);
    }
}
