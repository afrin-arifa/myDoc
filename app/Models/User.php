<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasUrlKey;
use App\Notifications\Account\ResetPasswordNotification;
use App\Rules\BangladeshiMobileNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password', 'phone', 'address', 'sms_notifications_enabled'])]
#[Hidden(['password', 'remember_token', 'photo_path'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUrlKey, Notifiable;

    /**
     * Stored value of the Doctor Assistant role. The role column is never mass assignable.
     */
    public const ROLE_ASSISTANT = 'assistant';

    /**
     * Display labels for every stored role value.
     *
     * @var array<string, string>
     */
    public const ROLE_LABELS = [
        'patient' => 'Patient',
        'doctor' => 'Doctor',
        'admin' => 'Admin',
        self::ROLE_ASSISTANT => 'Doctor Assistant',
    ];

    public function doctorProfile(): HasOne
    {
        return $this->hasOne(DoctorProfile::class);
    }

    /**
     * Active assignments of this user, as a Doctor Assistant, to doctors.
     */
    public function assistantAssignments(): HasMany
    {
        return $this->hasMany(AssistantAssignment::class, 'assistant_id');
    }

    /**
     * Doctor profiles this user is currently assigned to as a Doctor Assistant. Assignment alone grants nothing;
     * access rules are applied separately.
     */
    public function assignedDoctors(): BelongsToMany
    {
        return $this->belongsToMany(DoctorProfile::class, 'doctor_assistant', 'assistant_id', 'doctor_profile_id')
            ->using(AssistantAssignment::class)
            ->withPivot(['id', 'assigned_by'])
            ->withTimestamps();
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? ucfirst((string) $this->role);
    }

    /**
     * How staff identify the person: their email, or the mobile number of a walk-in patient without one.
     */
    public function contactLabel(): string
    {
        return $this->email ?? $this->phone ?? 'No contact details';
    }

    /**
     * URL of the patient's, administrator's or assistant's photo, served through an authorizing controller (never a direct storage path).
     */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('users.photo', ['user' => $this, 'v' => substr(md5($this->photo_path), 0, 8)]) : null;
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'patient_id');
    }

    public function medicalDocuments(): HasMany
    {
        return $this->hasMany(MedicalDocument::class, 'patient_id');
    }

    /**
     * Send the password reset link with the application's branded template.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Limit the query to active patient accounts.
     *
     * @param  Builder<User>  $query
     */
    public function scopeActivePatients(Builder $query): void
    {
        $query->where('role', 'patient')->whereNull('deactivated_at');
    }

    /**
     * Limit the query to patients who have had at least one appointment with the doctor profile.
     *
     * @param  Builder<User>  $query
     */
    public function scopeWithAppointmentsForDoctor(Builder $query, int $doctorProfileId): void
    {
        $query->whereHas('appointments.slot', fn (Builder $slots) => $slots->where('doctor_profile_id', $doctorProfileId));
    }

    /**
     * Limit the query to the doctor's patients: anyone with an appointment with the doctor, or whose account the
     * doctor's staff created.
     *
     * @param  Builder<User>  $query
     */
    public function scopeOfDoctor(Builder $query, int $doctorProfileId): void
    {
        // One indexed id list instead of "registered or has appointment" per user, which scans every patient.
        $query->whereIn('users.id', DB::table('appointments')
            ->select('appointments.patient_id')
            ->join('appointment_slots', 'appointment_slots.id', '=', 'appointments.appointment_slot_id')
            ->where('appointment_slots.doctor_profile_id', $doctorProfileId)
            ->union(DB::table('users')->select('id')->where('registered_by_doctor_profile_id', $doctorProfileId)));
    }

    /**
     * True while the account still uses the one-time password staff gave the patient.
     */
    public function mustChangePassword(): bool
    {
        return $this->password_change_required === true;
    }

    /**
     * True when the one-time password can no longer be used to sign in.
     */
    public function temporaryPasswordExpired(): bool
    {
        return $this->mustChangePassword() && ($this->temporary_password_expires_at?->isPast() ?? true);
    }

    public function dismissedAnnouncements(): BelongsToMany
    {
        return $this->belongsToMany(DoctorAnnouncement::class, 'doctor_announcement_dismissals')->withPivot('created_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /**
     * The user's current phone number in SMS form (8801XXXXXXXXX), or null when it is missing or not a valid
     * Bangladeshi mobile number. Older free-text numbers are interpreted here rather than rewritten.
     */
    public function smsPhoneNumber(): ?string
    {
        return BangladeshiMobileNumber::normalize($this->phone);
    }

    /**
     * True when the user opted in to appointment SMS and their account is active. The phone number is checked
     * separately at delivery time so an invalid number can be reported.
     */
    public function wantsAppointmentSms(): bool
    {
        return $this->sms_notifications_enabled === true && $this->deactivated_at === null;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'deactivated_at' => 'datetime',
            'sms_notifications_enabled' => 'boolean',
            'is_walk_in' => 'boolean',
            'password_change_required' => 'boolean',
            'temporary_password_expires_at' => 'datetime',
        ];
    }
}
