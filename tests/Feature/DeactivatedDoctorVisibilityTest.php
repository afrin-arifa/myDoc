<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivatedDoctorVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_doctors_appear_and_deactivated_doctors_are_hidden_from_public_listings(): void
    {
        $active = $this->doctor('Dr. Active Visible');
        $deactivated = $this->doctor('Dr. Gone Hidden', deactivated: true);

        foreach (['all', 'fee', 'experience'] as $filter) {
            $this->get(route('doctors.index', ['filter' => $filter]))
                ->assertOk()
                ->assertSee('Dr. Active Visible')
                ->assertDontSee('Dr. Gone Hidden');
        }

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Dr. Active Visible')
            ->assertDontSee('Dr. Gone Hidden');

        $this->get(route('doctors.show', $active->doctorProfile))->assertOk();
        $this->get(route('doctors.show', $deactivated->doctorProfile))->assertNotFound();
    }

    public function test_patients_cannot_book_a_deactivated_doctor(): void
    {
        $doctor = $this->doctor('Dr. Gone Hidden', deactivated: true);
        $patient = User::factory()->create(['role' => 'patient']);
        $slot = $this->slot($doctor->doctorProfile, 'available');

        $this->actingAs($patient)->get(route('appointment-slots.confirm', $slot))->assertNotFound();
        $this->actingAs($patient)
            ->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])
            ->assertSessionHasErrors('slot');

        $this->assertSame('available', $slot->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_historical_appointments_and_admin_records_remain_accessible(): void
    {
        $doctor = $this->doctor('Dr. Gone Hidden');
        $patient = User::factory()->create(['role' => 'patient']);
        $admin = User::factory()->create(['role' => 'admin']);
        $slot = $this->slot($doctor->doctorProfile, 'booked');
        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => 'confirmed',
            'consultation_type' => 'physical',
        ]);
        $doctor->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($patient)
            ->get(route('patient.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Dr. Gone Hidden');
        $this->actingAs($admin)->get(route('admin.doctors.show', $doctor->doctorProfile))->assertOk();
        $this->actingAs($admin)->get(route('admin.appointments.show', $appointment))->assertOk();

        $this->assertDatabaseHas('doctor_profiles', ['id' => $doctor->doctorProfile->id]);
        $this->assertSame('confirmed', $appointment->fresh()->status);
    }

    private function slot(DoctorProfile $profile, string $status): AppointmentSlot
    {
        return AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => now()->addDay()->startOfMinute(),
            'ends_at' => now()->addDay()->startOfMinute()->addMinutes(10),
            'status' => $status,
        ]);
    }

    private function doctor(string $name, bool $deactivated = false): User
    {
        $doctor = User::factory()->create([
            'role' => 'doctor',
            'name' => $name,
            'deactivated_at' => $deactivated ? now() : null,
        ]);
        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
            'years_of_experience' => 10,
        ]);

        return $doctor->load('doctorProfile');
    }
}
