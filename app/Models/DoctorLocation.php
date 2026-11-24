<?php

namespace App\Models;

use App\Models\Concerns\HasUrlKey;
use Database\Factories\DoctorLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A doctor's practice location (chamber). Weekly windows name the location they are held at, and each generated slot
 * keeps the location of its window, so an appointment always shows where it was booked.
 *
 * Editing a location's details applies everywhere, including past appointments. A location used by any window or
 * slot cannot be deleted (the foreign keys restrict it); it is archived instead (is_active = false), which hides it
 * from new schedules while history keeps showing it.
 */
#[Fillable(['doctor_profile_id', 'name', 'address', 'area', 'phone', 'directions', 'is_active'])]
class DoctorLocation extends Model
{
    /** @use HasFactory<DoctorLocationFactory> */
    use HasFactory, HasUrlKey;

    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class);
    }

    public function appointmentSlots(): HasMany
    {
        return $this->hasMany(AppointmentSlot::class);
    }

    /**
     * @param  Builder<DoctorLocation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('doctor_locations.is_active', true);
    }

    /**
     * One line for lists and messages, e.g. "Popular Diagnostic, Dhanmondi — House 16, Road 2".
     */
    public function summary(): string
    {
        return $this->address ? $this->name.' — '.$this->address : $this->name;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
