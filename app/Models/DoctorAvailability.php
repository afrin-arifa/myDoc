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
    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }
}
