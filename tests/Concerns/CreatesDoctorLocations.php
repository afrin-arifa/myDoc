<?php

namespace Tests\Concerns;

use App\Models\DoctorLocation;
use App\Models\DoctorProfile;

/**
 * Weekly availability must be held at one of the doctor's active locations (Phase 6).
 */
trait CreatesDoctorLocations
{
    /**
     * The doctor's first active location, created on first use.
     */
    protected function locationFor(DoctorProfile|int $profile): DoctorLocation
    {
        $profileId = $profile instanceof DoctorProfile ? $profile->id : $profile;

        return DoctorLocation::query()->where('doctor_profile_id', $profileId)->where('is_active', true)->orderBy('id')->first()
            ?? DoctorLocation::factory()->create(['doctor_profile_id' => $profileId, 'name' => 'Main chamber']);
    }
}
