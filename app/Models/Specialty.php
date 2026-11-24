<?php

namespace App\Models;

use App\Models\Concerns\HasUrlKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description', 'is_active'])]
class Specialty extends Model
{
    use HasUrlKey;

    public function doctorProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            DoctorProfile::class,
            'doctor_specialty',
            'specialty_id',
            'doctor_profile_id'
        );
    }
}
