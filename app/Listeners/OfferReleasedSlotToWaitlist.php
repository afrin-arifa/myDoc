<?php

namespace App\Listeners;

use App\Events\AppointmentSlotReleased;
use App\Jobs\OfferSlotToWaitlist;

/**
 * A slot became available again (cancellation, rejection, expiry, reschedule or unblock): offer it to the next
 * waiting-list patient. The event is dispatched after the releasing transaction commits; the offer itself runs on
 * the queue so the request that released the slot is not slowed down.
 */
class OfferReleasedSlotToWaitlist
{
    public function handle(AppointmentSlotReleased $event): void
    {
        OfferSlotToWaitlist::dispatch(slotId: $event->slotId);
    }
}
