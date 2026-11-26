<?php

namespace App\Services;

/**
 * Patient, administrator and assistant photos (doctors use DoctorPhotoStorage), served by UserPhotoController.
 */
class UserPhotoStorage extends PhotoStorage
{
    public const DIRECTORY = 'user-photos';
}
