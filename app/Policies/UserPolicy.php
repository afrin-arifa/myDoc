<?php

namespace App\Policies;

use App\Models\User;
use App\Services\DoctorAccess;

class UserPolicy
{
    public function __construct(private readonly DoctorAccess $access) {}

    /**
     * A patient's photo is visible to the patient, to administrators, and to a doctor (or their assigned assistants)
     * whose patient list includes the patient. Other patients and other doctors never see it. An administrator's
     * photo is visible to administrators only. An assistant's photo is visible to the assistant, to administrators and
     * to the doctors they currently work for. Deactivated accounts see nothing.
     */
    public function viewPhoto(User $user, User $owner): bool
    {
        if ($user->deactivated_at !== null) {
            return false;
        }

        return match ($owner->role) {
            'admin' => $user->role === 'admin',
            'patient' => match ($user->role) {
                'admin' => true,
                'patient' => $user->is($owner),
                'doctor', User::ROLE_ASSISTANT => $this->isPatientOfAnActingDoctor($user, $owner),
                default => false,
            },
            User::ROLE_ASSISTANT => match ($user->role) {
                'admin' => true,
                User::ROLE_ASSISTANT => $user->is($owner),
                'doctor' => $user->doctorProfile !== null && $this->access->worksFor($owner, $user->doctorProfile),
                default => false,
            },
            default => false,
        };
    }

    private function isPatientOfAnActingDoctor(User $user, User $patient): bool
    {
        return collect($this->access->profileIdsFor($user, DoctorAccess::VIEW_PATIENTS))
            ->contains(fn (int $profileId): bool => $this->access->canViewPatient($user, $patient, $profileId));
    }
}
