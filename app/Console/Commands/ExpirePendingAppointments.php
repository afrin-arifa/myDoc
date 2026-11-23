<?php

namespace App\Console\Commands;

use App\Services\PendingAppointmentExpiry;
use Illuminate\Console\Command;

/**
 * Expires pending appointment requests whose slot has started without approval. Scheduled every minute; safe to run
 * repeatedly or by hand (each request is expired at most once).
 */
class ExpirePendingAppointments extends Command
{
    protected $signature = 'mydoc:expire-pending-appointments';

    protected $description = 'Expire pending appointment requests whose slot has started without approval';

    public function handle(PendingAppointmentExpiry $expiry): int
    {
        $expired = $expiry->expireDue();

        $this->components->info($expired === 1 ? '1 pending appointment request expired.' : "{$expired} pending appointment requests expired.");

        return self::SUCCESS;
    }
}
