<?php

namespace App\Policies;

use App\Models\User;

/**
 * The all-doctors waiting list overview is for active administrators only. Patients act on their own entries and staff
 * on their doctor's entries through WaitlistService, which checks ownership and the doctor workspace itself.
 */
class WaitlistEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin' && $user->deactivated_at === null;
    }
}
