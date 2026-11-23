<?php

namespace App\Notifications;

use App\Models\Appointment;

/**
 * Sent to the patient when an appointment request is created: either by the patient themself, or by a doctor or
 * Doctor Assistant on the patient's behalf. The request is pending until the doctor confirms it.
 */
class AppointmentRequestReceivedNotification extends AppointmentMailNotification
{
    public function __construct(Appointment $appointment, private readonly bool $bookedByStaff)
    {
        parent::__construct($appointment);
    }

    protected function subject(): string
    {
        return ($this->bookedByStaff ? 'An appointment was booked for you – ' : 'Appointment request received – ')
            .$this->date($this->appointment->slot);
    }

    protected function tone(): string
    {
        return 'info';
    }

    protected function heading(): string
    {
        return $this->bookedByStaff ? 'Appointment booked for you' : 'Appointment request received';
    }

    protected function introduction(): string
    {
        return $this->bookedByStaff
            ? $this->doctorName().'\'s clinic has booked the appointment below for you. It is waiting for the doctor\'s confirmation.'
            : 'We have received your appointment request with '.$this->doctorName().'. It is waiting for the doctor\'s confirmation.';
    }

    protected function closing(): ?string
    {
        return 'We will email you when the doctor confirms or declines it. A request that is not confirmed before its start time expires automatically.';
    }

    protected function actionUrl(): string
    {
        return route('patient.appointments.show', $this->appointment);
    }
}
