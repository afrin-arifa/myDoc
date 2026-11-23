<?php

namespace App\Http\Controllers;

use App\Http\Requests\Appointment\JoinWaitlistRequest;
use App\Models\DoctorProfile;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use App\Services\AppointmentBookingService;
use App\Services\ConsultationFee;
use App\Services\WaitlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A patient's waiting list: joining a doctor's list, seeing their entries, and answering offers. Entries and offers of
 * other patients are not found (404). Accepting books the held slot through the normal booking service.
 */
class PatientWaitlistController extends Controller
{
    public function __construct(private readonly WaitlistService $waitlist) {}

    public function index(Request $request): View
    {
        return view('patient.waitlist.index', [
            'entries' => WaitlistEntry::query()
                ->where('patient_id', $request->user()->id)
                ->with(['doctorProfile.user:id,name', 'location:id,name', 'pendingOffer.slot', 'bookedAppointment:id'])
                ->orderByRaw("CASE WHEN status IN ('waiting', 'offered', 'paused') THEN 0 ELSE 1 END")
                ->latest('id')
                ->paginate(15),
        ]);
    }

    public function store(JoinWaitlistRequest $request, DoctorProfile $doctor): RedirectResponse
    {
        Gate::authorize('browseDirectory', DoctorProfile::class);
        abort_unless($doctor->isPubliclyListed(), 404);

        $this->waitlist->join($request->user(), $doctor, $request->validated());

        return redirect()->route('patient.waitlist.index')
            ->with('status', 'You are on the waiting list. When a matching time opens, we will hold it for you and let you know by email'
                .($request->user()->wantsAppointmentSms() ? ' and SMS' : '').'.');
    }

    public function destroy(Request $request, WaitlistEntry $entry): RedirectResponse
    {
        $this->waitlist->leave($request->user(), $entry);

        return back()->with('status', 'You have left the waiting list.');
    }

    public function resume(Request $request, WaitlistEntry $entry): RedirectResponse
    {
        $this->waitlist->resume($request->user(), $entry);

        return back()->with('status', 'Your waiting list request is active again.');
    }

    public function showOffer(Request $request, WaitlistOffer $offer): View
    {
        $offer->load(['entry.doctorProfile.user', 'slot.doctorProfile.user']);
        abort_unless((int) $offer->entry->patient_id === (int) $request->user()->id, 404);

        return view('patient.waitlist.offer', [
            'offer' => $offer,
            'fee' => ConsultationFee::forNewAppointment((int) $offer->slot->doctor_profile_id, $offer->entry->follow_up_of_id !== null),
        ]);
    }

    public function accept(Request $request, WaitlistOffer $offer, AppointmentBookingService $booking): RedirectResponse
    {
        $offer->load('entry');
        abort_unless((int) $offer->entry->patient_id === (int) $request->user()->id, 404);

        try {
            $booking->book(
                $request->user(),
                (int) $offer->appointment_slot_id,
                $offer->entry->consultation_type,
                $offer->entry->follow_up_of_id === null ? null : (int) $offer->entry->follow_up_of_id,
                (int) $offer->id,
            );
        } catch (ValidationException $exception) {
            return redirect()->route('patient.waitlist.offers.show', $offer)->withErrors($exception->errors());
        }

        return redirect()->route('patient.appointments.index')
            ->with('status', 'Your appointment request has been sent. It is pending until the doctor approves it, and we will let you know once it is confirmed.');
    }

    public function decline(Request $request, WaitlistOffer $offer): RedirectResponse
    {
        $this->waitlist->decline($request->user(), $offer);

        return redirect()->route('patient.waitlist.index')->with('status', 'Offer declined. You are still on the waiting list.');
    }
}
