<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'appointment_id',
    'previous_slot_id',
    'new_slot_id',
    'performed_by',
    'previous_starts_at',
    'new_starts_at',
    'reason',
])]
class AppointmentRescheduleHistory extends Model
{
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function previousSlot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'previous_slot_id');
    }

    public function newSlot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'new_slot_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
