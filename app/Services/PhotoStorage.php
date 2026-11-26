<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Stores photos on the private disk under random names, in the subclass's DIRECTORY. Photos are only served through
 * an authorizing controller, so storage paths are never exposed to browsers. The replaced file is deleted only after
 * the change is committed.
 */
abstract class PhotoStorage
{
    public const DISK = 'local';

    /**
     * Validation rules shared by every form that accepts a photo.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'];
    }

    public function replace(Model $owner, UploadedFile $photo): void
    {
        $previous = $owner->photo_path;
        $owner->forceFill(['photo_path' => $photo->store(static::DIRECTORY, static::DISK)])->save();
        $this->deleteAfterCommit($previous);
    }

    public function remove(Model $owner): void
    {
        $previous = $owner->photo_path;
        $owner->forceFill(['photo_path' => null])->save();
        $this->deleteAfterCommit($previous);
    }

    private function deleteAfterCommit(?string $path): void
    {
        if ($path) {
            DB::afterCommit(fn () => Storage::disk(static::DISK)->delete($path));
        }
    }
}
