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
use Illuminate\Support\Str;

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
        $this->availabilities->create($request->user(), $request->doctorProfile(), $request->validated(), $request->boolean('is_active', true));

        return back()->withFragment('availability-panel')->with('status', 'Availability added. Generate slots so patients can book these hours.');
    }

    public function update(UpdateDoctorAvailabilityRequest $request, DoctorAvailability $availability): RedirectResponse
    {
        $closed = $this->availabilities->update(
            $request->user(),
            $availability,
            $request->validated(),
            $request->boolean('is_active'),
            $this->workspace->forRequest($request, DoctorAccess::MANAGE_SCHEDULE),
        );

        return back()->withFragment('availability-panel')->with('status', 'Availability updated.'.$this->closedNotice($closed));
    }

    public function destroy(Request $request, DoctorAvailability $availability): RedirectResponse
    {
        Gate::authorize('delete', $availability);
        $profile = $this->workspace->forRequest($request, DoctorAccess::MANAGE_SCHEDULE);
        abort_unless($profile, 403);

        $closed = $this->availabilities->delete($request->user(), $availability, $profile);

        return back()->withFragment('availability-panel')->with('status', 'Availability removed.'.$this->closedNotice($closed));
    }

    /**
     * Explains which open times were closed because the weekly schedule no longer covers them.
     */
    private function closedNotice(int $closed): string
    {
        if ($closed === 0) {
            return '';
        }

        return ' '.$closed.' open future '.Str::plural('slot', $closed).' outside your weekly schedule '
            .($closed === 1 ? 'was' : 'were').' removed. Existing appointments are unchanged.';
    }
}
