<?php

namespace App\Policies;

use App\Models\User;

/**
 * Doctor Assistant accounts and their doctor assignments are managed only by active administrators. Assistants,
 * doctors and patients can never create assistants or change assignments, including their own.
 */
class AssistantAssignmentPolicy
{
    public function manage(User $user): bool
    {
        return $user->role === 'admin' && $user->deactivated_at === null;
    }
}
