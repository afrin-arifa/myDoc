<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentRescheduleHistory;
use App\Models\User;
use App\Notifications\AppointmentBookedNotification;
use App\Notifications\AppointmentCancelledNotification;
use App\Notifications\AppointmentConfirmedNotification;
use App\Notifications\AppointmentMailNotification;
use App\Notifications\AppointmentRejectedNotification;
use App\Notifications\AppointmentRescheduledNotification;
use App\Services\AppointmentNotificationService;
use App\Services\Sms\AppointmentSmsComposer;
use App\Services\Sms\SmsDeliveryException;
use App\Services\Sms\SmsGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one recorded appointment email or SMS.
 *
 * The ledger row is claimed with a single conditional UPDATE, so only one worker can send it at a time and a row
 * already marked "sent" is never sent again, however many times the job is retried or duplicated. A row is only
 * marked "sent" after the mailer or SMS provider returns successfully; a retryable failure marks it "failed" and
 * rethrows for a retry.
 *
 * If another attempt still holds a fresh "sending" claim (for example a worker that crashed or timed out mid-send,
 * whose job the queue has now redelivered), this job releases itself until that claim becomes stale instead of
 * finishing, so the queue never deletes the only job that can recover the message.
 *
 * SMS has one extra rule because the provider has no idempotency key: provider_requested_at is set just before
 * the provider is called. A stale claim with that marker may already have been delivered, so it is closed as
 * "unconfirmed" instead of being sent again. Likewise, a timeout or unreadable provider response is "unconfirmed",
 * not retried. Only failures where the provider certainly did not accept the SMS are retried.
 *
 * Timing: SMTP timeout (20s) and SMS HTTP timeout (10s + 5s connect) < job timeout (45s) < database queue
 * retry_after (90s) < stale-claim window (10 minutes).
 */
class SendAppointmentNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Delivery failures (exceptions) allowed before the job is failed. Self-releases do not count here.
     */
    public int $maxExceptions = 5;

    /**
     * Overall ceiling on runs, including self-releases and redeliveries after a crash or timeout. Each crash
     * recovery costs two runs (the interrupted run and one release), so this leaves room for the five delivery
     * attempts plus several recoveries while still bounding a job that keeps crashing.
     */
    public int $tries = 15;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900, 1800];

    public int $timeout = 45;

    /**
     * A "sending" claim older than this is treated as abandoned (for example, a worker that crashed mid-send)
     * and may be reclaimed. It must comfortably exceed the job timeout.
     */
    public const STALE_CLAIM_MINUTES = 10;

    /**
     * Extra seconds added to a release so the next run lands safely after the claim becomes stale.
     */
    public const RELEASE_BUFFER_SECONDS = 5;

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $sms = app(SmsGateway::class);
        $composer = app(AppointmentSmsComposer::class);

        if (! $this->claim()) {
            $this->releaseWhileClaimedElsewhere();

            return;
        }

        $notification = AppointmentNotification::query()->findOrFail($this->notificationId);
        $appointment = Appointment::query()
            ->with(['patient', 'slot.doctorProfile.user'])
            ->find($notification->appointment_id);

        $recipient = $appointment ? $this->recipientFor($notification, $appointment) : null;
        $skipReason = match (true) {
            $appointment === null => 'Appointment no longer exists.',
            $recipient === null => 'Recipient could not be resolved.',
            $recipient->deactivated_at !== null => 'Recipient account is deactivated.',
            default => $this->isSms($notification)
                ? $this->smsSkipReason($notification, $appointment, $recipient, $sms)
                : $this->emailSkipReason($notification, $appointment, $recipient),
        };

        if ($skipReason !== null) {
            $notification->update([
                'status' => AppointmentNotification::STATUS_SKIPPED,
                'recipient_id' => $recipient?->id,
                'last_error' => $skipReason,
            ]);

            return;
        }

        if ($this->isSms($notification)) {
            $this->deliverSms($notification, $appointment, $recipient, $sms, $composer);

            return;
        }

        try {
            $recipient->notifyNow($this->mailFor($notification, $appointment));
        } catch (Throwable $exception) {
            $notification->update([
                'status' => AppointmentNotification::STATUS_FAILED,
                'recipient_id' => $recipient->id,
                'last_error' => 'Email delivery failed.',
            ]);

            Log::warning('Appointment email delivery failed.', [
                'appointment_notification_id' => $notification->id,
                'appointment_id' => $notification->appointment_id,
                'type' => $notification->type,
                'attempt' => $notification->attempts,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        $notification->update([
            'status' => AppointmentNotification::STATUS_SENT,
            'recipient_id' => $recipient->id,
            'last_error' => null,
            'sent_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Appointment notification delivery gave up after all retries.', [
            'appointment_notification_id' => $this->notificationId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    /**
     * Atomically move the row from pending/failed (or an abandoned claim) to "sending". An abandoned SMS claim
     * whose provider request may already have been made is never reclaimed.
     */
    private function claim(): bool
    {
        return AppointmentNotification::query()
            ->whereKey($this->notificationId)
            ->where(fn ($query) => $query
                ->whereIn('status', [AppointmentNotification::STATUS_PENDING, AppointmentNotification::STATUS_FAILED])
                ->orWhere(fn ($stale) => $stale
                    ->where('status', AppointmentNotification::STATUS_SENDING)
                    ->whereNull('provider_requested_at')
                    ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))))
            ->increment('attempts', 1, [
                'status' => AppointmentNotification::STATUS_SENDING,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Called when the claim failed. "sent", "skipped" and other final rows (or a deleted row) are final, so the
     * job simply finishes. A "sending" row is held by another attempt: release this job once, until exactly when
     * that claim becomes stale, so it can take over if the other attempt never finishes. A stale SMS claim whose
     * provider request may have gone out is closed as "unconfirmed" rather than sent a second time.
     */
    private function releaseWhileClaimedElsewhere(): void
    {
        $notification = AppointmentNotification::query()->find($this->notificationId);

        if ($notification?->status !== AppointmentNotification::STATUS_SENDING) {
            return;
        }

        if ($notification->provider_requested_at !== null && $this->secondsUntilClaimIsStale($notification) <= self::RELEASE_BUFFER_SECONDS) {
            $closed = AppointmentNotification::query()
                ->whereKey($notification->id)
                ->where('status', AppointmentNotification::STATUS_SENDING)
                ->whereNotNull('provider_requested_at')
                ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))
                ->update([
                    'status' => AppointmentNotification::STATUS_UNCONFIRMED,
                    'last_error' => 'Delivery was interrupted after the SMS provider was contacted.',
                    'updated_at' => now(),
                ]);

            if ($closed === 1) {
                Log::warning('Appointment SMS outcome unknown after an interrupted attempt; not re-sent.', [
                    'appointment_notification_id' => $notification->id,
                    'appointment_id' => $notification->appointment_id,
                ]);
            }

            return;
        }

        $this->release($this->secondsUntilClaimIsStale($notification));
    }

    public function secondsUntilClaimIsStale(AppointmentNotification $notification): int
    {
        $staleAt = $notification->updated_at->copy()->addMinutes(self::STALE_CLAIM_MINUTES);

        return max(0, (int) ceil(now()->diffInSeconds($staleAt, false))) + self::RELEASE_BUFFER_SECONDS;
    }

    private function isSms(AppointmentNotification $notification): bool
    {
        return $notification->channel === AppointmentNotification::CHANNEL_SMS;
    }

    /**
     * Recipients come from the appointment's own relationships, never from request input. Email recipients are
     * the P1.2 ones; an SMS row names its audience (patient or doctor) in its context.
     */
    private function recipientFor(AppointmentNotification $notification, Appointment $appointment): ?User
    {
        if ($this->isSms($notification)) {
            return AppointmentNotificationService::smsRecipient($appointment, $notification->context['audience'] ?? null);
        }

        return match ($notification->type) {
            AppointmentNotification::TYPE_BOOKED,
            AppointmentNotification::TYPE_CANCELLED => $appointment->slot?->doctorProfile?->user,
            AppointmentNotification::TYPE_CONFIRMED,
            AppointmentNotification::TYPE_REJECTED,
            AppointmentNotification::TYPE_RESCHEDULED => $appointment->patient,
            default => null,
        };
    }

    private function emailSkipReason(AppointmentNotification $notification, Appointment $appointment, User $recipient): ?string
    {
        return match (true) {
            ! filter_var($recipient->email, FILTER_VALIDATE_EMAIL) => 'Recipient has no valid email address.',
            ! $this->stillRelevant($notification, $appointment) => 'Appointment changed before the email was sent.',
            default => null,
        };
    }

    /**
     * The preference and phone number are read now, so an SMS always uses the recipient's current settings.
     */
    private function smsSkipReason(AppointmentNotification $notification, Appointment $appointment, User $recipient, SmsGateway $sms): ?string
    {
        return match (true) {
            ! $recipient->wantsAppointmentSms() => 'Recipient has SMS notifications turned off.',
            $recipient->smsPhoneNumber() === null => 'Recipient has no valid Bangladeshi mobile number.',
            ! $this->stillRelevant($notification, $appointment) => 'Appointment changed before the SMS was sent.',
            ! $sms->isConfigured() => 'No SMS provider is configured.',
            default => null,
        };
    }

    private function deliverSms(AppointmentNotification $notification, Appointment $appointment, User $recipient, SmsGateway $sms, AppointmentSmsComposer $composer): void
    {
        $message = $composer->compose($notification, $appointment);

        // From here on the provider may receive the request, so this row must never be re-sent automatically.
        $notification->update(['recipient_id' => $recipient->id, 'provider_requested_at' => now()]);

        try {
            $result = $sms->send($recipient->smsPhoneNumber(), $message, 'mydoc-notification-'.$notification->id);
        } catch (SmsDeliveryException $exception) {
            $this->recordSmsFailure($notification, $exception);

            if ($exception->outcome === SmsDeliveryException::RETRYABLE) {
                throw $exception;
            }

            return;
        } catch (Throwable $exception) {
            $this->recordSmsFailure($notification, SmsDeliveryException::uncertain('Unexpected error while sending the SMS.'));

            report($exception);

            return;
        }

        $notification->update([
            'status' => AppointmentNotification::STATUS_SENT,
            'last_error' => null,
            'provider_reference' => $result->providerReference,
            'sent_at' => now(),
        ]);
    }

    /**
     * Retryable failures return the row to "failed" (claimable again, marker cleared: the provider certainly did
     * not accept it). Permanent and uncertain outcomes are final.
     */
    private function recordSmsFailure(AppointmentNotification $notification, SmsDeliveryException $exception): void
    {
        $status = match ($exception->outcome) {
            SmsDeliveryException::RETRYABLE => AppointmentNotification::STATUS_FAILED,
            SmsDeliveryException::PERMANENT => AppointmentNotification::STATUS_UNDELIVERED,
            default => AppointmentNotification::STATUS_UNCONFIRMED,
        };

        $notification->update([
            'status' => $status,
            'last_error' => mb_substr($exception->getMessage(), 0, 255),
            'provider_reference' => $exception->providerCode,
            'provider_requested_at' => $exception->outcome === SmsDeliveryException::RETRYABLE ? null : $notification->provider_requested_at,
        ]);

        Log::warning('Appointment SMS delivery failed.', [
            'appointment_notification_id' => $notification->id,
            'appointment_id' => $notification->appointment_id,
            'type' => $notification->type,
            'attempt' => $notification->attempts,
            'outcome' => $exception->outcome,
            'provider_code' => $exception->providerCode,
        ]);
    }

    /**
     * Avoid sending an outdated message, e.g. a confirmation for an appointment that was cancelled while the
     * message waited in the queue, or a reminder for a slot the appointment no longer uses. Final-state messages
     * (rejected, cancelled) are always relevant.
     */
    private function stillRelevant(AppointmentNotification $notification, Appointment $appointment): bool
    {
        return match ($notification->type) {
            AppointmentNotification::TYPE_BOOKED => in_array($appointment->status, Appointment::ACTIVE_STATUSES, true),
            AppointmentNotification::TYPE_CONFIRMED => in_array($appointment->status, ['confirmed', 'completed'], true),
            AppointmentNotification::TYPE_RESCHEDULED => in_array($appointment->status, Appointment::ACTIVE_STATUSES, true)
                && $this->rescheduleHistory($notification)?->new_slot_id === $appointment->appointment_slot_id,
            AppointmentNotification::TYPE_REMINDER => $this->reminderStillRelevant($notification, $appointment),
            default => true,
        };
    }

    /**
     * A reminder is only sent for a booked or confirmed appointment that still uses the same slot at the same
     * start time, and that has not started yet.
     */
    private function reminderStillRelevant(AppointmentNotification $notification, Appointment $appointment): bool
    {
        $slot = $appointment->slot;

        return in_array($appointment->status, Appointment::ACTIVE_STATUSES, true)
            && $slot !== null
            && (int) ($notification->context['slot_id'] ?? 0) === $slot->id
            && (int) ($notification->context['starts_at'] ?? 0) === $slot->starts_at->getTimestamp()
            && $slot->starts_at->isFuture();
    }

    private function mailFor(AppointmentNotification $notification, Appointment $appointment): AppointmentMailNotification
    {
        return match ($notification->type) {
            AppointmentNotification::TYPE_BOOKED => new AppointmentBookedNotification($appointment),
            AppointmentNotification::TYPE_CONFIRMED => new AppointmentConfirmedNotification($appointment),
            AppointmentNotification::TYPE_REJECTED => new AppointmentRejectedNotification($appointment),
            AppointmentNotification::TYPE_CANCELLED => new AppointmentCancelledNotification($appointment),
            AppointmentNotification::TYPE_RESCHEDULED => new AppointmentRescheduledNotification(
                $appointment,
                $this->rescheduleHistory($notification)
            ),
        };
    }

    private function rescheduleHistory(AppointmentNotification $notification): ?AppointmentRescheduleHistory
    {
        $historyId = $notification->context['reschedule_history_id'] ?? null;

        return $historyId
            ? AppointmentRescheduleHistory::query()
                ->whereKey($historyId)
                ->where('appointment_id', $notification->appointment_id)
                ->first()
            : null;
    }
}
