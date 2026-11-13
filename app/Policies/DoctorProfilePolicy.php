<?php

namespace App\Policies;

use App\Models\DoctorProfile;
use App\Models\User;

/**
 * Who may manage a doctor's profile and account. Ownership is checked against the stored user_id, never against
 * request input. Public directory visibility is handled by DoctorProfile::isPubliclyListed().
 */
class DoctorProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, DoctorProfile $doctorProfile): bool
    {
        return $this->isActiveAdmin($user) || $this->isOwningDoctor($user, $doctorProfile);
    }

    /**
     * Doctor accounts are created by administrators only.
     */
    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, DoctorProfile $doctorProfile): bool
    {
        return $this->isActiveAdmin($user) || $this->isOwningDoctor($user, $doctorProfile);
    }

    /**
     * Activate, deactivate and invite. Doctors can never change their own (or anyone's) account status.
     */
    public function manageAccount(User $user, DoctorProfile $doctorProfile): bool
    {
        return $this->isActiveAdmin($user) && $doctorProfile->user_id !== $user->id;
    }

    /**
     * The doctor directory and public doctor profiles are for guests, patients and administrators. Doctors work
     * only in their own workspace and cannot browse other doctors.
     */
    public function browseDirectory(?User $user): bool
    {
        return $user === null || in_array($user->role, ['patient', 'admin'], true);
    }

    /**
     * Doctors may load only their own photo; everyone allowed to browse the directory may load photos of listed
     * doctors; the owner and administrators may always load it.
     */
    public function viewPhoto(?User $user, DoctorProfile $doctorProfile): bool
    {
        if ($user !== null && $this->view($user, $doctorProfile)) {
            return true;
        }

        return $this->browseDirectory($user) && $doctorProfile->isPubliclyListed();
    }

    private function isOwningDoctor(User $user, DoctorProfile $doctorProfile): bool
    {
        return $user->role === 'doctor' && $user->deactivated_at === null && $doctorProfile->user_id === $user->id;
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === 'admin' && $user->deactivated_at === null;
    }
}
