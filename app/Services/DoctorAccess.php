<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\MedicalDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single source of truth for who may act in a doctor's workspace, and with which abilities.
 *
 *  - A doctor acts only for their own profile, and only while their account is active.
 *  - A Doctor Assistant acts only for doctors they are currently assigned to, while both accounts are active, and
 *    only with the abilities in ASSISTANT_ABILITIES. Assistants act on a doctor's behalf; they never become the
 *    doctor, so actions remain attributed to the assistant's own user id.
 *  - Patients and admins never act in a doctor's workspace (admins keep their own, separate admin permissions).
 *
 * Every check queries the database: nothing is cached on the user or in the session, so removing an assignment
 * or deactivating a doctor or assistant takes effect on the very next check. Profile ids are never trusted from
 * input: callers pass a profile (or a record that belongs to one) and this service decides.
 */
class DoctorAccess
{
    public const VIEW_APPOINTMENTS = 'view_appointments';

    public const BOOK_APPOINTMENTS = 'book_appointments';

    public const CONFIRM_APPOINTMENTS = 'confirm_appointments';

    public const REJECT_APPOINTMENTS = 'reject_appointments';

    public const RESCHEDULE_APPOINTMENTS = 'reschedule_appointments';

    public const CANCEL_APPOINTMENTS = 'cancel_appointments';

    public const START_APPOINTMENTS = 'start_appointments';

    public const COMPLETE_APPOINTMENTS = 'complete_appointments';

    public const MANAGE_SCHEDULE = 'manage_schedule';

    public const MANAGE_SLOTS = 'manage_slots';

    public const VIEW_PATIENTS = 'view_patients';

    public const VIEW_MEDICAL_DOCUMENTS = 'view_medical_documents';

    public const PREVIEW_MEDICAL_DOCUMENTS = 'preview_medical_documents';

    public const DOWNLOAD_MEDICAL_DOCUMENTS = 'download_medical_documents';

    public const UPLOAD_MEDICAL_DOCUMENTS = 'upload_medical_documents';

    public const DELETE_MEDICAL_DOCUMENTS = 'delete_medical_documents';

    public const EDIT_DOCTOR_PROFILE = 'edit_doctor_profile';

    public const MANAGE_DOCTOR_ACCOUNT = 'manage_doctor_account';

    public const MANAGE_GOOGLE_CALENDAR = 'manage_google_calendar';

    /**
     * Every ability this service knows. Checking any other name is a programming error and is always denied.
     *
     * @var list<string>
     */
    public const ABILITIES = [
        self::VIEW_APPOINTMENTS, self::BOOK_APPOINTMENTS, self::CONFIRM_APPOINTMENTS, self::REJECT_APPOINTMENTS,
        self::RESCHEDULE_APPOINTMENTS, self::CANCEL_APPOINTMENTS, self::START_APPOINTMENTS, self::COMPLETE_APPOINTMENTS,
        self::MANAGE_SCHEDULE, self::MANAGE_SLOTS, self::VIEW_PATIENTS, self::VIEW_MEDICAL_DOCUMENTS,
        self::PREVIEW_MEDICAL_DOCUMENTS, self::DOWNLOAD_MEDICAL_DOCUMENTS, self::UPLOAD_MEDICAL_DOCUMENTS,
        self::DELETE_MEDICAL_DOCUMENTS, self::EDIT_DOCTOR_PROFILE, self::MANAGE_DOCTOR_ACCOUNT, self::MANAGE_GOOGLE_CALENDAR,
    ];

    /**
     * What a doctor may do in their own workspace: the existing doctor permissions plus staff cancellation (with a
     * mandatory reason). Doctors cannot upload or delete patient documents, or manage their account through this
     * service (account changes are self-service or admin-only and are authorized elsewhere).
     *
     * @var list<string>
     */
    public const DOCTOR_ABILITIES = [
        self::VIEW_APPOINTMENTS, self::BOOK_APPOINTMENTS, self::CONFIRM_APPOINTMENTS, self::REJECT_APPOINTMENTS,
        self::RESCHEDULE_APPOINTMENTS, self::CANCEL_APPOINTMENTS, self::START_APPOINTMENTS, self::COMPLETE_APPOINTMENTS, self::MANAGE_SCHEDULE,
        self::MANAGE_SLOTS, self::VIEW_PATIENTS, self::VIEW_MEDICAL_DOCUMENTS, self::PREVIEW_MEDICAL_DOCUMENTS,
        self::DOWNLOAD_MEDICAL_DOCUMENTS, self::EDIT_DOCTOR_PROFILE, self::MANAGE_GOOGLE_CALENDAR,
    ];

