<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\RescheduleAppointmentRequest;
use App\Http\Requests\Doctor\StoreDoctorAppointmentRequest;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\DoctorAppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorAppointmentController extends Controller
{
    /**
     * Maximum number of patients returned by a single lookup.
     */
    private const PATIENT_SEARCH_LIMIT = 10;

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

        $pendingCount = Appointment::query()
            ->where('appointments.status', 'booked')
            ->whereHas('slot', fn ($query) => $query->where('doctor_profile_id', $profileId))
            ->count();

        return view('doctor.appointments.index', compact('appointments', 'profile', 'startableAppointmentIds', 'pendingCount'));
    }

    public function create(Request $request): View
    {
        $profile = $request->user()->doctorProfile;
        abort_unless($profile, 403);

        $date = $request->date('date') ?? today();

        return view('doctor.appointments.create', [
            'profile' => $profile,
            'slots' => AppointmentSlot::query()
                ->where('doctor_profile_id', $profile->id)
                ->where('status', 'available')
                ->whereDate('starts_at', $date)
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->get(),
            'selectedDate' => $date,
        ]);
    }

    /**
     * Search active patients who have previously had an appointment with the authenticated doctor.
     */
    public function searchPatients(Request $request): JsonResponse
    {
        $profileId = $request->user()->doctorProfile?->id;
        abort_unless($profileId, 403);

        $search = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:100'],
        ])['search'];

        $patients = User::query()
            ->activePatients()
            ->withAppointmentsForDoctor($profileId)
            ->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(self::PATIENT_SEARCH_LIMIT)
            ->get(['id', 'name', 'email']);

        return response()->json([
            'data' => $patients->map(fn (User $patient): array => [
                'id' => $patient->id,
                'name' => $patient->name,
                'email' => $patient->email,
            ])->all(),
        ]);
    }

    public function store(StoreDoctorAppointmentRequest $request, AppointmentBookingService $booking): RedirectResponse
    {
        $booking->bookForDoctor(
            $request->user(),
            $request->patient(),
            $request->integer('slot_id'),
            $request->string('consultation_type')->toString(),
            $request->input('provider_notes')
        );

        return redirect()->route('doctor.schedule')->with('status', 'Appointment request created.');
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

    public function complete(Request $request, Appointment $appointment, DoctorAppointmentService $service): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);
        $service->complete($request->user(), $appointment);

        return back()->with('status', 'Appointment marked as completed.');
    }

    public function rescheduleForm(Request $request, Appointment $appointment): View
    {
        $this->authorizeAppointment($request, $appointment);
        $appointment->load('slot');

        return view('doctor.appointments.reschedule', [
            'appointment' => $appointment,
            'slots' => AppointmentSlot::query()
                ->where('doctor_profile_id', $request->user()->doctorProfile?->id)
                ->where('status', 'available')
                ->where('starts_at', '>', now())
                ->where('id', '!=', $appointment->appointment_slot_id)
                ->orderBy('starts_at')
                ->get(),
        ]);
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment, DoctorAppointmentService $service): RedirectResponse
    {
        $service->reschedule(
            $request->user(),
            $appointment,
            $request->integer('slot_id'),
            $request->input('reason')
        );

        return redirect()->route('doctor.appointments.show', $appointment)->with('status', 'Appointment rescheduled.');
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        abort_unless(
            $appointment->slot()->where('doctor_profile_id', $request->user()->doctorProfile?->id)->exists(),
            403
        );
    }
}
