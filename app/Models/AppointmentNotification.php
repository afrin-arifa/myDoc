<?php

namespace App\Models;

use App\Models\Concerns\HasUrlKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery ledger for appointment emails and SMS. One row identifies one message by appointment, channel, event
 * type and transition, so a retried or duplicated job can never deliver the same message twice once it is marked
 * sent. For SMS, provider_requested_at marks that a provider request may have been made: such a row is never
 * re-sent automatically, because the provider offers no idempotency key.
 */
#[Fillable([
    'appointment_id',
    'waitlist_offer_id',
    'channel',
    'type',
    'transition_key',
    'recipient_id',
    'status',
    'context',
    'attempts',
    'last_error',
    'sent_at',
    'provider_requested_at',
    'provider_reference',
])]
class AppointmentNotification extends Model
{
    use HasUrlKey;

    public const TYPE_BOOKED = 'booked';

    public const TYPE_CONFIRMED = 'confirmed';

    public const TYPE_REJECTED = 'rejected';

    public const TYPE_CANCELLED = 'cancelled';

    public const TYPE_RESCHEDULED = 'rescheduled';

    /**
     * A doctor or Doctor Assistant cancelled the appointment (patient cancellations use TYPE_CANCELLED). Sent to
     * both the patient and the doctor; each row names its audience in context.audience.
     */
    public const TYPE_STAFF_CANCELLED = 'staff_cancelled';

    /**
     * SMS only: an appointment reminder before the scheduled time.
     */
    public const TYPE_REMINDER = 'reminder';

    /**
     * A pending request reached its start time without the doctor confirming it. Sent to the patient only.
     */
    public const TYPE_EXPIRED = 'expired';

    /**
     * A time held for a waiting-list patient (row names waitlist_offer_id instead of appointment_id).
     */
    public const TYPE_WAITLIST_OFFER = 'waitlist_offer';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    /**
     * Who an SMS row is for, stored in context.audience and appended to its transition key.
     */
    public const AUDIENCE_PATIENT = 'patient';

    public const AUDIENCE_DOCTOR = 'doctor';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * Final: the SMS provider rejected the message permanently (e.g. a wrong sender id or a disabled account).
     */
    public const STATUS_UNDELIVERED = 'undelivered';

    /**
     * Final: the SMS request may have reached the provider (e.g. a timeout). Never retried, to avoid duplicates.
     */
    public const STATUS_UNCONFIRMED = 'unconfirmed';

    /**
     * Final until an administrator retries it: automatic retries ran out and the message was certainly not delivered
     * (an email whose sending failed, or an SMS the provider confirmed it did not accept). Delivery jobs cannot claim
     * it, so a stray duplicate job never sends it; only an explicit retry returns it to "pending".
     */
    public const STATUS_EXHAUSTED = 'exhausted';

    /**
     * Whether an administrator may queue this message again: only after automatic retries ran out, and never for an
     * SMS whose provider request may have gone out.
     */
    public function isRetryable(): bool
    {
        return $this->status === self::STATUS_EXHAUSTED
            && ($this->channel === self::CHANNEL_EMAIL || $this->provider_requested_at === null);
    }

    public function waitlistOffer(): BelongsTo
    {
        return $this->belongsTo(WaitlistOffer::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'provider_requested_at' => 'datetime',
        ];
    }
}
