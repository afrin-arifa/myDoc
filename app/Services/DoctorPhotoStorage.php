<?php

namespace App\Services;

use App\Models\DoctorProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Stores doctor profile photos on the private disk under random names. Photos are served only through
 * DoctorPhotoController, so storage paths are never exposed to browsers.
 */
class DoctorPhotoStorage
{
    public const DISK = 'local';

    public const DIRECTORY = 'doctor-photos';

    /**
     * Validation rules shared by every form that accepts a doctor photo.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'];
    }

    public function replace(DoctorProfile $profile, UploadedFile $photo): void
    {
        $previous = $profile->photo_path;
        $path = $photo->store(self::DIRECTORY, self::DISK);

        $profile->forceFill(['photo_path' => $path])->save();
        $this->deleteAfterCommit($previous);
    }

    public function remove(DoctorProfile $profile): void
    {
        $previous = $profile->photo_path;
        $profile->forceFill(['photo_path' => null])->save();
        $this->deleteAfterCommit($previous);
    }

    private function deleteAfterCommit(?string $path): void
    {
        if ($path) {
            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));
        }
    }
}
