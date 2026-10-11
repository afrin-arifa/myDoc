<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseSevenTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_confirm_and_reject_only_own_booked_appointments(): void
    {
        $doctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient']);
        $appointment = $this->appointment($doctor, $patient);

        $this->actingAs($doctor)->post(route('doctor.appointments.confirm', $appointment))
            ->assertRedirect();
        $this->assertSame('confirmed', $appointment->fresh()->status);

        $this->actingAs($doctor)->post(route('doctor.appointments.reject', $appointment))
            ->assertSessionHasErrors('status');

        $second = $this->appointment($doctor, $patient);
        $this->actingAs($otherDoctor)->post(route('doctor.appointments.reject', $second))
            ->assertForbidden();
        $this->actingAs($doctor)->post(route('doctor.appointments.reject', $second))
            ->assertRedirect();

        $this->assertSame('rejected', $second->fresh()->status);
        $this->assertSame('available', $second->slot->fresh()->status);
    }

    public function test_admin_dashboard_and_management_are_protected_and_specialties_validate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);
        $specialty = Specialty::create(['name' => 'Cardiology']);

        $this->actingAs($patient)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertSee($patient->email);

        $this->actingAs($admin)->post(route('admin.specialties.store'), ['name' => 'Cardiology'])
            ->assertSessionHasErrors('name');
        $this->actingAs($admin)->patch(route('admin.specialties.update', $specialty), ['name' => 'Internal medicine'])
            ->assertRedirect();
        $this->assertDatabaseHas('specialties', ['id' => $specialty->id, 'name' => 'Internal medicine']);
    }

    public function test_referenced_specialties_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $specialty = Specialty::create(['name' => 'Neurology']);
        $doctor = $this->doctor();
        $doctor->doctorProfile->specialties()->attach($specialty);

        $this->actingAs($admin)->delete(route('admin.specialties.destroy', $specialty))
            ->assertSessionHasErrors('specialty');
        $this->assertDatabaseHas('specialties', ['id' => $specialty->id]);
    }

    public function test_admin_provisioning_command_creates_a_hashed_admin_without_printing_password(): void
    {
        $this->artisan('mydoc:admin')
            ->expectsQuestion('Administrator name', 'First Admin')
            ->expectsQuestion('Administrator email', 'admin@example.com')
            ->expectsQuestion('Administrator password', 'strong-password')
            ->expectsQuestion('Confirm password', 'strong-password')
            ->expectsOutput('Administrator account created.')
            ->doesntExpectOutput('strong-password')
            ->assertExitCode(0);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('strong-password', $admin->password));
    }

    private function doctor(): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
            'is_accepting_patients' => true,
        ]);

        return $doctor->load('doctorProfile');
    }

    private function appointment(User $doctor, User $patient): Appointment
    {
        static $slotOffset = 0;
        $startsAt = now()->addDays(2)->addMinutes($slotOffset++ * 30);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => 'booked',
        ]);

        return Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => 'booked',
            'booked_at' => now(),
        ]);
    }
}
