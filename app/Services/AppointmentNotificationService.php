<?php

namespace App\Services;

use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use Illuminate\Support\Facades\DB;
use LogicException;

class AppointmentNotificationService
{
    /**
     * Record an appointment email and queue its delivery once the surrounding transaction commits.
     *
     * Call this inside the transaction that performs the appointment change: if that transaction rolls back,
     * both the ledger row and the queued job are discarded, so no email is ever sent for an uncommitted change.
     * The (appointment, type, transition) identity is unique, so recording the same transition twice is a no-op.
     *
     * @param  array<string, int|string>  $context  Non-sensitive identifiers needed to render the email later.
     */
    public function record(Appointment $appointment, string $type, ?string $transitionKey = null, array $context = []): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Appointment notifications must be recorded inside the transaction that changes the appointment.');
        }

        $notification = AppointmentNotification::query()->firstOrCreate(
            [
                'appointment_id' => $appointment->id,
                'type' => $type,
                'transition_key' => $transitionKey ?? $type,
            ],
            [
                'status' => AppointmentNotification::STATUS_PENDING,
                'context' => $context === [] ? null : $context,
            ]
        );

        if ($notification->wasRecentlyCreated) {
            SendAppointmentNotification::dispatch($notification->id)->afterCommit();
        }
    }
}
