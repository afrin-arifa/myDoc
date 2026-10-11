<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\UpdateDoctorAccountRequest;
use App\Http\Requests\Doctor\UpdateDoctorProfileRequest;
use App\Models\Appointment;
use App\Models\Specialty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DoctorProfileController extends Controller
{
    public function show(): View
    {
        $user = request()->user();
        $profile = $user->doctorProfile?->load(['specialties', 'availabilities']);
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

        return view('doctor.dashboard', [
            'profile' => $profile,
            'specialties' => Specialty::query()->where('is_active', true)->orderBy('name')->get(),
            'appointments' => $appointments,
            'appointmentCounts' => [
                'total' => $profile?->appointmentSlots()->whereHas('appointments')->count() ?? 0,
                'booked' => $profile?->appointmentSlots()->whereHas('appointments', fn ($query) => $query->where('status', 'booked'))->count() ?? 0,
                'confirmed' => $profile?->appointmentSlots()->whereHas('appointments', fn ($query) => $query->where('status', 'confirmed'))->count() ?? 0,
            ],
        ]);
    }

    public function update(UpdateDoctorProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $specialtyIds = $validated['specialty_ids'] ?? [];
        unset($validated['specialty_ids']);
        $validated['is_accepting_patients'] = $request->boolean('is_accepting_patients');

        DB::transaction(function () use ($user, $validated, $specialtyIds): void {
            $profile = $user->doctorProfile()->updateOrCreate(
                ['user_id' => $user->id],
                $validated
            );
            $profile->specialties()->sync($specialtyIds);
        });

        return back()->with('status', 'Your doctor profile has been saved.');
    }

    public function updateAccount(UpdateDoctorAccountRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back()->with('status', 'Your account details have been updated.');
    }
}
