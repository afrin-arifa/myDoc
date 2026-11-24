<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\CancelAppointmentRequest;
use App\Http\Requests\Doctor\RescheduleAppointmentRequest;
use App\Http\Requests\Doctor\StoreDoctorAppointmentRequest;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\DoctorAccess;
use App\Services\DoctorAppointmentService;
use App\Services\DoctorWorkspace;
use App\Services\PatientAppointmentRules;
use App\Services\WalkInPatientService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Appointments in a doctor's workspace. The doctor context comes from DoctorWorkspace (the signed-in doctor's own
 * profile on these routes), the actor is always the signed-in user, and records must belong to the doctor context.
 */
class DoctorAppointmentController extends Controller
{
    /**
     * Maximum number of patients returned by a single lookup.
     */
    private const PATIENT_SEARCH_LIMIT = 10;

    public function __construct(
        private readonly DoctorWorkspace $workspace,
        private readonly DoctorAccess $access,
        private readonly PatientAppointmentRules $rules,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeForContext('viewAny', 'viewAppointments');

        $profile = $this->workspace->forRequest($request, DoctorAccess::VIEW_APPOINTMENTS)?->load(['user', 'specialties']);
        $profileId = $profile?->id;
        abort_unless($profileId, 403);

        $validated = $request->validate([
            'tab' => ['nullable', 'in:upcoming,cancelled,completed,all'],
            'search' => ['nullable', 'string', 'max:100'],
            'period' => ['nullable', 'in:upcoming,past'],
            'status' => ['nullable', Rule::in(array_keys(Appointment::STATUS_LABELS))],
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
            ->when(($validated['tab'] ?? null) === 'cancelled', fn ($query) => $query->whereIn('appointments.status', Appointment::CLOSED_STATUSES))
            ->when(($validated['tab'] ?? null) === 'completed', fn ($query) => $query->where('appointments.status', 'completed'))
            ->when(isset($validated['status']), fn ($query) => $query->where('appointments.status', $validated['status']))
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->whereHas('patient', fn ($patient) => $patient
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->whereHas('slot', fn ($slots) => $slots->whereDate('starts_at', '>=', $date)))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->whereHas('slot', fn ($slots) => $slots->whereDate('starts_at', '<=', $date)))
            ->with(['slot.doctorProfile.specialties', 'patient:id,name,email,phone,photo_path'])
            ->join('appointment_slots', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
            ->orderBy('appointment_slots.starts_at')
            ->orderBy('appointments.id')
            ->select('appointments.*')
            ->paginate(10)
            ->withQueryString();

        $now = CarbonImmutable::now();
        $startableAppointmentIds = $appointments->getCollection()
            ->filter(fn (Appointment $appointment): bool => $appointment->canStartConsultation($now))
            ->modelKeys();

        $pendingCount = Appointment::query()
            ->where('appointments.status', 'booked')
            ->whereHas('slot', fn ($query) => $query->where('doctor_profile_id', $profileId))
            ->count();

        return view('doctor.appointments.index', compact('appointments', 'profile', 'startableAppointmentIds', 'pendingCount'));
    }

    public function create(Request $request): View
    {
        $this->authorizeForContext('create', 'bookAppointments');
        $profile = $this->workspace->forRequest($request, DoctorAccess::BOOK_APPOINTMENTS);

        $date = $request->date('date') ?? today();

        return view('doctor.appointments.create', [
            'profile' => $profile,
            'followUpOf' => $this->followUpOriginal($profile, old('follow_up_of_id', Appointment::idFromUrlKey($request->query('follow_up')))),
            'slots' => AppointmentSlot::query()
                ->where('doctor_profile_id', $profile->id)
                ->where('status', 'available')
                ->whereDate('starts_at', $date)
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->get(),
            'selectedDate' => $date,
            'selectedSlotId' => AppointmentSlot::idFromUrlKey($request->query('slot')),
        ]);
    }

    /**
     * Search active patients who have previously had an appointment with the authenticated doctor.
     */
    public function searchPatients(Request $request): JsonResponse
    {
        $profileId = $this->workspace->forRequest($request, DoctorAccess::BOOK_APPOINTMENTS)?->id;
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
            ->get(['id', 'name', 'email', 'phone']);

        return response()->json([
            'data' => $patients->map(fn (User $patient): array => [
                'id' => $patient->id,
                'name' => $patient->name,
                'email' => $patient->contactLabel(),
            ])->all(),
        ]);
    }

    public function store(StoreDoctorAppointmentRequest $request, AppointmentBookingService $booking, WalkInPatientService $walkIns): RedirectResponse
    {
        // A walk-in patient is created together with the appointment: if the booking is refused, neither is kept.
        DB::transaction(fn () => $booking->bookForDoctor(
            $request->user(),
            $request->patient() ?? $walkIns->findOrCreate(
                $request->doctorProfile(),
                $request->string('walk_in_name')->toString(),
                $request->string('walk_in_phone')->toString(),
                $request->boolean('walk_in_sms'),
            ),
            $request->integer('slot_id'),
            $request->string('consultation_type')->toString(),
            $request->input('provider_notes'),
            $request->doctorProfile(),
            $request->filled('follow_up_of_id') ? $request->integer('follow_up_of_id') : null,
        ));

        return redirect()->to($this->workspace->url('doctor.schedule'))->with('status', 'Appointment created. It is pending approval until it is confirmed.');
    }

    public function show(Request $request, Appointment $appointment): View
    {
        Gate::authorize('view', $appointment);
        $this->contextFor($request, DoctorAccess::VIEW_APPOINTMENTS, $appointment);
        $appointment->load(['slot.doctorProfile.specialties', 'patient:id,name,email,phone,photo_path', 'followUpOf.slot', 'followUps.slot']);
        $canStart = $appointment->canStartConsultation();
        $canBookFollowUp = $this->workspace->routeAvailable('doctor.appointments.create')
            && $this->access->canActFor($request->user(), $appointment->slot->doctorProfile, DoctorAccess::BOOK_APPOINTMENTS)
            && $this->followUpOriginal($appointment->slot->doctorProfile, $appointment->id) !== null;

        return view('doctor.appointments.show', compact('appointment', 'canStart', 'canBookFollowUp'));
    }

    public function confirm(Request $request, Appointment $appointment, DoctorAppointmentService $service): RedirectResponse
    {
        Gate::authorize('confirm', $appointment);
        $service->transition($request->user(), $appointment, 'confirmed', $this->contextFor($request, DoctorAccess::CONFIRM_APPOINTMENTS, $appointment));

        return back()->with('status', 'Appointment confirmed.');
    }

    public function reject(Request $request, Appointment $appointment, DoctorAppointmentService $service): RedirectResponse
    {
        Gate::authorize('reject', $appointment);
        $service->transition($request->user(), $appointment, 'rejected', $this->contextFor($request, DoctorAccess::REJECT_APPOINTMENTS, $appointment));

        return back()->with('status', 'Appointment rejected and the slot is available again.');
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment, DoctorAppointmentService $service): RedirectResponse
    {
        $service->cancel(
            $request->user(),
            $appointment,
            $request->string('reason')->toString(),
            $this->contextFor($request, DoctorAccess::CANCEL_APPOINTMENTS, $appointment)
        );

        return redirect()->to($this->workspace->url('doctor.appointments.show', $appointment))
            ->with('status', 'Appointment cancelled. The patient has been notified.');
    }

    public function start(Request $request, Appointment $appointment, DoctorAppointmentService $service): RedirectResponse
    {
        Gate::authorize('start', $appointment);
        $service->start($request->user(), $appointment, $this->contextFor($request, DoctorAccess::START_APPOINTMENTS, $appointment));

        return back()->with('status', 'This consultation is ready to launch when a provider is configured.');
    }

    public function complete(Request $request, Appointment $appointment, DoctorAppointmentService $service): RedirectResponse
    {
        Gate::authorize('complete', $appointment);
        $service->complete($request->user(), $appointment, $this->contextFor($request, DoctorAccess::COMPLETE_APPOINTMENTS, $appointment));

        return back()->with('status', 'Appointment marked as completed.');
    }

    public function rescheduleForm(Request $request, Appointment $appointment): View
    {
        Gate::authorize('reschedule', $appointment);
        $profile = $this->contextFor($request, DoctorAccess::RESCHEDULE_APPOINTMENTS, $appointment);
        $appointment->load('slot');

        // One day of open slots at a time (the first day with any by default), like the staff booking form, instead
        // of every future slot: generation can create thousands of them.
        $openSlots = AppointmentSlot::query()
            ->where('doctor_profile_id', $profile->id)
            ->where('status', 'available')
            ->where('starts_at', '>', now())
            ->where('id', '!=', $appointment->appointment_slot_id);
        $firstOpen = (clone $openSlots)->min('starts_at');
        $date = $request->date('date', tz: config('app.timezone'))
            ?? ($firstOpen !== null ? Carbon::parse($firstOpen, config('app.timezone')) : today());

        return view('doctor.appointments.reschedule', [
            'appointment' => $appointment,
            'selectedDate' => $date->copy()->startOfDay(),
            'hasOpenSlots' => $firstOpen !== null,
            'slots' => (clone $openSlots)
                ->whereDate('starts_at', $date->toDateString())
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
            $request->input('reason'),
            $this->contextFor($request, DoctorAccess::RESCHEDULE_APPOINTMENTS, $appointment)
        );

        return redirect()->to($this->workspace->url('doctor.appointments.show', $appointment))->with('status', 'Appointment rescheduled.');
    }

    /**
     * Doctors keep their existing appointment permission; in an assistant workspace the per-doctor permission for the
     * selected doctor applies instead (assistants have no doctor-independent appointment permissions).
     */
    private function authorizeForContext(string $doctorAbility, string $workspaceAbility): void
    {
        $selected = $this->workspace->currentDoctorProfile();

        $selected === null
            ? Gate::authorize($doctorAbility, Appointment::class)
            : Gate::authorize($workspaceAbility, $selected);
    }

    /**
     * The doctor context for an action on an appointment: authorized for the ability, and the appointment must
     * belong to that doctor.
     */
    private function contextFor(Request $request, string $ability, Appointment $appointment): DoctorProfile
    {
        $profile = $this->workspace->forRequest($request, $ability);
        abort_unless($profile, 403);
        $this->workspace->ensureBelongs($profile, $appointment->slot()->value('doctor_profile_id'));

        return $profile;
    }

    /**
     * A completed appointment of this doctor that a follow-up can be booked from right now, or null. The id comes from
     * the query string, so it is only looked up among this doctor's appointments; the booking service re-checks it.
     */
    private function followUpOriginal(DoctorProfile $profile, mixed $appointmentId): ?Appointment
    {
        if (! is_scalar($appointmentId) || ! ctype_digit((string) $appointmentId)) {
            return null;
        }

        $original = Appointment::query()
            ->with(['patient:id,name,email,phone,role,deactivated_at', 'slot'])
            ->whereKey((int) $appointmentId)
            ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $profile->id))
            ->first();

        return $original?->patient !== null && $original->patient->deactivated_at === null
            ? $this->rules->followUpOriginalFor($original->patient, $original->id, (int) $profile->id)?->setRelation('patient', $original->patient)
            : null;
    }
}
