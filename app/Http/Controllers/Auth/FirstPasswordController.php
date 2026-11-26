<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetFirstPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * First sign-in of an account created by clinic staff: the patient replaces the one-time password before using
 * anything else (see EnsurePasswordIsChanged).
 */
class FirstPasswordController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $request->user()->mustChangePassword()) {
            return redirect()->route(AuthenticatedSessionController::dashboardRoute($request->user()->role) ?? 'home');
        }

        return view('auth.set-password', ['user' => $request->user()]);
    }

    public function update(SetFirstPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill([
            'password' => $request->validated('password'),
            'password_change_required' => false,
            'temporary_password_expires_at' => null,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        // Keep this session signed in with the new password hash; any other session using the old password ends.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route(AuthenticatedSessionController::dashboardRoute($user->role) ?? 'home')
            ->with('status', 'Your password is set. Welcome to myDoc!');
    }
}
