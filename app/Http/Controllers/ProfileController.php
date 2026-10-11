<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdatePatientProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function patient(Request $request): View
    {
        return view('patient.profile', ['user' => $request->user()]);
    }

    public function updatePatient(UpdatePatientProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back()->with('status', 'Your profile has been updated.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->update(['password' => $request->validated('password')]);

        return back()->with('status', 'Your password has been updated.');
    }

    public function deactivate(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->deactivated_at = now();
        $user->save();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'Your account has been deactivated.');
    }
}
