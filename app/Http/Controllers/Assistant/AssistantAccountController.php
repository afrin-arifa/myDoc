<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Models\DoctorProfile;
use App\Services\DoctorAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Doctor Assistant's own account page. It is read-only apart from the password form, which posts to the shared
 * profile.password.update endpoint used by every role. Name, email, role, status and assignments are managed by
 * administrators only, so nothing here can change them.
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
}
