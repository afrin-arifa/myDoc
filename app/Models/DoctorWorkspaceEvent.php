<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit event of a doctor workspace operation. Events are written only through
 * DoctorWorkspaceEventService, inside the transaction of the operation they describe, and can never be changed or
 * deleted through the application. Nothing is mass assignable.
 */
class DoctorWorkspaceEvent extends Model
{
    public const ACTION_BOOKED = 'booked';

    public const ACTION_CONFIRMED = 'confirmed';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_RESCHEDULED = 'rescheduled';

    public const ACTION_CANCELLED = 'cancelled';

    public const ACTION_STARTED = 'started';

    public const ACTION_COMPLETED = 'completed';

    public const ACTION_SLOT_BLOCKED = 'slot_blocked';

    public const ACTION_SLOT_UNBLOCKED = 'slot_unblocked';

    public const ACTION_SLOTS_GENERATED = 'slots_generated';

    public const ACTION_AVAILABILITY_CREATED = 'availability_created';

    public const ACTION_AVAILABILITY_UPDATED = 'availability_updated';

    public const ACTION_AVAILABILITY_DELETED = 'availability_deleted';

    /**
     * @var list<string>
     */
    public const APPOINTMENT_ACTIONS = [
        self::ACTION_BOOKED, self::ACTION_CONFIRMED, self::ACTION_REJECTED, self::ACTION_RESCHEDULED,
        self::ACTION_CANCELLED, self::ACTION_STARTED, self::ACTION_COMPLETED,
    ];

    /**
     * @var list<string>
     */
    public const ACTIONS = [
        ...self::APPOINTMENT_ACTIONS,
        self::ACTION_SLOT_BLOCKED, self::ACTION_SLOT_UNBLOCKED, self::ACTION_SLOTS_GENERATED,
        self::ACTION_AVAILABILITY_CREATED, self::ACTION_AVAILABILITY_UPDATED, self::ACTION_AVAILABILITY_DELETED,
    ];

    /**
     * actor_role for operations with no signed-in user (actor_id is null).
     */
    public const SYSTEM_ACTOR = 'system';

    public const SUBJECT_APPOINTMENT = 'appointment';

    public const SUBJECT_SLOT = 'appointment_slot';

    public const SUBJECT_AVAILABILITY = 'doctor_availability';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (! in_array($event->action, self::ACTIONS, true)) {
                throw new LogicException('Unknown doctor workspace action.');
            }
        });

        static::updating(fn () => throw new LogicException('Doctor workspace audit events cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Doctor workspace audit events cannot be deleted.'));
    }

    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
