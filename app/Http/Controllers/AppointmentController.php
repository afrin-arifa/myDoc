<?php

namespace App\Http\Controllers;

use App\Http\Requests\Appointment\BookAppointmentRequest;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorAnnouncement;
use App\Services\AppointmentBookingService;
use App\Services\ConsultationFee;
use App\Services\PatientAppointmentRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public const SLOT_UNAVAILABLE_MESSAGE = 'That appointment time is no longer available. Please choose another time.';

    public function store(
        BookAppointmentRequest $request,
        AppointmentSlot $slot,
        AppointmentBookingService $booking
    ): RedirectResponse {
        try {
            $followUpOfId = $request->validated('follow_up_of_id');
            $appointment = $booking->book($request->user(), $slot->id, $request->validated('consultation_type'), $followUpOfId === null ? null : (int) $followUpOfId);
        } catch (ValidationException $exception) {
            // The booking service has already refused and rolled back. If the time itself has gone (taken, blocked or
            // started) while the doctor is still listed, send the patient to choose another time instead of back to
            // a confirm page that no longer exists. Limit and overlap refusals keep the patient on the confirm page.
            $slot = $slot->fresh('doctorProfile.user');

            if (! $request->expectsJson() && $slot !== null && ! $slot->isOpenForBooking() && $slot->doctorProfile?->isPubliclyListed()) {
                return $this->backToDoctorProfile($slot, $request->input('follow_up_of_id'));
            }

            throw $exception;
        }

        return redirect()->route('patient.appointments.index')->with('status', $appointment->wasRecentlyCreated
            ? 'Your appointment request has been sent. It is pending until the doctor approves it, and we will let you know once it is confirmed.'
            : 'You have already requested this appointment. It is pending until the doctor approves it.');
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Appointment::class);

        $patient = $request->user();
        $appointments = Appointment::query()
            ->whereBelongsTo($patient, 'patient')
            ->with(['slot.doctorProfile.user', 'slot.doctorProfile.specialties'])
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        if ($request->routeIs('patient.dashboard')) {
            $upcomingAppointments = Appointment::query()
                ->whereBelongsTo($patient, 'patient')
                ->upcoming()
                ->with(['slot.doctorProfile.user', 'slot.doctorProfile.specialties'])
                ->orderBy(AppointmentSlot::query()
                    ->select('starts_at')
                    ->whereColumn('appointment_slots.id', 'appointments.appointment_slot_id'))
                ->limit(3)
                ->get();

            return view('patient.dashboard', [
                'announcements' => DoctorAnnouncement::query()
                    ->visibleToPatient($patient)
                    ->with('doctorProfile.user:id,name')
                    ->limit(5)
                    ->get(),
                'upcomingAppointments' => $upcomingAppointments,
                'appointmentCounts' => array_map('intval', (array) $patient->appointments()
                    ->join('appointment_slots', 'appointment_slots.id', '=', 'appointments.appointment_slot_id')
                    ->selectRaw(
                        "count(*) as total,
                        sum(case when appointments.status in (?, ?) and appointment_slots.starts_at > ? then 1 else 0 end) as upcoming,
                        sum(case when appointments.status = 'booked' then 1 else 0 end) as booked,
                        sum(case when appointments.status = 'confirmed' then 1 else 0 end) as confirmed",
                        [...Appointment::ACTIVE_STATUSES, now()]
                    )
                    ->toBase()
                    ->first()),
            ]);
        }

        return view('patient.appointments.index', compact('appointments'));
    }

    /**
     * The booking review page. A slot of a doctor who is not listed stays a 404 (nothing about them is revealed); a
     * time that is no longer open sends the patient back to the doctor's profile to choose another. The limit and
     * overlap notices are read-only hints: the booking service still decides when the request is sent.
     */
    public function confirm(Request $request, AppointmentSlot $slot, PatientAppointmentRules $rules): View|RedirectResponse
    {
        $slot->load(['doctorProfile.user', 'doctorProfile.specialties', 'location']);

        abort_unless($slot->doctorProfile->isPubliclyListed(), 404);

        if (! $slot->isOpenForBooking()) {
            return $this->backToDoctorProfile($slot, Appointment::idFromUrlKey($request->query('follow_up')));
        }

        $patient = $request->user();
        $followUpOf = $rules->followUpOriginalFor($patient, old('follow_up_of_id', Appointment::idFromUrlKey($request->query('follow_up'))), (int) $slot->doctor_profile_id);

        return view('patient.appointments.confirm', [
            'slot' => $slot,
            'followUpOf' => $followUpOf,
            // The same rule the booking service applies when the request is sent.
            'fee' => ConsultationFee::forNewAppointment((int) $slot->doctor_profile_id, $followUpOf !== null),
            'hasReachedLimit' => $rules->hasReachedActiveLimit($patient),
            'activeLimit' => PatientAppointmentRules::activeLimit(),
            'overlapping' => $rules->overlappingAppointmentsFor($patient, [$slot])[$slot->id] ?? null,
        ]);
    }

    public function show(Request $request, Appointment $appointment, PatientAppointmentRules $rules): View
    {
        Gate::authorize('view', $appointment);

        $appointment->load(['slot.doctorProfile.user', 'slot.doctorProfile.specialties', 'followUpOf.slot', 'followUps.slot']);
        $canBookFollowUp = $appointment->slot->doctorProfile->isPubliclyListed()
            && $rules->followUpOriginalFor($request->user(), $appointment->id, (int) $appointment->slot->doctor_profile_id) !== null;

        return view('patient.appointments.show', compact('appointment', 'canBookFollowUp'));
    }

    public function cancel(
        Request $request,
        Appointment $appointment,
        AppointmentBookingService $booking
    ): RedirectResponse {
        Gate::authorize('cancel', $appointment);

        $booking->cancel($request->user(), $appointment->id);

        return back()->with('status', 'Your appointment has been cancelled.');
    }

    /**
     * The slot no longer exists, for example because the doctor changed their weekly hours while the patient had the
     * booking page open.
     */
    public static function slotRemoved(Request $request): RedirectResponse
    {
        abort_if($request->expectsJson(), 404);

        return redirect()->route('doctors.index')->with('warning', self::SLOT_UNAVAILABLE_MESSAGE);
    }

    private function backToDoctorProfile(AppointmentSlot $slot, mixed $followUp = null): RedirectResponse
    {
        return redirect()
            ->route('doctors.show', array_filter([
                'doctor' => $slot->doctorProfile,
                'date' => $slot->starts_at->toDateString(),
                'follow_up' => ($followUpId = Appointment::idFromUrlKey($followUp)) !== null ? Appointment::urlKey($followUpId) : null,
            ]))
            ->with('warning', self::SLOT_UNAVAILABLE_MESSAGE);
    }
}
