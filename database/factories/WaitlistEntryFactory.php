<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Doctor profiles have no factory in this project, so pass doctor_profile_id explicitly.
 *
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => User::factory()->state(['role' => 'patient']),
            'date_from' => today()->toDateString(),
            'date_to' => today()->addDays(7)->toDateString(),
            'time_of_day' => null,
            'consultation_type' => 'physical',
            'status' => WaitlistEntry::STATUS_WAITING,
            'expired_offer_count' => 0,
        ];
    }
}
