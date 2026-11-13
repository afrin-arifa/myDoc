<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlotDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_availability_always_stores_ten_minutes_regardless_of_input(): void
    {
        $doctor = $this->doctor();

        foreach ([['day_of_week' => 1], ['day_of_week' => 2, 'slot_duration_minutes' => 45], ['day_of_week' => 3, 'slot_duration_minutes' => 'abc']] as $input) {
            $this->actingAs($doctor)
                ->post(route('doctor.availability.store'), [...$input, 'start_time' => '09:00', 'end_time' => '10:00', 'is_active' => 1])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame([10, 10, 10], $doctor->doctorProfile->availabilities()->orderBy('day_of_week')->pluck('slot_duration_minutes')->all());
    }

    public function test_updated_availability_stores_ten_minutes(): void
    {
        $doctor = $this->doctor();
        $availability = $this->availability($doctor->doctorProfile, 60);

        $this->actingAs($doctor)
            ->patch(route('doctor.availability.update', $availability), [
                'day_of_week' => 1,
                'start_time' => '08:00',
                'end_time' => '12:00',
                'slot_duration_minutes' => 120,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(10, $availability->fresh()->slot_duration_minutes);
        $this->assertSame('08:00', substr($availability->fresh()->start_time, 0, 5));
    }

    public function test_generated_and_previewed_slots_are_exactly_ten_minutes_even_for_legacy_records(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 7, 0, 0, config('app.timezone')));
        $doctor = $this->doctor();
        $this->availability($doctor->doctorProfile, 60);
        $range = ['start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]];

        $preview = $this->actingAs($doctor)
            ->postJson(route('doctor.slots.preview'), $range)
            ->assertOk()
            ->assertJsonPath('total', 6);
        foreach ($preview->json('slots') as $slot) {
            $this->assertSame(10, (int) CarbonImmutable::parse($slot['starts_at'])->diffInMinutes(CarbonImmutable::parse($slot['ends_at'])));
        }

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), $range)->assertSessionHasNoErrors();

        $slots = AppointmentSlot::all();
        $this->assertCount(6, $slots);
        $slots->each(fn (AppointmentSlot $slot) => $this->assertSame(10, (int) $slot->starts_at->diffInMinutes($slot->ends_at)));
    }

    private function availability(DoctorProfile $profile, int $legacyDuration): DoctorAvailability
    {
        return DoctorAvailability::create([
            'doctor_profile_id' => $profile->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => $legacyDuration,
            'is_active' => true,
        ]);
    }

    private function doctor(): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);

        return $doctor->load('doctorProfile');
    }
}
