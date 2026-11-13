<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\StoreDoctorAvailabilityRequest;
use App\Http\Requests\Doctor\UpdateDoctorAvailabilityRequest;
use App\Models\DoctorAvailability;
use App\Services\DoctorAccess;
use App\Services\DoctorAvailabilityService;
use App\Services\DoctorWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Weekly availability in a doctor's workspace. Writes go through DoctorAvailabilityService, which authorizes the
 * doctor context and records each change as a workspace audit event in the same transaction.
 */
class DoctorAvailabilityController extends Controller
{
    public function __construct(
        private readonly DoctorAvailabilityService $availabilities,
        private readonly DoctorWorkspace $workspace,
    ) {}

    public function store(StoreDoctorAvailabilityRequest $request): RedirectResponse
    {
        $this->availabilities->create($request->user(), $request->doctorProfile(), $request->validated(), $request->boolean('is_active'));

        return back()->with('status', 'Availability added.');
    }

    public function update(UpdateDoctorAvailabilityRequest $request, DoctorAvailability $availability): RedirectResponse
    {
        $this->availabilities->update(
            $request->user(),
            $availability,
            $request->validated(),
            $request->boolean('is_active'),
            $this->workspace->forRequest($request, DoctorAccess::MANAGE_SCHEDULE),
        );

        return back()->with('status', 'Availability updated.');
    }

    public function destroy(Request $request, DoctorAvailability $availability): RedirectResponse
    {
        Gate::authorize('delete', $availability);
        $profile = $this->workspace->forRequest($request, DoctorAccess::MANAGE_SCHEDULE);
        abort_unless($profile, 403);

        $this->availabilities->delete($request->user(), $availability, $profile);

        return back()->with('status', 'Availability removed.');
    }
}
