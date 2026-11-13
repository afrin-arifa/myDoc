<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Models\AppointmentRescheduleHistory;

/**
 * Sent to the patient when the doctor moves their appointment to a different slot.
 */
class AppointmentRescheduledNotification extends AppointmentMailNotification
{
    public function __construct(Appointment $appointment, public AppointmentRescheduleHistory $reschedule)
    {
        parent::__construct($appointment);
        $this->reschedule->loadMissing(['previousSlot', 'newSlot']);
    }

    protected function subject(): string
    {
        return 'Your appointment has been rescheduled – '.$this->date($this->reschedule->newSlot);
    }

    protected function tone(): string
    {
        return 'info';
    }

    protected function heading(): string
    {
        return 'Appointment rescheduled';
    }

    protected function introduction(): string
    {
        return $this->doctorName().' has moved your appointment to a new time.';
    }

    /**
     * @return array<string, string>
     */
    protected function details(): array
    {
        return [
            ...parent::details(),
            'Date' => $this->date($this->reschedule->newSlot),
            'Time' => $this->timeRange($this->reschedule->newSlot),
        ];
    }

    protected function scheduleChange(): ?array
    {
        return [
            'previous' => [
                'date' => $this->date($this->reschedule->previousSlot),
                'time' => $this->timeRange($this->reschedule->previousSlot),
            ],
            'current' => [
                'date' => $this->date($this->reschedule->newSlot),
                'time' => $this->timeRange($this->reschedule->newSlot),
            ],
        ];
    }

    protected function closing(): ?string
    {
        return 'If the new time does not suit you, please cancel from your appointment page at least 24 hours in advance.';
    }

    protected function actionUrl(): string
    {
        return route('patient.appointments.show', $this->appointment);
    }

    protected function actionLabel(): string
    {
        return 'View updated appointment';
    }
}
