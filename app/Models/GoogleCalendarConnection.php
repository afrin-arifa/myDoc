<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'doctor_profile_id',
    'google_account_email',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'calendar_id',
    'is_connected',
    'last_synced_at',
])]
class GoogleCalendarConnection extends Model
{
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    public function appointmentEvents(): HasMany
    {
        return $this->hasMany(AppointmentGoogleCalendarEvent::class);
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'is_connected' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
