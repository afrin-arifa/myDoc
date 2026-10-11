<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\DoctorAppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorAppointmentController extends Controller
{
    public function index(Request $request): View
    {
        $profile = $request->user()->doctorProfile?->load(['user', 'specialties']);
        $profileId = $profile?->id;
        abort_unless($profileId, 403);

        $validated = $request->validate([
            'tab' => ['nullable', 'in:upcoming,cancelled,completed,all'],
            'search' => ['nullable', 'string', 'max:100'],
            'period' => ['nullable', 'in:upcoming,past'],
            'status' => ['nullable', 'in:booked,confirmed,completed,cancelled,rejected'],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $validated['tab'] ??= 'upcoming';

        $appointments = Appointment::query()
            ->whereHas('slot', function ($slots) use ($profileId, $validated): void {
                $slots->where('doctor_profile_id', $profileId)
                    ->when(($validated['period'] ?? null) === 'upcoming', fn ($query) => $query->where('starts_at', '>=', now()))
                    ->when(($validated['period'] ?? null) === 'past', fn ($query) => $query->where('starts_at', '<', now()))
                    ->when(isset($validated['date']), fn ($query) => $query->whereDate('starts_at', $validated['date']));
            })
            ->when(($validated['tab'] ?? null) === 'upcoming', fn ($query) => $query
                ->whereIn('appointments.status', ['booked', 'confirmed'])
                ->whereHas('slot', fn ($slots) => $slots->where('starts_at', '>=', now())))
            ->when(($validated['tab'] ?? null) === 'cancelled', fn ($query) => $query->whereIn('appointments.status', ['cancelled', 'rejected']))
            ->when(($validated['tab'] ?? null) === 'completed', fn ($query) => $query->where('appointments.status', 'completed'))
            ->when(isset($validated['status']), fn ($query) => $query->where('appointments.status', $validated['status']))
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->whereHas('patient', fn ($patient) => $patient
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->whereHas('slot', fn ($slots) => $slots->whereDate('starts_at', '>=', $date)))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->whereHas('slot', fn ($slots) => $slots->whereDate('starts_at', '<=', $date)))
            ->with(['slot.doctorProfile.specialties', 'patient:id,name,email'])
            ->join('appointment_slots', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
            ->orderBy('appointment_slots.starts_at')
            ->orderBy('appointments.id')
            ->select('appointments.*')
            ->paginate(10)
            ->withQueryString();

        $now = CarbonImmutable::now();
        $earlyStartMinutes = (int) config('app.consultation_start_early_minutes', 15);
        $startableAppointmentIds = $appointments->getCollection()
            ->filter(function (Appointment $appointment) use ($now, $earlyStartMinutes): bool {
                if ($appointment->status !== 'confirmed') {
                    return false;
                }

                $startsAt = CarbonImmutable::instance($appointment->slot->starts_at);
                $endsAt = CarbonImmutable::instance($appointment->slot->ends_at);

                return $now->between($startsAt->subMinutes($earlyStartMinutes), $endsAt);
            })
            ->modelKeys();

        return view('doctor.appointments.index', compact('appointments', 'profile', 'startableAppointmentIds'));
    }

    public function show(Request $request, Appointment $appointment): View
    {
        $this->authorizeAppointment($request, $appointment);
        $appointment->load(['slot.doctorProfile.specialties', 'patient:id,name,email']);
        $now = CarbonImmutable::now();
        $startsAt = CarbonImmutable::instance($appointment->slot->starts_at);
        $endsAt = CarbonImmutable::instance($appointment->slot->ends_at);
        $canStart = $appointment->status === 'confirmed'
            && in_array($appointment->consultation_type, ['voice', 'video'], true)
            && $now->between($startsAt->subMinutes((int) config('app.consultation_start_early_minutes', 15)), $endsAt);

        return view('doctor.appointments.show', compact('appointment', 'canStart'));
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

    public function start(Request $request, Appointment $appointment, DoctorAppointmentService $service): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);
        $service->start($request->user(), $appointment);

        return back()->with('status', 'This consultation is ready to launch when a provider is configured.');
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        abort_unless(
            $appointment->slot()->where('doctor_profile_id', $request->user()->doctorProfile?->id)->exists(),
            403
        );
    }
}
