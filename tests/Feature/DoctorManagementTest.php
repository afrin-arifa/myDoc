<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctor_can_create_a_profile_and_associate_specialties(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $specialties = collect([
            Specialty::create(['name' => 'Family medicine', 'is_active' => true]),
            Specialty::create(['name' => 'Preventive care', 'is_active' => true]),
        ]);

        $this->actingAs($doctor)
            ->put(route('doctor.profile.update'), [
                'professional_title' => 'Family physician',
                'bio' => 'Focused on primary care.',
                'license_number' => 'LIC-100',
                'consultation_fee' => '75.00',
                'years_of_experience' => 8,
                'is_accepting_patients' => 1,
                'specialty_ids' => $specialties->pluck('id')->all(),
            ])
            ->assertRedirect();

        $profile = DoctorProfile::firstOrFail();
        $this->assertSame($doctor->id, $profile->user_id);
        $this->assertCount(2, $profile->specialties);
    }

    public function test_patients_cannot_manage_doctor_profiles_or_availability(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $this->actingAs($patient)
            ->get(route('doctor.dashboard'))
            ->assertForbidden();

        $this->actingAs($patient)
            ->post(route('doctor.availability.store'), [])
            ->assertForbidden();
    }

    public function test_doctors_cannot_update_another_doctors_availability(): void
    {
        $firstDoctor = User::factory()->create(['role' => 'doctor']);
        $secondDoctor = User::factory()->create(['role' => 'doctor']);
        $secondProfile = DoctorProfile::create([
            'user_id' => $secondDoctor->id,
            'professional_title' => 'Specialist',
            'license_number' => 'LIC-SECOND',
            'consultation_fee' => 50,
        ]);
        $availability = DoctorAvailability::create([
            'doctor_profile_id' => $secondProfile->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
        ]);

        $this->actingAs($firstDoctor)
            ->patch(route('doctor.availability.update', $availability), [
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'slot_duration_minutes' => 30,
            ])
            ->assertForbidden();
    }

    public function test_overlapping_availability_is_rejected(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-OVERLAP',
            'consultation_fee' => 50,
        ]);
        DoctorAvailability::create([
            'doctor_profile_id' => $profile->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
        ]);

        $this->actingAs($doctor)
            ->post(route('doctor.availability.store'), [
                'day_of_week' => 1,
                'start_time' => '11:00',
                'end_time' => '13:00',
                'slot_duration_minutes' => 30,
            ])
            ->assertSessionHasErrors('start_time');

        $this->assertCount(1, DoctorAvailability::all());
    }

    public function test_slot_generation_respects_boundaries_and_is_repeatable(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 11, 8, 0, 0, config('app.timezone')));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-SLOTS',
            'consultation_fee' => 50,
        ]);
        DoctorAvailability::create([
            'doctor_profile_id' => $profile->id,
            'day_of_week' => 0,
            'start_time' => '09:00',
            'end_time' => '10:30',
            'slot_duration_minutes' => 60,
            'is_active' => true,
        ]);

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), ['days' => 1])
            ->assertRedirect();

        $slots = AppointmentSlot::all();
        $this->assertCount(1, $slots);
        $this->assertSame('09:00:00', $slots->first()->starts_at->format('H:i:s'));
        $this->assertSame('10:00:00', $slots->first()->ends_at->format('H:i:s'));

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), ['days' => 1])
            ->assertRedirect();

        $this->assertCount(1, AppointmentSlot::all());
    }

    public function test_slot_generation_preserves_booked_and_blocked_slots(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 11, 8, 0, 0, config('app.timezone')));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-PRESERVE',
            'consultation_fee' => 50,
        ]);
        DoctorAvailability::create([
            'doctor_profile_id' => $profile->id,
            'day_of_week' => 0,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'slot_duration_minutes' => 60,
        ]);

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), ['days' => 1]);
        $slots = AppointmentSlot::query()->orderBy('starts_at')->get();
        $slots[0]->update(['status' => 'booked']);
        $slots[1]->update(['status' => 'blocked']);

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), ['days' => 1]);

        $this->assertSame('booked', $slots[0]->fresh()->status);
        $this->assertSame('blocked', $slots[1]->fresh()->status);
        $this->travelBack();
    }
}
