<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'appointment_id',
    'google_calendar_connection_id',
    'google_event_id',
    'status',
    'last_error',
    'synced_at',
])]
class AppointmentGoogleCalendarEvent extends Model
{
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendarConnection::class, 'google_calendar_connection_id');
    }

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
