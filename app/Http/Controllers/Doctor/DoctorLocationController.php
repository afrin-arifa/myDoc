<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\SaveDoctorLocationRequest;
use App\Models\DoctorLocation;
use App\Services\DoctorAccess;
use App\Services\DoctorLocationService;
use App\Services\DoctorWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Practice locations in a doctor's workspace, managed from the schedule page's Locations tab by the doctor or an
 * assigned Doctor Assistant. Writes go through DoctorLocationService, which authorizes the doctor context, checks the
 * location belongs to it and records an audit event.
 */
class DoctorLocationController extends Controller
{
    public function __construct(
        private readonly DoctorLocationService $locations,
        private readonly DoctorWorkspace $workspace,
    ) {}

    public function store(SaveDoctorLocationRequest $request): RedirectResponse
    {
        $this->locations->create($request->user(), $request->doctorProfile(), $request->validated());

        return $this->backToLocations('Location added. You can now use it in your weekly availability.');
    }

    public function update(SaveDoctorLocationRequest $request, DoctorLocation $location): RedirectResponse
    {
        $this->locations->update($request->user(), $location, $request->validated(), $request->doctorProfile());

        return $this->backToLocations('Location updated.');
    }

    public function updateStatus(Request $request, DoctorLocation $location): RedirectResponse
    {
        $profile = $this->workspace->forRequest($request, DoctorAccess::MANAGE_SCHEDULE);
        abort_unless($profile, 403);
        $isActive = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        $this->locations->setActive($request->user(), $location, (bool) $isActive, $profile);

        return $this->backToLocations($isActive ? 'Location restored.' : 'Location archived. It is hidden from new schedules; existing appointments keep showing it.');
    }

    public function destroy(Request $request, DoctorLocation $location): RedirectResponse
    {
        $profile = $this->workspace->forRequest($request, DoctorAccess::MANAGE_SCHEDULE);
        abort_unless($profile, 403);

        $this->locations->delete($request->user(), $location, $profile);

        return $this->backToLocations('Location deleted.');
    }

    private function backToLocations(string $status): RedirectResponse
    {
        return back()->withFragment('locations-panel')->with('status', $status);
    }
}
