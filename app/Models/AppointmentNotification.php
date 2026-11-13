<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery ledger for appointment emails. One row identifies one email by appointment, event type and
 * transition, so a retried or duplicated job can never deliver the same email twice once it is marked sent.
 */
#[Fillable([
    'appointment_id',
    'type',
    'transition_key',
    'recipient_id',
    'status',
    'context',
    'attempts',
    'last_error',
    'sent_at',
])]
class AppointmentNotification extends Model
{
    public const TYPE_BOOKED = 'booked';

    public const TYPE_CONFIRMED = 'confirmed';

    public const TYPE_REJECTED = 'rejected';

    public const TYPE_CANCELLED = 'cancelled';

    public const TYPE_RESCHEDULED = 'rescheduled';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

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
        ];
    }
}
