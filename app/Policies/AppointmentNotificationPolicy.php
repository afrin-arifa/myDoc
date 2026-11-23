<?php

namespace App\Policies;

use App\Models\AppointmentNotification;
use App\Models\User;

/**
 * The appointment notification ledger is operational data: only active administrators may review it. Ledger rows
 * are created and updated by the notification service and delivery job; nobody may create, edit or delete them
 * through the application. The one manual action is retrying a message that certainly was not delivered after its
 * automatic retries ran out (AppointmentNotification::isRetryable()); uncertain SMS outcomes are never retried.
 */
class AppointmentNotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin' && $user->deactivated_at === null;
    }

    public function retry(User $user, AppointmentNotification $notification): bool
    {
        return $this->viewAny($user) && $notification->isRetryable();
    }
}
