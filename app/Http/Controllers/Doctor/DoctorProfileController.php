<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\UpdateDoctorAccountRequest;
use App\Http\Requests\Doctor\UpdateDoctorProfileRequest;
use App\Models\Appointment;
use App\Models\Specialty;
use App\Services\DoctorPhotoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DoctorProfileController extends Controller
{
    public function show(): View
    {
        $user = request()->user();
        $profile = $user->doctorProfile?->load(['specialties', 'availabilities', 'googleCalendarConnection']);
        $appointments = $profile
            ? Appointment::query()
                ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $profile->id))
                ->with(['slot', 'patient:id,name'])
                ->orderBy('appointment_slots.starts_at')
                ->join('appointment_slots', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
                ->select('appointments.*')
                ->limit(5)
                ->get()
            : collect();
        $pendingAppointments = $profile
            ? Appointment::query()
                ->where('appointments.status', 'booked')
                ->whereHas('slot', fn ($query) => $query->where('doctor_profile_id', $profile->id))
                ->with(['slot', 'patient:id,name'])
                ->orderBy('appointment_slots.starts_at')
                ->join('appointment_slots', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
                ->select('appointments.*')
                ->limit(5)
                ->get()
            : collect();

        $today = today();
        $todaySlots = $profile
            ? $profile->appointmentSlots()
                ->whereDate('starts_at', $today)
                ->with(['appointments.patient:id,name'])
                ->orderBy('starts_at')
                ->get()
            : collect();

        return view('doctor.dashboard', [
            'profile' => $profile,
            'specialties' => Specialty::query()->where('is_active', true)->orderBy('name')->get(),
            'appointments' => $appointments,
            'pendingAppointments' => $pendingAppointments,
            'todaySlots' => $todaySlots,
            'appointmentCounts' => [
                'total' => $profile?->appointmentSlots()->whereHas('appointments')->count() ?? 0,
                'booked' => $profile?->appointmentSlots()->whereHas('appointments', fn ($query) => $query->where('status', 'booked'))->count() ?? 0,
                'confirmed' => $profile?->appointmentSlots()->whereHas('appointments', fn ($query) => $query->where('status', 'confirmed'))->count() ?? 0,
                'cancelled' => $profile?->appointmentSlots()->whereHas('appointments', fn ($query) => $query->whereIn('status', Appointment::CLOSED_STATUSES))->count() ?? 0,
            ],
            'pendingCount' => $profile?->appointmentSlots()->whereHas('appointments', fn ($query) => $query->where('status', 'booked'))->count() ?? 0,
        ]);
    }

    public function profile(Request $request): View
    {
        $profile = $request->user()->doctorProfile?->load(['user', 'specialties']);
        abort_unless($profile, 403);

        return view('doctor.profile', [
            'profile' => $profile,
            'specialties' => Specialty::query()->where('is_active', true)->orderBy('name')->get(),
            'pendingCount' => $this->pendingCount($profile->id),
        ]);
    }

    public function settings(Request $request): View
    {
        $profile = $request->user()->doctorProfile?->load('googleCalendarConnection');
        abort_unless($profile, 403);

        return view('doctor.settings', [
            'profile' => $profile,
            'pendingCount' => $this->pendingCount($profile->id),
            'calendarIssueCount' => $profile->googleCalendarConnection
                ? $profile->googleCalendarConnection->appointmentEvents()->whereIn('status', ['failed', 'delete_pending'])->count()
                : 0,
        ]);
    }

    private function pendingCount(int $profileId): int
    {
        return Appointment::query()
            ->where('status', 'booked')
            ->whereHas('slot', fn ($query) => $query->where('doctor_profile_id', $profileId))
            ->count();
    }

    public function update(UpdateDoctorProfileRequest $request, DoctorPhotoStorage $photos): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->safe()->except(['specialty_ids', 'photo', 'remove_photo']);
        $specialtyIds = $request->validated('specialty_ids') ?? [];
        $validated['is_accepting_patients'] = $request->boolean('is_accepting_patients');

        DB::transaction(function () use ($user, $validated, $specialtyIds, $request, $photos): void {
            $profile = $user->doctorProfile()->updateOrCreate(
                ['user_id' => $user->id],
                $validated
            );
            $profile->specialties()->sync($specialtyIds);

            if ($request->hasFile('photo')) {
                $photos->replace($profile, $request->file('photo'));
            } elseif ($request->boolean('remove_photo')) {
                $photos->remove($profile);
            }
        });

        return back()->with('status', 'Your doctor profile has been saved.');
    }

    public function updateAccount(UpdateDoctorAccountRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back()->with('status', 'Your account details have been updated.');
    }
}
