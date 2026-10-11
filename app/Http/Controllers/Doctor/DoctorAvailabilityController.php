<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\StoreDoctorAvailabilityRequest;
use App\Http\Requests\Doctor\UpdateDoctorAvailabilityRequest;
use App\Models\DoctorAvailability;
use Illuminate\Http\RedirectResponse;

class DoctorAvailabilityController extends Controller
{
    public function store(StoreDoctorAvailabilityRequest $request): RedirectResponse
    {
        $profile = $request->user()->doctorProfile;

        $profile->availabilities()->create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Availability added.');
    }

    public function update(UpdateDoctorAvailabilityRequest $request, DoctorAvailability $availability): RedirectResponse
    {
        $availability->update([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Availability updated.');
    }

    public function destroy(DoctorAvailability $availability): RedirectResponse
    {
        abort_unless($availability->doctor_profile_id === request()->user()->doctorProfile?->id, 403);

        $availability->delete();

        return back()->with('status', 'Availability removed.');
    }
}
