<?php

namespace Database\Factories;

use App\Models\DoctorLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Doctor profiles have no factory in this project, so pass the owner explicitly:
 * DoctorLocation::factory()->create(['doctor_profile_id' => $profile->id]).
 *
 * @extends Factory<DoctorLocation>
 */
class DoctorLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Chamber',
            'address' => fake()->streetAddress(),
            'area' => fake()->city(),
            'phone' => '02-'.fake()->numerify('#######'),
            'directions' => null,
            'is_active' => true,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
