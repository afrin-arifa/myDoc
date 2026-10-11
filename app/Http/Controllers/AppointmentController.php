<?php

namespace App\Http\Controllers;

use App\Http\Requests\Appointment\BookAppointmentRequest;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Services\AppointmentBookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function store(
        BookAppointmentRequest $request,
        AppointmentSlot $slot,
        AppointmentBookingService $booking
    ): RedirectResponse {
        $booking->book($request->user(), $slot->id);

        return redirect()->route('patient.appointments.index')
            ->with('status', 'Your appointment has been booked.');
    }

    public function index(Request $request): View
    {
        $appointments = Appointment::query()
            ->whereBelongsTo($request->user(), 'patient')
            ->with(['slot.doctorProfile.user'])
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('patient.appointments.index', compact('appointments'));
    }

    public function show(Request $request, Appointment $appointment): View
    {
        abort_unless($appointment->patient_id === $request->user()->id, 403);

        $appointment->load(['slot.doctorProfile.user', 'slot.doctorProfile.specialties']);

        return view('patient.appointments.show', compact('appointment'));
    }

    public function cancel(
        Request $request,
        Appointment $appointment,
        AppointmentBookingService $booking
    ): RedirectResponse {
        abort_unless($appointment->patient_id === $request->user()->id, 403);

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
