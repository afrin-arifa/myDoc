<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\DoctorAppointmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorAppointmentController extends Controller
{
    public function index(Request $request): View
    {
        $profileId = $request->user()->doctorProfile?->id;
        abort_unless($profileId, 403);

        $validated = $request->validate([
            'period' => ['nullable', 'in:upcoming,past'],
            'status' => ['nullable', 'in:booked,confirmed,completed,cancelled,rejected'],
            'date' => ['nullable', 'date'],
        ]);

        $appointments = Appointment::query()
            ->whereHas('slot', function ($slots) use ($profileId, $validated): void {
                $slots->where('doctor_profile_id', $profileId)
                    ->when(($validated['period'] ?? null) === 'upcoming', fn ($query) => $query->where('starts_at', '>=', now()))
                    ->when(($validated['period'] ?? null) === 'past', fn ($query) => $query->where('starts_at', '<', now()))
                    ->when(isset($validated['date']), fn ($query) => $query->whereDate('starts_at', $validated['date']));
            })
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->with(['slot.doctorProfile', 'patient:id,name,email'])
            ->join('appointment_slots', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
            ->orderBy('appointment_slots.starts_at')
            ->orderBy('appointments.id')
            ->select('appointments.*')
            ->paginate(10)
            ->withQueryString();

        return view('doctor.appointments.index', compact('appointments'));
    }

    public function show(Request $request, Appointment $appointment): View
    {
        $this->authorizeAppointment($request, $appointment);
        $appointment->load(['slot.doctorProfile', 'patient:id,name,email']);

        return view('doctor.appointments.show', compact('appointment'));
    }

    public function confirm(Request $request, Appointment $appointment, DoctorAppointmentService $service): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);
        $service->transition($request->user(), $appointment, 'confirmed');

        return back()->with('status', 'Appointment confirmed.');
    }

    public function reject(Request $request, Appointment $appointment, DoctorAppointmentService $service): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);
        $service->transition($request->user(), $appointment, 'rejected');

        return back()->with('status', 'Appointment rejected and the slot is available again.');
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        abort_unless(
            $appointment->slot()->where('doctor_profile_id', $request->user()->doctorProfile?->id)->exists(),
            403
        );
    }
}
