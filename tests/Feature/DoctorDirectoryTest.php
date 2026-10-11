<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_shows_only_accepting_doctors_and_supports_name_and_specialty_search(): void
    {
        $specialty = Specialty::create(['name' => 'Cardiology', 'is_active' => true]);
        $matchingDoctor = $this->doctor('Dr. Avery Stone', 'Cardiology Physician');
        $matchingDoctor->specialties()->attach($specialty);
        $hiddenDoctor = $this->doctor('Dr. Hidden Stone', 'Cardiology Physician', false);
        $hiddenDoctor->specialties()->attach($specialty);

        $this->get(route('doctors.index', ['search' => 'Avery']))
            ->assertOk()
            ->assertSee('Dr. Avery Stone')
            ->assertDontSee('Dr. Hidden Stone');

        $this->get(route('doctors.index', ['search' => 'Cardiology']))
            ->assertOk()
            ->assertSee('Dr. Avery Stone');
    }

    public function test_directory_supports_fee_experience_and_available_today_filters(): void
    {
        $lowFeeDoctor = $this->doctor('Dr. Low Fee', 'Physician', true, 40, 3);
        $experiencedDoctor = $this->doctor('Dr. Experienced', 'Physician', true, 90, 20);
        $availableTodayDoctor = $this->doctor('Dr. Available Today', 'Physician');
        AppointmentSlot::create([
            'doctor_profile_id' => $availableTodayDoctor->doctorProfile->id,
            'starts_at' => CarbonImmutable::today()->setTime(15, 0),
            'ends_at' => CarbonImmutable::today()->setTime(15, 30),
            'status' => 'available',
        ]);

        $this->get(route('doctors.index', ['filter' => 'fee']))
            ->assertOk()
            ->assertSeeInOrder(['Dr. Low Fee', 'Dr. Available Today']);

        $this->get(route('doctors.index', ['filter' => 'experience']))
            ->assertOk()
            ->assertSeeInOrder(['Dr. Experienced', 'Dr. Available Today']);

        $this->get(route('doctors.index', ['filter' => 'today']))
            ->assertOk()
            ->assertSee('Dr. Available Today')
            ->assertDontSee('Dr. Low Fee');
    }

    public function test_directory_preserves_filters_in_pagination_and_exposes_profile_booking_links(): void
    {
        $specialty = Specialty::create(['name' => 'Family Medicine', 'is_active' => true]);

        for ($number = 1; $number <= 13; $number++) {
            $doctor = $this->doctor('Directory Provider '.$number, 'Family Physician');
            $doctor->specialties()->attach($specialty);
        }

        $response = $this->get(route('doctors.index', [
            'search' => 'Directory Provider',
            'specialty' => $specialty->id,
            'page' => 1,
        ]));

        $response->assertOk()->assertSee('search=Directory+Provider');

        $doctor = $this->doctor('Dr. Booking Link', 'Family Physician');
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => 'available',
        ]);

        $this->get(route('doctors.show', $doctor->doctorProfile))
            ->assertOk()
            ->assertSee(route('doctors.show', $doctor->doctorProfile))
            ->assertSee(route('appointment-slots.confirm', $slot, false), false);

        $this->get(route('appointment-slots.confirm', $slot))
            ->assertRedirect(route('login'));
    }

    private function doctor(
        string $name,
        string $title,
        bool $acceptingPatients = true,
        float $fee = 65,
        int $yearsOfExperience = 8
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'role' => 'doctor',
        ]);
        DoctorProfile::create([
            'user_id' => $user->id,
            'professional_title' => $title,
            'license_number' => 'LIC-'.$user->id,
            'consultation_fee' => $fee,
            'years_of_experience' => $yearsOfExperience,
            'is_accepting_patients' => $acceptingPatients,
        ]);

        return $user->load('doctorProfile');
    }
}
