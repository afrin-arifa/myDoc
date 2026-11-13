<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetNewPasswordRequest;
use App\Services\AssistantOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lets an invited Doctor Assistant set their password. Only valid, unused, unexpired assistant invitations are
 * accepted; doctor invitations and other accounts are rejected with the same generic message. Setting the password
 * does not sign the assistant in; they sign in afterwards with their email and new password.
 */
class AssistantInvitationController extends Controller
{
    private const INVALID_MESSAGE = 'This invitation link is invalid or has expired. Please ask your administrator for a new invitation.';

    public function __construct(private readonly AssistantOnboardingService $onboarding) {}

    public function show(Request $request, string $token): View
    {
        $assistant = $this->onboarding->invitedAssistant($request->string('email')->toString(), $token);

        if (! $assistant) {
            return view('assistant.invitation.invalid', ['message' => self::INVALID_MESSAGE]);
        }

        return view('assistant.invitation.accept', [
            'token' => $token,
            'email' => $assistant->email,
            'name' => $assistant->name,
        ]);
    }

    public function store(SetNewPasswordRequest $request): RedirectResponse|View
    {
        $assistant = $this->onboarding->acceptInvitation(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        if (! $assistant) {
            return view('assistant.invitation.invalid', ['message' => self::INVALID_MESSAGE]);
        }

        return redirect()->route('assistant.invitation.activated');
    }

    public function activated(): View
    {
        return view('assistant.invitation.activated');
    }
}
