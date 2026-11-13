<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'doctor_profile_id',
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
}
