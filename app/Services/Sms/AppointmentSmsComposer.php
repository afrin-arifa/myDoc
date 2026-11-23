<?php

namespace App\Services\Sms;

use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\WaitlistOffer;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Builds appointment SMS text. Messages carry only the reference, doctor name, schedule and (for the patient's
 * booking, confirmation, reschedule and reminder) the chamber's name: never notes,
 * symptoms, documents, diagnoses or links with tokens. They are plain English and kept short so most fit in a
 * single 160-character segment.
 */
class AppointmentSmsComposer
{
    public const DOCTOR_NAME_LIMIT = 40;

    /**
     * Location names are cut to this length; the full address is in the email and on the appointment page.
     */
    public const LOCATION_NAME_LIMIT = 30;

    public function compose(AppointmentNotification $notification, Appointment $appointment): string
    {
        $appointment->loadMissing(['slot.doctorProfile.user', 'slot.location']);

        $reference = $appointment->reference();
        $doctor = Str::limit((string) $appointment->slot?->doctorProfile?->user?->name, self::DOCTOR_NAME_LIMIT, '');
        $when = $this->when($appointment->slot->starts_at);
        $audience = $notification->context['audience'] ?? null;
        $locationName = $appointment->slot?->location?->name;
        $at = $locationName ? ' at '.Str::limit($locationName, self::LOCATION_NAME_LIMIT, '') : '';

        return match ($notification->type) {
            AppointmentNotification::TYPE_BOOKED => $audience === AppointmentNotification::AUDIENCE_DOCTOR
                ? "myDoc: New appointment request {$reference} for {$when}. Please review it in your myDoc dashboard."
                : "myDoc: Your appointment {$reference} with {$doctor} on {$when}{$at} is booked. We will tell you when it is confirmed.",
            AppointmentNotification::TYPE_CONFIRMED => "myDoc: Your appointment {$reference} with {$doctor} on {$when}{$at} is confirmed.",
            AppointmentNotification::TYPE_REJECTED => "myDoc: Your appointment request {$reference} with {$doctor} on {$when} was declined. You can book another time on myDoc.",
            AppointmentNotification::TYPE_CANCELLED => "myDoc: Appointment {$reference} on {$when} was cancelled by the patient.",
            AppointmentNotification::TYPE_STAFF_CANCELLED => $audience === AppointmentNotification::AUDIENCE_DOCTOR
                ? "myDoc: Appointment {$reference} on {$when} was cancelled by ".(($notification->context['cancelled_by_role'] ?? null) === 'assistant' ? 'your assistant' : 'you').'.'
                : "myDoc: Your appointment {$reference} with {$doctor} on {$when} has been cancelled by the clinic. You can book a new time on myDoc.",
            AppointmentNotification::TYPE_RESCHEDULED => "myDoc: Your appointment {$reference} with {$doctor} has moved to {$when}{$at}.",
            AppointmentNotification::TYPE_REMINDER => "myDoc reminder: Your appointment {$reference} with {$doctor} is on {$when}{$at}.",
            AppointmentNotification::TYPE_EXPIRED => "myDoc: Your appointment request {$reference} with {$doctor} on {$when} was not confirmed in time and has expired. You can book another time on myDoc.",
        };
    }

    /**
     * A time held for a waiting-list patient: doctor, time, chamber and when the hold ends. No link or patient data.
     */
    public function composeWaitlistOffer(WaitlistOffer $offer): string
    {
        $offer->loadMissing(['slot.doctorProfile.user']);
        $slot = $offer->slot;
        $doctor = Str::limit((string) $slot->doctorProfile?->user?->name, self::DOCTOR_NAME_LIMIT, '');
        $at = $slot->location?->name ? ' at '.Str::limit($slot->location->name, self::LOCATION_NAME_LIMIT, '') : '';
        $until = $offer->expires_at->copy()->setTimezone(config('app.timezone', 'UTC'))->format('g:i A');

        return "myDoc: A time with {$doctor} on {$this->when($slot->starts_at)}{$at} is held for you until {$until}. Accept or decline on myDoc.";
    }

    /**
     * Same timezone as the appointment emails (config app.timezone), with its abbreviation.
     */
    private function when(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone(config('app.timezone', 'UTC'))->format('j M Y, g:i A T');
    }
}
