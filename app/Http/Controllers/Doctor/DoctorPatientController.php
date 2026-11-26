<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\RegisterPatientRequest;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\User;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use App\Services\PatientRegistrationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorPatientController extends Controller
{
    public function __construct(private readonly DoctorAccess $access) {}

    /**
     * The signed-in doctor's own patients: every patient with at least one appointment with this doctor, listed
     * once. Counts and dates are scoped to this doctor, so nothing about other doctors' care is revealed.
     */
    public function index(Request $request, DoctorWorkspace $workspace): View
    {
        $profile = $workspace->forRequest($request, DoctorAccess::VIEW_PATIENTS)?->load(['user', 'specialties']);
        abort_unless($profile, 403);

        $search = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ])['search'] ?? null;

        $ownAppointments = fn (Builder $appointments) => $appointments
            ->whereHas('slot', fn (Builder $slots) => $slots->where('doctor_profile_id', $profile->id));

        $patients = User::query()
            ->where('role', 'patient')
            ->ofDoctor($profile->id)
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->select(['id', 'name', 'email', 'phone', 'photo_path', 'is_walk_in', 'deactivated_at', 'password_change_required', 'temporary_password_expires_at', 'registered_by_doctor_profile_id'])
            ->withCount([
                'appointments as appointment_count' => $ownAppointments,
                'appointments as upcoming_count' => fn (Builder $appointments) => $ownAppointments($appointments)->upcoming(),
            ])
            ->addSelect([
                'last_appointment_at' => AppointmentSlot::query()
                    ->select('appointment_slots.starts_at')
                    ->join('appointments', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
                    ->whereColumn('appointments.patient_id', 'users.id')
                    ->where('appointment_slots.doctor_profile_id', $profile->id)
                    ->where('appointment_slots.starts_at', '<=', now())
                    ->orderByDesc('appointment_slots.starts_at')
                    ->limit(1),
            ])
            ->withCasts(['last_appointment_at' => 'datetime'])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('doctor.patients.index', [
            'profile' => $profile,
            'patients' => $patients,
            'search' => $search,
            'canRegister' => $workspace->routeAvailable('doctor.patients.store')
                && $this->access->allows($request->user(), DoctorAccess::REGISTER_PATIENTS),
        ]);
    }

    public function create(Request $request, DoctorWorkspace $workspace): View
    {
        $profile = $workspace->forRequest($request, DoctorAccess::REGISTER_PATIENTS)?->load('user');
        abort_unless($profile, 403);

        return view('doctor.patients.create', [
            'profile' => $profile,
            'passwordLifetimeHours' => PatientRegistrationService::PASSWORD_LIFETIME_HOURS,
        ]);
    }

    /**
     * Create the patient's account and show its one-time password once, on the patients page.
     */
    public function store(RegisterPatientRequest $request, DoctorWorkspace $workspace, PatientRegistrationService $registrations): RedirectResponse
    {
        $profile = $workspace->forRequest($request, DoctorAccess::REGISTER_PATIENTS);
        abort_unless($profile, 403);

        [$patient, $password] = $registrations->register($request->user(), $profile, $request->validated('name'), $request->validated('email'), $request->validated('phone'));

        return redirect($workspace->url('doctor.patients.index'))->with('issuedCredentials', $this->credentials($patient, $password, true));
    }

    /**
     * A new one-time password for a patient this doctor's staff registered, before the patient chose their own.
     */
    public function issuePassword(Request $request, DoctorWorkspace $workspace, PatientRegistrationService $registrations, User $patient): RedirectResponse
    {
        $profile = $workspace->forRequest($request, DoctorAccess::REGISTER_PATIENTS);
        abort_unless($profile, 403);
        abort_unless($registrations->canIssueNewPassword($profile, $patient), 404);

        $password = $registrations->issueNewPassword($request->user(), $profile, $patient);

        return redirect($workspace->url('doctor.patients.index'))->with('issuedCredentials', $this->credentials($patient, $password, false));
    }

    /**
     * @return array{name: string, email: string, password: string, expires_at: string, is_new: bool}
     */
    private function credentials(User $patient, string $password, bool $isNew): array
    {
        return [
            'name' => $patient->name,
            'email' => $patient->email,
            'password' => $password,
            'expires_at' => $patient->temporary_password_expires_at->format('D, M j, Y g:i A'),
            'is_new' => $isNew,
        ];
    }
}
