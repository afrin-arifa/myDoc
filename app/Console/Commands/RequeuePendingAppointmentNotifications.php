<?php

namespace App\Console\Commands;

use App\Jobs\SendAppointmentNotification;
use App\Models\AppointmentNotification;
use Illuminate\Console\Command;

/**
 * Recovers appointment emails whose queue job was never created, e.g. when the appointment transaction committed
 * but pushing the job to the queue failed afterwards. Such rows stay "pending" with no job to deliver them.
 *
 * Safe to run repeatedly: it never marks anything as sent, and a duplicate job cannot send twice because delivery
 * is guarded by the atomic claim on the ledger row. Each requeue refreshes the row's updated_at, so a row is
 * requeued at most once per window even if its original job is merely waiting in a long queue.
 */
class RequeuePendingAppointmentNotifications extends Command
{
    /**
     * Minutes a row may stay "pending" without progress before it is considered to have lost its job.
     */
    public const PENDING_AFTER_MINUTES = 30;

    protected $signature = 'mydoc:requeue-pending-notifications';

    protected $description = 'Re-queue appointment emails that have been pending without a delivery job';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(self::PENDING_AFTER_MINUTES);
        $requeued = 0;

        AppointmentNotification::query()
            ->where('status', AppointmentNotification::STATUS_PENDING)
            ->where('updated_at', '<', $cutoff)
            ->select('id')
            ->chunkById(200, function ($notifications) use ($cutoff, &$requeued): void {
                foreach ($notifications as $notification) {
                    $touched = AppointmentNotification::query()
                        ->whereKey($notification->id)
                        ->where('status', AppointmentNotification::STATUS_PENDING)
                        ->where('updated_at', '<', $cutoff)
                        ->update(['updated_at' => now()]);

                    if ($touched === 1) {
                        SendAppointmentNotification::dispatch($notification->id);
                        $requeued++;
                    }
                }
            });

        $this->info("{$requeued} pending appointment email(s) re-queued.");

        return self::SUCCESS;
    }
}
