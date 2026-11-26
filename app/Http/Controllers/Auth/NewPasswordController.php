<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetNewPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    /**
     * Laravel's broker validates the hashed, expiring token and deletes it after use. Deactivated accounts are
     * excluded, and every failure returns one generic message.
     */
    public function store(SetNewPasswordRequest $request): RedirectResponse
    {
        $status = Password::broker()->reset(
            [
                ...$request->only('email', 'password', 'password_confirmation', 'token'),
                'deactivated_at' => null,
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    // A reset through the patient's own inbox replaces any one-time password from the clinic.
                    'password_change_required' => false,
                    'temporary_password_expires_at' => null,
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'This password reset link is invalid or has expired. Please request a new one.']);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. You can now log in.');
    }
}
