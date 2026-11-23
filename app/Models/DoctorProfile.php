<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use LogicException;

#[Fillable([
    'user_id',
    'professional_title',
    'bio',
    'qualifications',
    'license_number',
    'consultation_fee',
    'follow_up_fee',
    'years_of_experience',
    'is_accepting_patients',
    'waitlist_enabled',
])]
class DoctorProfile extends Model
{
    /**
     * Lock the doctor's profile row for the rest of the transaction. Every change to a doctor's weekly availability
     * and every slot generation takes this lock first, so they run one at a time per doctor: overlap checks on
     * availability windows and on generated slots always see the other operation's committed result. Booking never
     * takes it, so it does not slow down or change booking. Lock order: profile, then availability, then slots.
     *
     * @throws LogicException When called outside a transaction.
     */
    public static function lockForScheduling(int $doctorProfileId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The doctor schedule lock must be taken inside a transaction.');
        }

        static::query()->whereKey($doctorProfileId)->lockForUpdate()->first();
    }

    protected function casts(): array
    {
        return [
            'follow_up_fee' => 'decimal:2',
            'waitlist_enabled' => 'boolean',
        ];
    }

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

    /**
     * Active Doctor Assistant assignments for this doctor.
     */
    public function assistantAssignments(): HasMany
    {
        return $this->hasMany(AssistantAssignment::class);
    }

    /**
     * Users currently assigned to this doctor as Doctor Assistants.
     */
    public function assistants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'doctor_assistant', 'doctor_profile_id', 'assistant_id')
            ->using(AssistantAssignment::class)
            ->withPivot(['id', 'assigned_by'])
            ->withTimestamps();
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(DoctorLocation::class);
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    /**
     * Appointments on this doctor's slots.
     */
    public function appointments(): HasManyThrough
    {
        return $this->hasManyThrough(Appointment::class, AppointmentSlot::class, 'doctor_profile_id', 'appointment_slot_id');
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

    /**
     * URL of the profile photo, served through an authorizing controller (never a direct storage path).
     */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('doctors.photo', ['doctor' => $this, 'v' => substr(md5($this->photo_path), 0, 8)]) : null;
    }

    /**
     * Profile sections a doctor still needs to fill in after activation.
     *
     * @return list<string>
     */
    public function missingProfileSections(): array
    {
        $this->loadMissing('specialties');

        return array_keys(array_filter([
            'Biography' => blank($this->bio),
            'Qualifications' => blank($this->qualifications),
            'Specialties' => $this->specialties->isEmpty(),
            'Profile photo' => blank($this->photo_path),
        ]));
    }

    public function isPubliclyListed(): bool
    {
        $this->loadMissing('user');

        return $this->is_accepting_patients && $this->user?->deactivated_at === null;
    }
}
