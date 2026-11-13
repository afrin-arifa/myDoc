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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one recorded appointment email.
 *
 * The ledger row is claimed with a single conditional UPDATE, so only one worker can send it at a time and a row
 * already marked "sent" is never sent again, however many times the job is retried or duplicated. A row is only
 * marked "sent" after the mailer returns successfully; a failure marks it "failed" and rethrows for a retry.
 *
 * If another attempt still holds a fresh "sending" claim (for example a worker that crashed or timed out mid-send,
 * whose job the queue has now redelivered), this job releases itself until that claim becomes stale instead of
 * finishing, so the queue never deletes the only job that can recover the email.
 *
 * Timing: SMTP timeout (20s, config mail.mailers.smtp.timeout) < job timeout (45s) < database queue retry_after
 * (90s) < stale-claim window (10 minutes).
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
            ! filter_var($recipient->email, FILTER_VALIDATE_EMAIL) => 'Recipient has no valid email address.',
            ! $this->stillRelevant($notification, $appointment) => 'Appointment changed before the email was sent.',
            default => null,
        };

        if ($skipReason !== null) {
            $notification->update([
                'status' => AppointmentNotification::STATUS_SKIPPED,
                'recipient_id' => $recipient?->id,
                'last_error' => $skipReason,
            ]);

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
        Log::error('Appointment email delivery gave up after all retries.', [
            'appointment_notification_id' => $this->notificationId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    /**
     * Atomically move the row from pending/failed (or an abandoned claim) to "sending".
     */
    private function claim(): bool
    {
        return AppointmentNotification::query()
            ->whereKey($this->notificationId)
            ->where(fn ($query) => $query
                ->whereIn('status', [AppointmentNotification::STATUS_PENDING, AppointmentNotification::STATUS_FAILED])
                ->orWhere(fn ($stale) => $stale
                    ->where('status', AppointmentNotification::STATUS_SENDING)
                    ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))))
            ->increment('attempts', 1, [
                'status' => AppointmentNotification::STATUS_SENDING,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Called when the claim failed. "sent" and "skipped" rows (or a deleted row) are final, so the job simply
     * finishes. A "sending" row is held by another attempt: release this job once, until exactly when that claim
     * becomes stale, so it can take over if the other attempt never finishes. Pending/failed rows cannot reach
     * here because they are always claimable.
     */
    private function releaseWhileClaimedElsewhere(): void
    {
        $notification = AppointmentNotification::query()->find($this->notificationId);

        if ($notification?->status !== AppointmentNotification::STATUS_SENDING) {
            return;
        }

        $this->release($this->secondsUntilClaimIsStale($notification));
    }

    public function secondsUntilClaimIsStale(AppointmentNotification $notification): int
    {
        $staleAt = $notification->updated_at->copy()->addMinutes(self::STALE_CLAIM_MINUTES);

        return max(0, (int) ceil(now()->diffInSeconds($staleAt, false))) + self::RELEASE_BUFFER_SECONDS;
    }

    /**
     * Recipients come from the appointment's own relationships, never from request input.
     */
    private function recipientFor(AppointmentNotification $notification, Appointment $appointment): ?User
    {
        return match ($notification->type) {
            AppointmentNotification::TYPE_BOOKED,
            AppointmentNotification::TYPE_CANCELLED => $appointment->slot?->doctorProfile?->user,
            AppointmentNotification::TYPE_CONFIRMED,
            AppointmentNotification::TYPE_REJECTED,
            AppointmentNotification::TYPE_RESCHEDULED => $appointment->patient,
            default => null,
        };
    }

    /**
     * Avoid sending an outdated message, e.g. a confirmation for an appointment that was cancelled while the
     * email waited in the queue. Final-state messages (rejected, cancelled) are always relevant.
     */
    private function stillRelevant(AppointmentNotification $notification, Appointment $appointment): bool
    {
        return match ($notification->type) {
            AppointmentNotification::TYPE_BOOKED => in_array($appointment->status, Appointment::ACTIVE_STATUSES, true),
            AppointmentNotification::TYPE_CONFIRMED => in_array($appointment->status, ['confirmed', 'completed'], true),
            AppointmentNotification::TYPE_RESCHEDULED => in_array($appointment->status, Appointment::ACTIVE_STATUSES, true)
                && $this->rescheduleHistory($notification)?->new_slot_id === $appointment->appointment_slot_id,
            default => true,
        };
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
