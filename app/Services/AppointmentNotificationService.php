<?php

namespace App\Services;

use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class AppointmentNotificationService
{
    /**
     * SMS recipients per event. Emails keep their P1.2 recipients (see SendAppointmentNotification); SMS also
     * confirms a booking to the patient.
     *
     * @var array<string, list<string>>
     */
    public const SMS_AUDIENCES = [
        AppointmentNotification::TYPE_BOOKED => [AppointmentNotification::AUDIENCE_PATIENT, AppointmentNotification::AUDIENCE_DOCTOR],
        AppointmentNotification::TYPE_CONFIRMED => [AppointmentNotification::AUDIENCE_PATIENT],
        AppointmentNotification::TYPE_REJECTED => [AppointmentNotification::AUDIENCE_PATIENT],
        AppointmentNotification::TYPE_CANCELLED => [AppointmentNotification::AUDIENCE_DOCTOR],
        AppointmentNotification::TYPE_RESCHEDULED => [AppointmentNotification::AUDIENCE_PATIENT],
        AppointmentNotification::TYPE_REMINDER => [AppointmentNotification::AUDIENCE_PATIENT],
        AppointmentNotification::TYPE_STAFF_CANCELLED => [AppointmentNotification::AUDIENCE_PATIENT, AppointmentNotification::AUDIENCE_DOCTOR],
    ];

    /**
     * Events whose email goes to several audiences, one ledger row each (keyed "{type}:{audience}"). Every other
     * event keeps its single P1.2 email recipient, decided by type in SendAppointmentNotification.
     *
     * @var array<string, list<string>>
     */
    public const EMAIL_AUDIENCES = [
        AppointmentNotification::TYPE_STAFF_CANCELLED => [AppointmentNotification::AUDIENCE_PATIENT, AppointmentNotification::AUDIENCE_DOCTOR],
    ];

    /**
     * Record an appointment email (and the matching SMS for recipients who opted in) and queue delivery once the
     * surrounding transaction commits.
     *
     * Call this inside the transaction that performs the appointment change: if that transaction rolls back,
     * the ledger rows and the queued jobs are discarded, so nothing is ever sent for an uncommitted change.
     * Each (appointment, channel, type, transition) identity is unique, so recording the same transition twice
     * is a no-op.
     *
     * @param  array<string, int|string>  $context  Non-sensitive identifiers needed to render the message later.
     */
    public function record(Appointment $appointment, string $type, ?string $transitionKey = null, array $context = []): void
    {
        $this->ensureInsideTransaction();

        if (isset(self::EMAIL_AUDIENCES[$type])) {
            foreach (self::EMAIL_AUDIENCES[$type] as $audience) {
                $this->recordOne($appointment, AppointmentNotification::CHANNEL_EMAIL, $type, ($transitionKey ?? $type).':'.$audience, [...$context, 'audience' => $audience]);
            }
        } else {
            $this->recordOne($appointment, AppointmentNotification::CHANNEL_EMAIL, $type, $transitionKey ?? $type, $context);
        }

        $this->recordSms($appointment, $type, $transitionKey, $context);
    }

    /**
     * Record SMS only (no email) for the given audiences, defaulting to the event's standard SMS recipients.
     * Rows are created only for recipients who currently opted in to SMS; the preference, account status and
     * phone number are checked again when the SMS is delivered.
     *
     * @param  array<string, int|string>  $context
     * @param  list<string>|null  $audiences
     */
    public function recordSms(Appointment $appointment, string $type, ?string $transitionKey = null, array $context = [], ?array $audiences = null): void
    {
        $this->ensureInsideTransaction();

        $appointment->loadMissing(['patient', 'slot.doctorProfile.user']);

        foreach ($audiences ?? self::SMS_AUDIENCES[$type] ?? [] as $audience) {
            $recipient = self::smsRecipient($appointment, $audience);

            if ($recipient?->wantsAppointmentSms() !== true) {
                continue;
            }

            $this->recordOne(
                $appointment,
                AppointmentNotification::CHANNEL_SMS,
                $type,
                ($transitionKey ?? $type).':'.$audience,
                [...$context, 'audience' => $audience],
                $recipient->id,
            );
        }
    }

    /**
     * Queue the patient's reminder SMS for the appointment's current slot. The key contains the slot and its start
     * time, so a rescheduled appointment gets fresh reminders and the old ones become irrelevant.
     */
    public function recordReminder(Appointment $appointment, int $hoursBefore): void
    {
        $slot = $appointment->slot;
        $startsAt = $slot->starts_at->getTimestamp();

        $this->recordSms(
            $appointment,
            AppointmentNotification::TYPE_REMINDER,
            "reminder:{$hoursBefore}h:slot:{$slot->id}:{$startsAt}",
            ['reminder_hours' => $hoursBefore, 'slot_id' => $slot->id, 'starts_at' => $startsAt],
        );
    }

    /**
     * Recipients come from the appointment's own relationships, never from request input.
     */
    public static function smsRecipient(Appointment $appointment, ?string $audience): ?User
    {
        return match ($audience) {
            AppointmentNotification::AUDIENCE_PATIENT => $appointment->patient,
            AppointmentNotification::AUDIENCE_DOCTOR => $appointment->slot?->doctorProfile?->user,
            default => null,
        };
    }

    /**
     * @param  array<string, int|string>  $context
     */
    private function recordOne(Appointment $appointment, string $channel, string $type, string $transitionKey, array $context, ?int $recipientId = null): void
    {
        $notification = AppointmentNotification::query()->firstOrCreate(
            [
                'appointment_id' => $appointment->id,
                'channel' => $channel,
                'type' => $type,
                'transition_key' => $transitionKey,
            ],
            [
                'status' => AppointmentNotification::STATUS_PENDING,
                'recipient_id' => $recipientId,
                'context' => $context === [] ? null : $context,
            ]
        );

        if ($notification->wasRecentlyCreated) {
            SendAppointmentNotification::dispatch($notification->id)->afterCommit();
        }
    }

    private function ensureInsideTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Appointment notifications must be recorded inside the transaction that changes the appointment.');
        }
    }
}
