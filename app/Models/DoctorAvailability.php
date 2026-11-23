<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'doctor_profile_id',
    'doctor_location_id',
    'day_of_week',
    'start_time',
    'end_time',
    'slot_duration_minutes',
    'is_active',
])]
class DoctorAvailability extends Model
{
    /**
     * The only supported appointment slot length. The slot_duration_minutes
     * column is retained for backward compatibility but is not configurable.
     */
    public const SLOT_DURATION_MINUTES = 10;

    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(DoctorLocation::class, 'doctor_location_id');
    }

    /**
     * Minutes since midnight for a stored or submitted time ("09:05" or "09:05:00"). Comparing minutes avoids the
     * string comparison pitfalls between "10:00" and "10:00:00" across database drivers.
     */
    public static function minuteOfDay(string $time): int
    {
        return ((int) substr($time, 0, 2) * 60) + (int) substr($time, 3, 2);
    }

    /**
     * Whether a slot held at the given location belongs to this window's location. A slot created before locations
     * existed (null) is treated as belonging to any window at its time, so linking old windows to a location never
     * blocks the open slots they already generated.
     */
    public function holdsSlotLocation(int|string|null $slotLocationId): bool
    {
        return $slotLocationId === null || (int) $slotLocationId === (int) $this->doctor_location_id;
    }

    /**
     * Whether this window (same weekday, Sunday = 0) fully contains the given slot. Slot times are wall-clock times in
     * the application timezone (Asia/Dhaka), the same as the window.
     */
    public function coversSlot(CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        $slotStart = ($startsAt->hour * 60) + $startsAt->minute;
        $slotEnd = $slotStart + (int) $startsAt->diffInMinutes($endsAt);

        return (int) $this->day_of_week === $startsAt->dayOfWeek
            && $slotStart >= self::minuteOfDay((string) $this->start_time)
            && $slotEnd <= self::minuteOfDay((string) $this->end_time);
    }
}
