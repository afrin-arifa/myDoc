<?php

namespace App\Http\Controllers;

use App\Http\Requests\Appointment\BookAppointmentRequest;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Services\AppointmentBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function store(
        BookAppointmentRequest $request,
        AppointmentSlot $slot,
        AppointmentBookingService $booking
    ): RedirectResponse {
        $booking->book($request->user(), $slot->id, $request->validated('consultation_type'));

        return redirect()->route('patient.appointments.index')
            ->with('status', 'Your appointment has been booked.');
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Appointment::class);

        $patient = $request->user();
        $appointments = Appointment::query()
            ->whereBelongsTo($patient, 'patient')
            ->with(['slot.doctorProfile.user'])
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
                'upcomingAppointments' => $upcomingAppointments,
                'appointmentCounts' => [
                    'total' => $patient->appointments()->count(),
                    'upcoming' => $patient->appointments()->upcoming()->count(),
                    'booked' => $patient->appointments()->where('status', 'booked')->count(),
                    'confirmed' => $patient->appointments()->where('status', 'confirmed')->count(),
                ],
            ]);
        }

        return view('patient.appointments.index', compact('appointments'));
    }

    public function confirm(AppointmentSlot $slot): View
    {
        $slot->load(['doctorProfile.user', 'doctorProfile.specialties']);

        abort_unless(
            $slot->status === 'available'
                && $slot->starts_at->gt(CarbonImmutable::now())
                && $slot->doctorProfile->isPubliclyListed(),
            404
        );

        return view('patient.appointments.confirm', compact('slot'));
    }

    public function show(Request $request, Appointment $appointment): View
    {
        Gate::authorize('view', $appointment);

        $appointment->load(['slot.doctorProfile.user', 'slot.doctorProfile.specialties']);

        return view('patient.appointments.show', compact('appointment'));
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

    public function doctorIndex(Request $request): View
    {
        $profile = $request->user()->doctorProfile;
        $appointments = Appointment::query()
            ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $profile->id))
            ->with(['slot', 'patient:id,name'])
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('doctor.appointments.index', compact('appointments'));
    }
}
