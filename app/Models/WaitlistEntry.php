<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\WaitlistEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A patient waiting for a time with a doctor within a date range (Asia/Dhaka calendar days), optionally limited to a
 * time of day, one location and a consultation type. Entries are served first come, first served; WaitlistMatcher
 * offers released or new slots to them and WaitlistService handles every change.
 */
#[Fillable([
    'patient_id', 'doctor_profile_id', 'doctor_location_id', 'date_from', 'date_to', 'time_of_day',
    'consultation_type', 'follow_up_of_id', 'status', 'expired_offer_count', 'booked_appointment_id',
])]
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use HasFactory;

    public const STATUS_WAITING = 'waiting';

    /**
     * A slot is currently held for this entry (one pending offer).
     */
    public const STATUS_OFFERED = 'offered';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The date range passed without a booking.
     */
    public const STATUS_EXPIRED = 'expired';

    /**
     * Stopped after too many unanswered offers; the patient can resume it.
     */
    public const STATUS_PAUSED = 'paused';

    /**
     * Entries that count towards the patient's limit and may still be offered a time (paused ones count too).
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = [self::STATUS_WAITING, self::STATUS_OFFERED, self::STATUS_PAUSED];

    /**
     * Time-of-day preferences as [first hour, end hour) in the application timezone.
     *
     * @var array<string, array{0: int, 1: int, 2: string}>
     */
    public const TIMES_OF_DAY = [
        'morning' => [0, 12, 'Morning (before 12:00)'],
        'afternoon' => [12, 17, 'Afternoon (12:00–17:00)'],
        'evening' => [17, 24, 'Evening (after 17:00)'],
    ];

    public const STATUS_LABELS = [
        self::STATUS_WAITING => 'Waiting',
        self::STATUS_OFFERED => 'Time offered',
        self::STATUS_BOOKED => 'Booked',
        self::STATUS_CANCELLED => 'Left the list',
        self::STATUS_EXPIRED => 'Dates passed',
        self::STATUS_PAUSED => 'Paused',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(DoctorLocation::class, 'doctor_location_id');
    }

    public function followUpOf(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'follow_up_of_id');
    }

    public function bookedAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'booked_appointment_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(WaitlistOffer::class);
    }

    public function pendingOffer(): HasOne
    {
        return $this->hasOne(WaitlistOffer::class)->where('status', WaitlistOffer::STATUS_PENDING);
    }

    /**
     * Whether this entry asks for a slot at this time and place (not whether the patient can take it right now).
     */
    public function wantsSlot(AppointmentSlot $slot): bool
    {
        $startsAt = $slot->starts_at->copy()->setTimezone(config('app.timezone'));
        $day = $startsAt->toDateString();

        return (int) $slot->doctor_profile_id === (int) $this->doctor_profile_id
            && $day >= $this->date_from->toDateString()
            && $day <= $this->date_to->toDateString()
            && ($this->doctor_location_id === null || (int) $slot->doctor_location_id === (int) $this->doctor_location_id)
            && $this->matchesTimeOfDay($startsAt);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    public static function timeOfDayLabel(?string $timeOfDay): string
    {
        return self::TIMES_OF_DAY[$timeOfDay][2] ?? 'Any time';
    }

    private function matchesTimeOfDay(CarbonInterface $startsAt): bool
    {
        if ($this->time_of_day === null || ! isset(self::TIMES_OF_DAY[$this->time_of_day])) {
            return true;
        }

        [$from, $until] = self::TIMES_OF_DAY[$this->time_of_day];

        return $startsAt->hour >= $from && $startsAt->hour < $until;
    }

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'expired_offer_count' => 'integer',
        ];
    }
}
