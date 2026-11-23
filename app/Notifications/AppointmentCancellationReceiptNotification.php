<?php

namespace App\Notifications;

/**
 * Sent to the patient as a receipt when they cancel their own appointment (the doctor gets
 * AppointmentCancelledNotification).
 */
class AppointmentCancellationReceiptNotification extends AppointmentMailNotification
{
    protected function subject(): string
    {
        return 'You cancelled your appointment – '.$this->appointment->reference();
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
        return 'Your appointment with '.$this->doctorName().' below has been cancelled as you requested. The doctor has been notified.';
    }

    protected function closing(): ?string
    {
        return 'You can choose a new time from the doctor\'s profile.';
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
