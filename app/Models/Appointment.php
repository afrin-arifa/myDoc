<?php

namespace App\Models;

use App\Models\Concerns\HasUrlKey;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'appointment_slot_id',
    'patient_id',
    'follow_up_of_id',
    'status',
    'consultation_type',
    'consultation_fee',
    'fee_type',
    'patient_notes',
    'provider_notes',
    'booked_at',
    'cancelled_at',
    'cancellation_reason',
])]
class Appointment extends Model
{
    use HasUrlKey;

    /**
     * Statuses that still hold a slot and may take place in the future.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = ['booked', 'confirmed'];

    /**
     * Statuses that no longer hold their slot. Must match the active_slot_id generated column.
     *
     * @var list<string>
     */
    public const CLOSED_STATUSES = ['cancelled', 'rejected', 'expired'];

    /**
     * A pending ("booked") request whose slot started before the doctor approved it.
     */
    public const STATUS_EXPIRED = 'expired';

    /**
     * The one set of status labels shown to every role. "booked" is a request waiting for the doctor's approval.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        'booked' => 'Pending approval',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
    ];

    /**
     * Every consultation type an appointment can have. Voice and video stay valid for existing records.
     *
     * @var list<string>
     */
    public const CONSULTATION_TYPES = ['physical', 'voice', 'video'];

    /**
     * Patients may cancel online only until this many hours before the appointment starts.
     */
    public const PATIENT_CANCELLATION_NOTICE_HOURS = 24;

    /**
     * Label for an appointment status; other values (such as slot statuses) are capitalised as they are.
     */
    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst($status);
    }

    /**
     * Whether voice and video consultations can be booked and started. Off until a call provider exists.
     */
    public static function remoteConsultationsEnabled(): bool
    {
        return (bool) config('booking.remote_consultations_enabled', false);
    }

    /**
     * Consultation types that can be chosen for a new appointment.
     *
     * @return list<string>
     */
    public static function bookableConsultationTypes(): array
    {
        return self::remoteConsultationsEnabled() ? self::CONSULTATION_TYPES : ['physical'];
    }

    /**
     * The last moment a patient may cancel an appointment starting at the given time.
     */
    public static function patientCancellationDeadline(CarbonInterface $startsAt): CarbonInterface
    {
        return $startsAt->copy()->subHours(self::PATIENT_CANCELLATION_NOTICE_HOURS);
    }

    /**
     * Whether the patient can still cancel this appointment online: it is booked or confirmed and the deadline has
     * not passed. AppointmentBookingService applies the same rule under a lock.
     */
    public function isCancellableByPatient(): bool
    {
        $startsAt = $this->slot?->starts_at;

        return in_array($this->status, self::ACTIVE_STATUSES, true)
            && $startsAt !== null
            && now()->lt(self::patientCancellationDeadline($startsAt));
    }

    /**
     * Whether staff can start this consultation now: a confirmed voice or video appointment, while remote
     * consultations are enabled, from shortly before its start until it ends.
     */
    public function canStartConsultation(?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $slot = $this->slot;

        return $this->status === 'confirmed'
            && self::remoteConsultationsEnabled()
            && in_array($this->consultation_type, ['voice', 'video'], true)
            && $slot !== null
            && $now->between($slot->starts_at->copy()->subMinutes((int) config('app.consultation_start_early_minutes', 15)), $slot->ends_at);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'appointment_slot_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * The completed appointment this one follows up (same patient and doctor), if any.
     */
    public function followUpOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'follow_up_of_id');
    }

    /**
     * Appointments booked as follow-ups of this one.
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'follow_up_of_id');
    }

    /**
     * Whether a follow-up may be booked from this appointment: only completed appointments can be followed up.
     * The booking service re-checks this (and that no other follow-up is active) under the patient lock.
     */
    public function canBeFollowedUp(): bool
    {
        return $this->status === 'completed';
    }

    public function googleCalendarEvent(): HasOne
    {
        return $this->hasOne(AppointmentGoogleCalendarEvent::class);
    }

    public function rescheduleHistories(): HasMany
    {
        return $this->hasMany(AppointmentRescheduleHistory::class);
    }

    public function medicalDocuments(): HasMany
    {
        return $this->hasMany(MedicalDocument::class);
    }

    public function emailNotifications(): HasMany
    {
        return $this->hasMany(AppointmentNotification::class);
    }

    /**
     * Human-readable reference used in emails, e.g. APT-000042.
     */
    public function reference(): string
    {
        return self::referenceFor((int) $this->id);
    }

    /**
     * The reference for an appointment id, without loading the appointment.
     */
    public static function referenceFor(int $appointmentId): string
    {
        return 'APT-'.str_pad((string) $appointmentId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Limit the query to active (booked or confirmed) appointments whose slot has not started yet.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereIn('appointments.status', self::ACTIVE_STATUSES)
            ->whereHas('slot', fn (Builder $slots) => $slots->where('starts_at', '>', now()));
    }

    protected function casts(): array
    {
        return [
            'booked_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'consultation_fee' => 'decimal:2',
        ];
    }
}
