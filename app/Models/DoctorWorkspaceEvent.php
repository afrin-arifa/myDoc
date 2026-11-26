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

    /**
     * A pending request closed by the system because its slot started before it was approved.
     */
    public const ACTION_EXPIRED = 'expired';

    public const ACTION_SLOT_BLOCKED = 'slot_blocked';

    public const ACTION_SLOT_UNBLOCKED = 'slot_unblocked';

    public const ACTION_SLOTS_GENERATED = 'slots_generated';

    public const ACTION_AVAILABILITY_CREATED = 'availability_created';

    public const ACTION_AVAILABILITY_UPDATED = 'availability_updated';

    public const ACTION_AVAILABILITY_DELETED = 'availability_deleted';

    public const ACTION_LOCATION_CREATED = 'location_created';

    /**
     * Details edited, archived or restored (context.changes holds is_active for the latter two).
     */
    public const ACTION_LOCATION_UPDATED = 'location_updated';

    public const ACTION_LOCATION_DELETED = 'location_deleted';

    public const ACTION_WAITLIST_OFFERED = 'waitlist_offered';

    public const ACTION_WAITLIST_DECLINED = 'waitlist_declined';

    public const ACTION_WAITLIST_OFFER_EXPIRED = 'waitlist_offer_expired';

    public const ACTION_WAITLIST_WITHDRAWN = 'waitlist_withdrawn';

    public const ACTION_WAITLIST_SETTINGS = 'waitlist_settings';

    /** A doctor's assistant uploaded a medical document for a patient in the doctor's care. */
    public const ACTION_DOCUMENT_UPLOADED = 'document_uploaded';

    /** Staff created a patient account with a one-time password. */
    public const ACTION_PATIENT_REGISTERED = 'patient_registered';

    /** Staff issued a new one-time password to a patient who had not signed in yet. */
    public const ACTION_PATIENT_PASSWORD_RESET = 'patient_password_reset';

    public const ACTION_ANNOUNCEMENT_POSTED = 'announcement_posted';

    public const ACTION_ANNOUNCEMENT_REMOVED = 'announcement_removed';

    /**
     * @var list<string>
     */
    public const APPOINTMENT_ACTIONS = [
        self::ACTION_BOOKED, self::ACTION_CONFIRMED, self::ACTION_REJECTED, self::ACTION_RESCHEDULED,
        self::ACTION_CANCELLED, self::ACTION_STARTED, self::ACTION_COMPLETED, self::ACTION_EXPIRED,
    ];

    /**
     * @var list<string>
     */
    public const ACTIONS = [
        ...self::APPOINTMENT_ACTIONS,
        self::ACTION_SLOT_BLOCKED, self::ACTION_SLOT_UNBLOCKED, self::ACTION_SLOTS_GENERATED,
        self::ACTION_AVAILABILITY_CREATED, self::ACTION_AVAILABILITY_UPDATED, self::ACTION_AVAILABILITY_DELETED,
        self::ACTION_LOCATION_CREATED, self::ACTION_LOCATION_UPDATED, self::ACTION_LOCATION_DELETED,
        self::ACTION_WAITLIST_OFFERED, self::ACTION_WAITLIST_DECLINED, self::ACTION_WAITLIST_OFFER_EXPIRED,
        self::ACTION_WAITLIST_WITHDRAWN, self::ACTION_WAITLIST_SETTINGS, self::ACTION_DOCUMENT_UPLOADED,
        self::ACTION_PATIENT_REGISTERED, self::ACTION_PATIENT_PASSWORD_RESET, self::ACTION_ANNOUNCEMENT_POSTED,
        self::ACTION_ANNOUNCEMENT_REMOVED,
    ];

    /**
     * actor_role for operations with no signed-in user (actor_id is null).
     */
    public const SYSTEM_ACTOR = 'system';

    public const SUBJECT_APPOINTMENT = 'appointment';

    public const SUBJECT_SLOT = 'appointment_slot';

    public const SUBJECT_AVAILABILITY = 'doctor_availability';

    public const SUBJECT_LOCATION = 'doctor_location';

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

    public function actionLabel(): string
    {
        return match (true) {
            $this->action === self::ACTION_SLOTS_GENERATED => 'Generated slots',
            $this->action === self::ACTION_DOCUMENT_UPLOADED => 'Uploaded a document',
            $this->action === self::ACTION_PATIENT_REGISTERED => 'Created a patient account',
            $this->action === self::ACTION_PATIENT_PASSWORD_RESET => 'Issued a new one-time password',
            $this->action === self::ACTION_ANNOUNCEMENT_POSTED => 'Posted an announcement',
            $this->action === self::ACTION_ANNOUNCEMENT_REMOVED => 'Removed an announcement',
            in_array($this->action, self::APPOINTMENT_ACTIONS, true) => ucfirst($this->action).' appointment',
            default => ucfirst(str_replace('_', ' ', $this->action)),
        };
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
