<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Stores patient, administrator and assistant photos on the private disk under random names (doctors use DoctorPhotoStorage).
 * Photos are served only through UserPhotoController, so storage paths are never exposed to browsers.
 */
class UserPhotoStorage
{
    public const DISK = 'local';

    public const DIRECTORY = 'user-photos';

    /**
     * The same limits as doctor photos.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return DoctorPhotoStorage::rules();
    }

    public function replace(User $user, UploadedFile $photo): void
    {
        $previous = $user->photo_path;
        $path = $photo->store(self::DIRECTORY, self::DISK);

        $user->forceFill(['photo_path' => $path])->save();
        $this->deleteAfterCommit($previous);
    }

    public function remove(User $user): void
    {
        $previous = $user->photo_path;
        $user->forceFill(['photo_path' => null])->save();
        $this->deleteAfterCommit($previous);
    }

    private function deleteAfterCommit(?string $path): void
    {
        if ($path) {
            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));
        }
    }
}
