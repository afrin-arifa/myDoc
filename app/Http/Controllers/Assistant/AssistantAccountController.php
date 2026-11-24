<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateAssistantPhotoRequest;
use App\Models\DoctorProfile;
use App\Services\DoctorAccess;
use App\Services\UserPhotoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The Doctor Assistant's own account page. Assistants change only their photo here, and their password through the
 * shared profile.password.update endpoint used by every role. Name, email, role, status and assignments are managed
 * by administrators only, so nothing here can change them.
 */
class AssistantAccountController extends Controller
{
    public function show(Request $request, DoctorAccess $access): View
    {
        $assistant = $request->user();

        return view('assistant.account', [
            'assistant' => $assistant,
            'doctors' => DoctorProfile::query()
                ->whereKey($access->actingProfileIds($assistant))
                ->with('user:id,name')
                ->get()
                ->sortBy('user.name')
                ->values(),
        ]);
    }

    public function updatePhoto(UpdateAssistantPhotoRequest $request, UserPhotoStorage $photos): RedirectResponse
    {
        return DB::transaction(function () use ($request, $photos): RedirectResponse {
            if ($request->hasFile('photo')) {
                $photos->replace($request->user(), $request->file('photo'));

                return back()->with('status', 'Your photo has been saved.');
            }

            if ($request->boolean('remove_photo')) {
                $photos->remove($request->user());

                return back()->with('status', 'Your photo has been removed.');
            }

            return back();
        });
    }
}
