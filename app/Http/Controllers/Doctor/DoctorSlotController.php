<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\GenerateDoctorSlotsRequest;
use App\Services\DoctorSlotGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class DoctorSlotController extends Controller
{
    public function store(GenerateDoctorSlotsRequest $request, DoctorSlotGenerator $generator): RedirectResponse
    {
        $profile = $request->user()->doctorProfile;

        if (! $profile) {
            return back()->withErrors(['profile' => 'Create your doctor profile before generating slots.']);
        }

        [$startDate, $endDate] = $request->dateRange();
        $created = $generator->generateRange($profile, $startDate, $endDate, $request->weekdays());

        return back()->with('status', "{$created} new appointment slot(s) generated.");
    }

    public function preview(GenerateDoctorSlotsRequest $request, DoctorSlotGenerator $generator): JsonResponse
    {
        $profile = $request->user()->doctorProfile;
        abort_unless($profile, 403);
        [$startDate, $endDate] = $request->dateRange();

        return response()->json($generator->preview($profile, $startDate, $endDate, $request->weekdays()));
    }
}
