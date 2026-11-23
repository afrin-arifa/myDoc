<?php

namespace App\Notifications;

use App\Models\WaitlistOffer;
use App\Services\ConsultationFee;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a waiting-list patient that a time is held for them until the offer expires. Delivered by
 * SendAppointmentNotification only while the offer is still open. Scheduling details only.
 */
class WaitlistOfferNotification extends Notification
{
    public function __construct(public WaitlistOffer $offer)
    {
        $this->offer->loadMissing(['entry', 'slot.doctorProfile.user']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = config('app.timezone', 'UTC');
        $slot = $this->offer->slot;
        $start = $slot->starts_at->copy()->setTimezone($timezone);
        $expires = $this->offer->expires_at->copy()->setTimezone($timezone);
        $doctor = $slot->doctorProfile->user->name;
        $isFollowUp = $this->offer->entry->follow_up_of_id !== null;
        $fee = ConsultationFee::forNewAppointment((int) $slot->doctor_profile_id, $isFollowUp);
        $subject = 'A time with '.$doctor.' is available for you – '.$start->format('l, j F');

        return (new MailMessage)
            ->subject($subject)
            ->view(
                ['html' => 'emails.appointments.notification', 'text' => 'emails.appointments.notification-text'],
                [
                    'subject' => $subject,
                    'tone' => 'success',
                    'heading' => 'A time is held for you',
                    'recipientName' => $notifiable->name ?? null,
                    'introduction' => 'A time you were waiting for with '.$doctor.' has opened. We are holding it for you until '
                        .$expires->format('g:i A').' ('.$timezone.'). If you do not answer by then, it is offered to the next patient.',
                    'details' => [
                        'Doctor' => $doctor,
                        'Date' => $start->format('l, j F Y'),
                        'Time' => $start->format('g:i A').' – '.$slot->ends_at->copy()->setTimezone($timezone)->format('g:i A').' ('.$timezone.')',
                        'Location' => $slot->location?->summary() ?? 'Not specified',
                        ConsultationFee::label($fee['type']) => ConsultationFee::format($fee['fee']),
                        'Hold expires' => $expires->format('g:i A'),
                    ],
                    'scheduleChange' => null,
                    'closing' => 'Accepting sends an appointment request that the doctor confirms, like any other booking. '
                        .'Not interested? Decline it so the next patient can have it.',
                    'actionUrl' => route('patient.waitlist.offers.show', $this->offer),
                    'actionLabel' => 'View the offer',
                    'appName' => config('app.name', 'myDoc'),
                    'footerNote' => 'This is an automated message from '.config('app.name', 'myDoc').' about your waiting list request. '
                        .'For your privacy, it contains scheduling details only.',
                ]
            );
    }
}
