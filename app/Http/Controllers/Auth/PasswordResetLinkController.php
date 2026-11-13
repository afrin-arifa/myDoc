<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public const GENERIC_STATUS = 'If an active account exists for that email address, we have sent a password reset link. Please check your inbox.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Always returns the same response so the form cannot be used to discover accounts. Deactivated accounts
     * (including doctors still awaiting their invitation) are treated as unknown and receive no email.
     */
    public function store(Request $request): RedirectResponse
    {
        $email = $request->validate(['email' => ['required', 'string', 'email', 'max:255']])['email'];

        try {
            Password::broker()->sendResetLink(['email' => $email, 'deactivated_at' => null]);
        } catch (Throwable $exception) {
            Log::warning('Password reset email could not be sent.', ['exception' => $exception::class]);
        }

        return back()->with('status', self::GENERIC_STATUS);
    }
}
