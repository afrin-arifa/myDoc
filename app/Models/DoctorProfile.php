<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'professional_title',
    'bio',
    'license_number',
    'consultation_fee',
    'years_of_experience',
    'is_accepting_patients',
])]
class DoctorProfile extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(
            Specialty::class,
            'doctor_specialty',
            'doctor_profile_id',
            'specialty_id'
        );
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class);
    }

    public function appointmentSlots(): HasMany
    {
        return $this->hasMany(AppointmentSlot::class);
    }

    public function googleCalendarConnection(): HasOne
    {
        return $this->hasOne(GoogleCalendarConnection::class);
    }

    /**
     * Limit the query to doctors who accept patients and whose account is active.
     *
     * @param  Builder<DoctorProfile>  $query
     */
    public function scopePubliclyListed(Builder $query): void
    {
        $query->where('doctor_profiles.is_accepting_patients', true)
            ->whereHas('user', fn (Builder $users) => $users->whereNull('deactivated_at'));
    }

    public function isPubliclyListed(): bool
    {
        $this->loadMissing('user');

        return $this->is_accepting_patients && $this->user?->deactivated_at === null;
    }
}
