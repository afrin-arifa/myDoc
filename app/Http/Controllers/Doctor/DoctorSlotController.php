<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\GenerateDoctorSlotsRequest;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Services\DoctorAccess;
use App\Services\DoctorSlotGenerator;
use App\Services\DoctorWorkspace;
use App\Services\SlotBlockingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DoctorSlotController extends Controller
{
    public function __construct(private readonly DoctorWorkspace $workspace) {}

    public function store(GenerateDoctorSlotsRequest $request, DoctorSlotGenerator $generator): RedirectResponse
    {
        $profile = $request->doctorProfile();

        if (! $profile) {
            return back()->withErrors(['profile' => 'Create your doctor profile before generating slots.']);
        }

        [$startDate, $endDate] = $request->dateRange();
        $created = $generator->generateRange($profile, $startDate, $endDate, $request->weekdays(), $request->user());

        if ($created === 0) {
            return back()
                ->withInput()
                ->withFragment('generate-panel')
                ->with('warning', $this->nothingGeneratedMessage($profile, $generator->preview($profile, $startDate, $endDate, $request->weekdays())));
        }

        return back()->withFragment('schedule-panel')->with('status', "{$created} new appointment slot(s) generated.");
    }

    public function block(Request $request, AppointmentSlot $slot, SlotBlockingService $slots): RedirectResponse
    {
        Gate::authorize('block', $slot);
        $slots->block($request->user(), $slot, $this->contextFor($request, $slot));

        return back()->with('status', 'Slot blocked. Patients can no longer book it.');
    }

    public function unblock(Request $request, AppointmentSlot $slot, SlotBlockingService $slots): RedirectResponse
    {
        Gate::authorize('unblock', $slot);
        $slots->unblock($request->user(), $slot, $this->contextFor($request, $slot));

        return back()->with('status', 'Slot unblocked and available for booking again.');
    }

    public function preview(GenerateDoctorSlotsRequest $request, DoctorSlotGenerator $generator): JsonResponse
    {
        $profile = $request->doctorProfile();
        abort_unless($profile, 403);
        [$startDate, $endDate] = $request->dateRange();

        return response()->json($generator->preview($profile, $startDate, $endDate, $request->weekdays()));
    }

    /**
     * Why a generation created nothing, so staff know what to change.
     *
     * @param  array{total: int}  $preview
     */
    private function nothingGeneratedMessage(DoctorProfile $profile, array $preview): string
    {
        return match (true) {
            $profile->availabilities()->where('is_active', true)->doesntExist() => 'No slots were generated because no weekly availability is turned on. Add or turn on hours in the Availability tab first.',
            $preview['total'] === 0 => 'No slots were generated because the weekly availability has no hours on the chosen dates and weekdays.',
            default => 'No new slots were generated: every time in this range already has a slot, overlaps one or is in the past.',
        };
    }

    /**
     * The doctor context for a slot action: allowed to manage slots, and the slot must belong to that doctor.
     */
    private function contextFor(Request $request, AppointmentSlot $slot): DoctorProfile
    {
        $profile = $this->workspace->forRequest($request, DoctorAccess::MANAGE_SLOTS);
        abort_unless($profile, 403);
        $this->workspace->ensureBelongs($profile, $slot->doctor_profile_id);

        return $profile;
    }
}
