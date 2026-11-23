<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An appointment slot became bookable again. Dispatched by AppointmentSlotRelease once the transaction that released
 * the slot has committed (never for a rolled-back change), exactly once per release.
 *
 * Nothing listens to it in Release 1. It is the hook for a future waiting list: a listener can offer the slot to the
 * next waiting patient. The payload holds plain values only, so it is safe to queue.
 */
class AppointmentSlotReleased implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $slotId,
        public readonly int $doctorProfileId,
        public readonly string $startsAt,
        public readonly string $reason,
        public readonly ?int $appointmentId = null,
        public readonly ?int $actorId = null,
    ) {}
}