    /**
     * What a Doctor Assistant may do for an assigned doctor. Anything not listed is denied, including starting or
     * completing consultations, editing the doctor's profile or account, Google Calendar and uploading or deleting
     * patient documents.
     *
     * @var list<string>
     */
    public const ASSISTANT_ABILITIES = [
        self::VIEW_APPOINTMENTS, self::BOOK_APPOINTMENTS, self::CONFIRM_APPOINTMENTS, self::REJECT_APPOINTMENTS,
        self::RESCHEDULE_APPOINTMENTS, self::CANCEL_APPOINTMENTS, self::MANAGE_SCHEDULE, self::MANAGE_SLOTS,
        self::VIEW_PATIENTS, self::VIEW_MEDICAL_DOCUMENTS, self::PREVIEW_MEDICAL_DOCUMENTS, self::DOWNLOAD_MEDICAL_DOCUMENTS,
    ];

    /**
     * Ids of the doctor profiles this user may currently act for (with at least one ability): their own profile
     * for an active doctor, the assigned profiles of active doctors for an active assistant, otherwise none.
     *
     * @return list<int>
     */
    public function actingProfileIds(User $user): array
    {
        return $this->actingProfiles($user)->orderBy('doctor_profiles.id')->pluck('doctor_profiles.id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Ids of the profiles this user may act for with the given ability.
     *
     * @return list<int>
     */
    public function profileIdsFor(User $user, string $ability): array
    {
        return $this->allows($user, $ability) ? $this->actingProfileIds($user) : [];
    }

    /**
     * Whether the user may use the ability for this doctor profile right now.
     */
    public function canActFor(User $user, DoctorProfile|int $profile, string $ability): bool
    {
        $profileId = $profile instanceof DoctorProfile ? $profile->id : $profile;

        return $profileId > 0
            && $this->allows($user, $ability)
            && $this->actingProfiles($user)->whereKey($profileId)->exists();
    }

    /**
     * Whether the user currently works for this doctor at all (any ability): the doctor themselves, or an assistant
     * currently assigned to them, with both accounts active. Used for read-only context such as the doctor's photo.
     */
    public function worksFor(User $user, DoctorProfile|int $profile): bool
    {
        $profileId = $profile instanceof DoctorProfile ? $profile->id : $profile;

        return $profileId > 0 && $this->actingProfiles($user)->whereKey($profileId)->exists();
    }

    /**
     * Whether the user may see this patient in the given doctor's patient list: they may view patients for that
     * doctor and the patient has (or had) an appointment with them, in any status, as the doctor patient list does.
     */
    public function canViewPatient(User $user, User $patient, DoctorProfile|int $profile): bool
    {
        $profileId = $profile instanceof DoctorProfile ? $profile->id : $profile;

        return $patient->role === 'patient'
            && $this->canActFor($user, $profileId, self::VIEW_PATIENTS)
            && Appointment::query()
                ->where('patient_id', $patient->id)
                ->whereHas('slot', fn (Builder $slots) => $slots->where('doctor_profile_id', $profileId))
                ->exists();
    }

    /**
     * Whether the patient is in the care of a doctor the user may act for with the given medical document ability:
     * the patient has a booked, confirmed or completed appointment with that doctor. Cancelled or rejected
     * appointments alone never grant access, and the document's own appointment link is irrelevant.
     *
     * When a doctor context is given, only that doctor counts: the user must currently be able to act for them with
     * the ability (assignment and both accounts active, checked now) and the patient must be in that doctor's care.
     */
    public function hasCareRelationship(User $user, int $patientId, string $ability = self::VIEW_MEDICAL_DOCUMENTS, ?DoctorProfile $doctorProfile = null): bool
    {
        $profileIds = $doctorProfile === null
            ? $this->profileIdsFor($user, $ability)
            : ($this->canActFor($user, $doctorProfile, $ability) ? [$doctorProfile->id] : []);

        return $profileIds !== [] && Appointment::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', MedicalDocument::CARE_STATUSES)
            ->whereHas('slot', fn (Builder $slots) => $slots->whereIn('doctor_profile_id', $profileIds))
            ->exists();
    }

    /**
     * Whether the role's ability list includes this ability (assignment and account status are checked separately).
     */
    public function allows(User $user, string $ability): bool
    {
        return match ($user->role) {
            'doctor' => in_array($ability, self::DOCTOR_ABILITIES, true),
            User::ROLE_ASSISTANT => in_array($ability, self::ASSISTANT_ABILITIES, true),
            default => false,
        };
    }

    /**
     * Profiles the user may act for, evaluated in the database. The doctor whose profile it is must be active in
     * every case, so deactivating a doctor immediately closes their workspace to their assistants too.
     *
     * @return Builder<DoctorProfile>
     */
    private function actingProfiles(User $user): Builder
    {
        $query = DoctorProfile::query()
            ->whereHas('user', fn (Builder $doctors) => $doctors->where('role', 'doctor')->whereNull('deactivated_at'));

        if ($user->deactivated_at !== null || $user->exists === false) {
            return $query->whereRaw('1 = 0');
        }

        return match ($user->role) {
            'doctor' => $query->where('doctor_profiles.user_id', $user->id),
            User::ROLE_ASSISTANT => $query->whereHas('assistants', fn (Builder $assistants) => $assistants
                ->whereKey($user->id)
                ->where('users.role', User::ROLE_ASSISTANT)
                ->whereNull('users.deactivated_at')),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
