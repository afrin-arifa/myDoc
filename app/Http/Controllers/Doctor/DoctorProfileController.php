<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\UpdateDoctorAccountRequest;
use App\Http\Requests\Doctor\UpdateDoctorProfileRequest;
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

        return view('doctor.dashboard', [
            'profile' => $profile,
            'specialties' => Specialty::query()->where('is_active', true)->orderBy('name')->get(),
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
