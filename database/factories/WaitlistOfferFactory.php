<?php

namespace Database\Factories;

use App\Models\WaitlistOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass waitlist_entry_id and appointment_slot_id explicitly.
 *
 * @extends Factory<WaitlistOffer>
 */
class WaitlistOfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => WaitlistOffer::STATUS_PENDING,
            'expires_at' => now()->addMinutes(45),
        ];
    }
}
