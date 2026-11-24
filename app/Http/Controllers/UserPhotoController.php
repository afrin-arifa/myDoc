<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserPhotoStorage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserPhotoController extends Controller
{
    /**
     * Stream a patient's, administrator's or assistant's photo from the private disk to the people UserPolicy::viewPhoto allows. Anyone else gets a
     * 404, the same answer as for an account without a photo. Never cached by shared caches or kept after access ends.
     */
    public function show(User $user): StreamedResponse
    {
        $disk = Storage::disk(UserPhotoStorage::DISK);

        abort_unless(
            $user->photo_path
                && Gate::allows('viewPhoto', $user)
                && $disk->exists($user->photo_path),
            404
        );

        return $disk->response($user->photo_path, 'user-'.$user->id, [
            'Content-Type' => $disk->mimeType($user->photo_path),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
