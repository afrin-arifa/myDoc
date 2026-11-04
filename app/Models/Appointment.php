<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'appointment_slot_id',
    'patient_id',
    'status',
    'consultation_type',
    'patient_notes',
    'provider_notes',
    'booked_at',
    'cancelled_at',
])]
class Appointment extends Model
{
    public function slot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'appointment_slot_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function googleCalendarEvent(): HasOne
    {
        return $this->hasOne(AppointmentGoogleCalendarEvent::class);
    }

    public function rescheduleHistories(): HasMany
    {
        return $this->hasMany(AppointmentRescheduleHistory::class);
    }

    protected function casts(): array
    {
        return [
            'booked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
