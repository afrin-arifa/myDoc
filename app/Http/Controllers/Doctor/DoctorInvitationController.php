<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetNewPasswordRequest;
use App\Services\DoctorOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Guest-facing doctor invitation acceptance. Every invalid, expired, used or mismatched invitation gets the same
 * response, so the pages reveal nothing about which accounts exist.
 */
class DoctorInvitationController extends Controller
{
    private const INVALID_MESSAGE = 'This invitation link is invalid or has expired. Please ask your administrator for a new invitation.';

    public function __construct(private readonly DoctorOnboardingService $onboarding) {}

    public function show(Request $request, string $token): View
    {
        $email = $request->string('email')->toString();
        $doctor = $this->onboarding->invitedDoctor($email, $token);

        if (! $doctor) {
            return view('doctor.invitation.invalid', ['message' => self::INVALID_MESSAGE]);
        }

        return view('doctor.invitation.accept', [
            'token' => $token,
            'email' => $doctor->email,
            'name' => $doctor->name,
        ]);
    }

    public function store(SetNewPasswordRequest $request): RedirectResponse|View
    {
        $doctor = $this->onboarding->acceptInvitation(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        if (! $doctor) {
            return view('doctor.invitation.invalid', ['message' => self::INVALID_MESSAGE]);
        }

        return redirect()->route('doctor.invitation.activated');
    }

    public function activated(): View
    {
        return view('doctor.invitation.activated');
    }
}
