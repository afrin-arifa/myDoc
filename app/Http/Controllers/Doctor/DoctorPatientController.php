<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\User;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorPatientController extends Controller
{
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
            ->withAppointmentsForDoctor($profile->id)
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->select(['id', 'name', 'email', 'phone', 'deactivated_at'])
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
            'pendingCount' => Appointment::query()
                ->where('status', 'booked')
                ->whereHas('slot', fn (Builder $slots) => $slots->where('doctor_profile_id', $profile->id))
                ->count(),
        ]);
    }
}
