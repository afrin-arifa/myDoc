<?php

namespace App\Jobs;

use App\Services\WaitlistMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Offers one slot (slotId) or a doctor's open slots (doctorProfileId) to waiting-list patients. Safe to run more than
 * once: holding a slot re-checks everything under its lock and a slot can have only one pending offer.
 */
class OfferSlotToWaitlist implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public int $timeout = 60;

    public function __construct(
        public ?int $slotId = null,
        public ?int $doctorProfileId = null,
    ) {}

    public function handle(WaitlistMatcher $matcher): void
    {
        if ($this->slotId !== null) {
            $matcher->offerSlot($this->slotId);

            return;
        }

        if ($this->doctorProfileId !== null) {
            $matcher->offerOpenSlots($this->doctorProfileId);
        }
    }
}
