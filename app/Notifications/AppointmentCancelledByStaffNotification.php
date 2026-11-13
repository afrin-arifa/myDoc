<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Models\User;

/**
 * Email sent when a doctor or Doctor Assistant cancels an appointment. The patient version explains that the clinic
 * cancelled and how to book again; the doctor version says whether the doctor or their assistant cancelled.
 *
 * The cancellation reason is deliberately not included: it is staff-only and may contain clinical detail, so it is
 * shown only on the staff appointment page.
 */
class AppointmentCancelledByStaffNotification extends AppointmentMailNotification
{
    public function __construct(Appointment $appointment, private readonly bool $forDoctor, private readonly string $cancelledByRole)
    {
        parent::__construct($appointment);
    }

    protected function subject(): string
    {
        return $this->forDoctor
            ? 'Appointment cancelled – '.$this->appointment->reference()
            : 'Your appointment has been cancelled – '.$this->appointment->reference();
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
        if (! $this->forDoctor) {
            return 'Your appointment with '.$this->doctorName().' has been cancelled by the clinic. We are sorry for the inconvenience. '
                .'You can choose a new time from the doctor\'s profile.';
        }

        return ($this->cancelledByRole === User::ROLE_ASSISTANT ? 'Your assistant cancelled' : 'You cancelled')
            .' the appointment below. The patient has been notified and the time slot is available again where possible. '
            .'The cancellation reason is recorded on the appointment in myDoc.';
    }

    protected function actionUrl(): string
    {
        return $this->forDoctor
            ? route('doctor.appointments.show', $this->appointment)
            : route('doctors.show', $this->appointment->slot->doctorProfile);
    }

    protected function actionLabel(): string
    {
        return $this->forDoctor ? 'View appointment' : 'Book a new time';
    }
}
