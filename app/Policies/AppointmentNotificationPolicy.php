<?php

namespace App\Policies;

use App\Models\User;

/**
 * The appointment notification ledger is operational data: only active administrators may review it. Ledger rows
 * are created and updated by the notification service and delivery job only; nobody may create, edit, resend or
 * delete them through the application.
 */
class AppointmentNotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin' && $user->deactivated_at === null;
    }
}
