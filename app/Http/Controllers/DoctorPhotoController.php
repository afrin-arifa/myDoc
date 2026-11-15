<?php

namespace App\Http\Controllers;

use App\Models\DoctorProfile;
use App\Services\DoctorPhotoStorage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DoctorPhotoController extends Controller
{
    /**
     * Stream a doctor's photo from the private disk. Only signed-in users reach this route; photos of listed doctors
     * are visible to users who may browse the directory, otherwise only to the doctor, their assistants and admins. Anyone else gets a 404 so hidden profiles are not revealed.
     */
    public function show(DoctorProfile $doctor): StreamedResponse
    {
        abort_unless(
            $doctor->photo_path
                && Gate::forUser(auth()->user())->allows('viewPhoto', $doctor)
                && Storage::disk(DoctorPhotoStorage::DISK)->exists($doctor->photo_path),
            404
        );

        $disk = Storage::disk(DoctorPhotoStorage::DISK);

        return $disk->response($doctor->photo_path, 'doctor-'.$doctor->id, [
            'Content-Type' => $disk->mimeType($doctor->photo_path),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => $doctor->isPubliclyListed() ? 'private, max-age=86400' : 'private, no-store',
        ], 'inline');
    }
}
