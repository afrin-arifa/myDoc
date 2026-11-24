<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdateAdminProfileRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdatePatientProfileRequest;
use App\Models\User;
use App\Services\UserPhotoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function patient(Request $request): View
    {
        return view('patient.profile', ['user' => $request->user()]);
    }

    public function updatePatient(UpdatePatientProfileRequest $request, UserPhotoStorage $photos): RedirectResponse
    {
        $this->saveProfile($request, $photos);

        return back()->with('status', 'Your profile has been updated.');
    }

    public function admin(Request $request): View
    {
        $assistants = User::query()->where('role', User::ROLE_ASSISTANT);

        return view('admin.profile', [
            'user' => $request->user(),
            'assistantCount' => (clone $assistants)->count(),
            'recentAssistants' => $assistants
                ->withCount('assistantAssignments')
                ->latest()
                ->latest('id')
                ->limit(4)
                ->get(['id', 'name', 'email', 'photo_path', 'created_at']),
        ]);
    }

    public function updateAdmin(UpdateAdminProfileRequest $request, UserPhotoStorage $photos): RedirectResponse
    {
        $this->saveProfile($request, $photos);

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

        return redirect()->route('login')->with('status', 'Your account has been deactivated.');
    }

    private function saveProfile(UpdatePatientProfileRequest|UpdateAdminProfileRequest $request, UserPhotoStorage $photos): void
    {
        DB::transaction(function () use ($request, $photos): void {
            $user = $request->user();
            $user->update($request->safe()->except(['photo', 'remove_photo']));

            if ($request->hasFile('photo')) {
                $photos->replace($user, $request->file('photo'));
            } elseif ($request->boolean('remove_photo')) {
                $photos->remove($user);
            }
        });
    }
}
