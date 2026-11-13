<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Doctor Assistant's own pages: dashboard and doctor list. Only doctors the assistant currently works for
 * (DoctorAccess: current assignment, both accounts active) are ever queried, so no other doctor is exposed.
 */
class AssistantDashboardController extends Controller
{
    private const UPCOMING_LIMIT = 10;

    public function __construct(private readonly DoctorAccess $access) {}

    public function dashboard(Request $request): View
    {
        $doctors = $this->assignedDoctors($request);

        return view('assistant.dashboard', [
            'assistant' => $request->user(),
            'doctors' => $doctors,
            'upcoming' => $doctors->isEmpty() ? collect() : Appointment::query()
                ->upcoming()
                ->whereHas('slot', fn (Builder $slots) => $slots->whereIn('doctor_profile_id', $doctors->modelKeys()))
                ->with(['patient:id,name', 'slot.doctorProfile.user:id,name'])
                ->join('appointment_slots', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
                ->orderBy('appointment_slots.starts_at')
                ->select('appointments.*')
                ->limit(self::UPCOMING_LIMIT)
                ->get(),
        ]);
    }

    public function doctors(Request $request): View
    {
        return view('assistant.doctors', ['doctors' => $this->assignedDoctors($request)]);
    }

    /**
     * Opening a doctor's workspace starts at their appointments (access is checked by the route middleware).
     */
    public function workspace(DoctorWorkspace $workspace): RedirectResponse
    {
        return redirect()->to($workspace->url('doctor.appointments.index'));
    }

    /**
     * @return Collection<int, DoctorProfile>
     */
    private function assignedDoctors(Request $request): Collection
    {
        return DoctorProfile::query()
            ->whereKey($this->access->actingProfileIds($request->user()))
            ->with(['user:id,name,email', 'specialties:id,name'])
            ->withCount([
                'appointments as pending_count' => fn (Builder $appointments) => $appointments->where('appointments.status', 'booked'),
                'appointments as upcoming_count' => fn (Builder $appointments) => $appointments->upcoming(),
            ])
            ->get()
            ->sortBy('user.name')
            ->values();
    }
}
