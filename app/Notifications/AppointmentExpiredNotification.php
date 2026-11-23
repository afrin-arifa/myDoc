<?php

namespace App\Notifications;

/**
 * Sent to the patient when their pending request reached its start time without the doctor confirming it.
 */
class AppointmentExpiredNotification extends AppointmentMailNotification
{
    protected function subject(): string
    {
        return 'Your appointment request has expired – '.$this->appointment->reference();
    }

    protected function tone(): string
    {
        return 'warning';
    }

    protected function heading(): string
    {
        return 'Appointment request expired';
    }

    protected function introduction(): string
    {
        return 'Your appointment request with '.$this->doctorName().' was not confirmed before its start time, so it has expired. You do not need to do anything.';
    }

    protected function closing(): ?string
    {
        return 'You are welcome to choose another available time from the doctor\'s profile.';
    }

    protected function actionUrl(): string
    {
        return route('doctors.show', $this->appointment->slot->doctorProfile);
    }

    protected function actionLabel(): string
    {
        return 'Book a new time';
    }
}
