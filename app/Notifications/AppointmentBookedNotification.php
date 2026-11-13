<?php

namespace App\Notifications;

/**
 * Sent to the doctor when a patient books one of their slots.
 */
class AppointmentBookedNotification extends AppointmentMailNotification
{
    protected function subject(): string
    {
        return 'New appointment request – '.$this->date($this->appointment->slot);
    }

    protected function tone(): string
    {
        return 'info';
    }

    protected function heading(): string
    {
        return 'New appointment request';
    }

    protected function introduction(): string
    {
        return $this->appointment->patient->name.' has booked an appointment with you. Please review and confirm or reject the request.';
    }

    protected function actionUrl(): string
    {
        return route('doctor.appointments.show', $this->appointment);
    }

    protected function actionLabel(): string
    {
        return 'Review appointment';
    }
}
