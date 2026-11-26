<?php

namespace App\Models;

use App\Models\Concerns\HasUrlKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A notice a doctor or their assistant posts for that doctor's own patients. It is shown until it expires or the
 * patient dismisses it. Rows are written only by application code, never mass assigned from request input.
 */
class DoctorAnnouncement extends Model
{
    use HasUrlKey;

    public const TITLE_MAX_LENGTH = 120;

    public const BODY_MAX_LENGTH = 1000;

    protected $guarded = ['*'];

    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function dismissedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'doctor_announcement_dismissals');
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Announcements that have not expired.
     *
     * @param  Builder<DoctorAnnouncement>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $active) => $active->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Active announcements of the patient's doctors that the patient has not dismissed, important ones first.
     *
     * @param  Builder<DoctorAnnouncement>  $query
     */
    public function scopeVisibleToPatient(Builder $query, User $patient): void
    {
        $query->forPatient($patient)->whereDoesntHave('dismissedBy', fn (Builder $users) => $users->whereKey($patient->id));
    }

    /**
     * Active announcements of the patient's doctors (an appointment that puts them in the doctor's care, or an
     * account the doctor's staff created), including ones the patient dismissed. Important ones come first.
     *
     * @param  Builder<DoctorAnnouncement>  $query
     */
    public function scopeForPatient(Builder $query, User $patient): void
    {
        $query->active()
            ->where(fn (Builder $doctors) => $doctors
                ->whereIn('doctor_profile_id', AppointmentSlot::query()
                    ->select('appointment_slots.doctor_profile_id')
                    ->join('appointments', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
                    ->where('appointments.patient_id', $patient->id)
                    ->whereIn('appointments.status', MedicalDocument::CARE_STATUSES))
                ->when($patient->registered_by_doctor_profile_id !== null, fn (Builder $registered) => $registered
                    ->orWhere('doctor_profile_id', $patient->registered_by_doctor_profile_id)))
            ->orderByDesc('is_important')
            ->latest()
            ->latest('id');
    }

    /**
     * Active announcements a colleague posted for doctors this staff member works for (a doctor's own profile, an
     * assistant's assigned doctors) that this staff member has not read yet. Their own posts are never included.
     *
     * @param  Builder<DoctorAnnouncement>  $query
     * @param  list<int>  $doctorProfileIds
     */
    public function scopeUnreadForStaff(Builder $query, User $staff, array $doctorProfileIds): void
    {
        $query->active()
            ->whereIn('doctor_profile_id', $doctorProfileIds)
            ->where(fn (Builder $others) => $others->whereNull('author_id')->orWhere('author_id', '!=', $staff->id))
            ->whereDoesntHave('dismissedBy', fn (Builder $users) => $users->whereKey($staff->id))
            ->orderByDesc('is_important')
            ->latest()
            ->latest('id');
    }

    protected function casts(): array
    {
        return [
            'is_important' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }
}
