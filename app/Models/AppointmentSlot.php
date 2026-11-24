<?php

namespace App\Models;

use App\Models\Concerns\HasUrlKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['doctor_profile_id', 'doctor_location_id', 'starts_at', 'ends_at', 'status'])]
class AppointmentSlot extends Model
{
    use HasUrlKey;

    /**
     * Every page that shows a slot also shows where it is held, so the location is always eager loaded (one extra
     * query per slot query instead of one per slot).
     *
     * @var list<string>
     */
    protected $with = ['location'];

    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    /**
     * Where this slot is held: copied from its weekly window when generated and never changed afterwards. Null for
     * slots created before locations existed and never linked.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(DoctorLocation::class, 'doctor_location_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Slots a patient could request right now: available and not yet started. Read-only listing filter; the booking
     * service re-checks this under a row lock.
     */
    public function scopeOpenForBooking(Builder $query): void
    {
        $query->where('appointment_slots.status', 'available')
            ->where('appointment_slots.starts_at', '>', now());
    }

    /**
     * Whether a patient could request this slot now: available, not started, and the doctor is listed. Used to send
     * patients back to the doctor's profile when a time has gone; the booking service remains the authority.
     */
    public function isOpenForBooking(): bool
    {
        return $this->status === 'available'
            && $this->starts_at->isFuture()
            && (bool) $this->doctorProfile?->isPubliclyListed();
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
