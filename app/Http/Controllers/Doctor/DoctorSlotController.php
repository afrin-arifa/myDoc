<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Services\DoctorSlotGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DoctorSlotController extends Controller
{
    public function store(Request $request, DoctorSlotGenerator $generator): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'between:1,90'],
        ]);

        $profile = $request->user()->doctorProfile;

        if (! $profile) {
            return back()->withErrors(['profile' => 'Create your doctor profile before generating slots.']);
        }

        $created = $generator->generate($profile, $validated['days']);

        return back()->with('status', "{$created} new appointment slot(s) generated.");
    }
}
