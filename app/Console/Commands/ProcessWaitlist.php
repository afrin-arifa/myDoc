<?php

namespace App\Console\Commands;

use App\Services\WaitlistMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every minute: ends offers whose hold ran out and offers those slots to the next patient, ends entries whose
 * dates have passed, then offers any other open slot a waiting patient asked for (new slots from generation, or a
 * missed slot-release event). Each slot is handled in its own short transaction.
 */
#[Signature('mydoc:process-waitlist')]
#[Description('Expire waiting list offers, end past entries and offer open slots to waiting patients')]
class ProcessWaitlist extends Command
{
    public function handle(WaitlistMatcher $matcher): int
    {
        $released = $matcher->expireDueOffers();
        $reOffered = 0;

        foreach ($released as $slotId) {
            $reOffered += (int) ($matcher->offerSlot($slotId) !== null);
        }

        $endedEntries = $matcher->expirePastEntries();
        $offered = $matcher->offerOpenSlots();

        $this->info(count($released)." offer(s) expired ({$reOffered} re-offered), {$endedEntries} entr(y/ies) ended, {$offered} new offer(s).");

        return self::SUCCESS;
    }
}
