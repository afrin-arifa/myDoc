<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Services\ConsultationFee;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared layout for appointment emails. Delivery is queued by SendAppointmentNotification, which sends these
 * immediately inside its job, so the notifications themselves are not queued a second time.
 *
 * Emails carry only scheduling details (including the chamber where the appointment is held): no notes, medical
 * history, documents or credentials.
 */
abstract class AppointmentMailNotification extends Notification
{
    public function __construct(public Appointment $appointment)
    {
        $this->appointment->loadMissing(['patient', 'slot.doctorProfile.user']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract protected function subject(): string;

    /**
     * One of: success, warning, info.
     */
    abstract protected function tone(): string;

    abstract protected function heading(): string;

    abstract protected function introduction(): string;

    abstract protected function actionUrl(): string;

    /**
     * @return array<string, string>
     */
    protected function details(): array
    {
        return [
            'Reference' => $this->appointment->reference(),
            ...($this->appointment->follow_up_of_id ? ['Follow-up to' => Appointment::referenceFor((int) $this->appointment->follow_up_of_id)] : []),
            'Doctor' => $this->doctorName(),
            'Patient' => $this->appointment->patient->name,
            'Date' => $this->date($this->appointment->slot),
            'Time' => $this->timeRange($this->appointment->slot),
            'Location' => $this->locationLabel($this->appointment->slot),
            'Consultation' => $this->consultationLabel(),
            (ConsultationFee::label($this->appointment->fee_type) ?? 'Consultation fee') => ConsultationFee::format($this->appointment->consultation_fee),
        ];
    }

    /**
     * Optional before/after schedule comparison.
     *
     * @return array{previous: array{date: string, time: string, location?: string}, current: array{date: string, time: string, location?: string}}|null
     */
    protected function scheduleChange(): ?array
    {
        return null;
    }

    protected function closing(): ?string
    {
        return null;
    }

    protected function actionLabel(): string
    {
        return 'View appointment';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->subject();

        return (new MailMessage)
            ->subject($subject)
            ->view(
                ['html' => 'emails.appointments.notification', 'text' => 'emails.appointments.notification-text'],
                [
                    'subject' => $subject,
                    'tone' => $this->tone(),
                    'heading' => $this->heading(),
                    'recipientName' => $notifiable->name ?? null,
                    'introduction' => $this->introduction(),
                    'details' => $this->details(),
                    'scheduleChange' => $this->scheduleChange(),
                    'closing' => $this->closing(),
                    'actionUrl' => $this->actionUrl(),
                    'actionLabel' => $this->actionLabel(),
                    'appName' => config('app.name', 'myDoc'),
                ]
            );
    }

    protected function doctorName(): string
    {
        return $this->appointment->slot->doctorProfile->user->name;
    }

    protected function date(AppointmentSlot|CarbonInterface $moment): string
    {
        return $this->localTime($moment instanceof AppointmentSlot ? $moment->starts_at : $moment)->format('l, j F Y');
    }

    protected function timeRange(AppointmentSlot $slot): string
    {
        return $this->localTime($slot->starts_at)->format('g:i A')
            .' – '.$this->localTime($slot->ends_at)->format('g:i A')
            .' ('.config('app.timezone', 'UTC').')';
    }

    /**
     * The chamber's name and address (the doctor's practice details, never patient data).
     */
    protected function locationLabel(AppointmentSlot $slot): string
    {
        $location = $slot->location;

        return $location === null ? 'Not specified' : $location->summary();
    }

    protected function consultationLabel(): string
    {
        return match ($this->appointment->consultation_type) {
            'voice' => 'Voice call',
            'video' => 'Video call',
            default => 'Physical visit',
        };
    }

    private function localTime(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone(config('app.timezone', 'UTC'));
    }
}
