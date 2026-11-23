<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use App\Services\WaitlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Waiting list tab of a doctor's schedule, for the doctor or an assigned Doctor Assistant: the on/off switch,
 * offering a chosen time to a waiting patient, and withdrawing an offer. WaitlistService authorizes the doctor
 * context and checks the entry, offer and slot belong to that doctor.
 */
class DoctorWaitlistController extends Controller
{
    public function __construct(
        private readonly WaitlistService $waitlist,
        private readonly DoctorWorkspace $workspace,
    ) {}

    public function updateSettings(Request $request): RedirectResponse
    {
        $profile = $this->workspace->forRequest($request, DoctorAccess::MANAGE_SCHEDULE);
        abort_unless($profile, 403);
        $enabled = (bool) $request->validate(['waitlist_enabled' => ['required', 'boolean']])['waitlist_enabled'];

        $this->waitlist->setEnabled($request->user(), $profile, $enabled);

        return $this->backToWaitlist($enabled
            ? 'The waiting list is on. Patients can join it and matching times are offered automatically.'
            : 'The waiting list is off. Open offers were withdrawn and their times are bookable again; waiting patients keep their place.');
    }

    public function offer(Request $request, WaitlistEntry $entry): RedirectResponse
    {
        $profile = $this->workspace->forRequest($request, DoctorAccess::BOOK_APPOINTMENTS);
        abort_unless($profile, 403);
        $slotId = (int) $request->validate(['slot_id' => ['required', 'integer']])['slot_id'];

        $this->waitlist->offerByStaff($request->user(), $profile, $entry, $slotId);

        return $this->backToWaitlist('The time is held for the patient, who has been notified.');
    }

    public function withdraw(Request $request, WaitlistOffer $offer): RedirectResponse
    {
        $profile = $this->workspace->forRequest($request, DoctorAccess::BOOK_APPOINTMENTS);
        abort_unless($profile, 403);

        $this->waitlist->withdrawByStaff($request->user(), $profile, $offer);

        return $this->backToWaitlist('Offer withdrawn. The time is offered to the next waiting patient, or is bookable again.');
    }

    private function backToWaitlist(string $status): RedirectResponse
    {
        return back()->withFragment('waitlist-panel')->with('status', $status);
    }
}
