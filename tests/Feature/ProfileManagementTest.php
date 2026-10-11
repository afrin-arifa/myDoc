<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_can_update_own_profile_and_password(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $this->actingAs($patient)->put(route('patient.profile.update'), [
            'name' => 'Updated Patient',
            'email' => 'updated@example.com',
            'phone' => '+8801000000000',
            'address' => 'Fictional address',
        ])->assertRedirect();

        $this->actingAs($patient)->put(route('profile.password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect();

        $this->assertSame('Updated Patient', $patient->fresh()->name);
        $this->assertTrue(Hash::check('new-password', $patient->fresh()->password));
    }

    public function test_patient_cannot_edit_another_user_and_wrong_password_is_rejected(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $other = User::factory()->create(['role' => 'patient']);

        $this->actingAs($patient)->put(route('patient.profile.update'), [
            'name' => 'Changed',
            'email' => $other->email,
        ])->assertSessionHasErrors('email');

        $this->actingAs($patient)->put(route('profile.password.update'), [
            'current_password' => 'wrong',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_doctor_can_update_own_account_and_professional_profile(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-OWN',
            'consultation_fee' => 50,
        ]);
        $specialty = Specialty::create(['name' => 'Demo specialty']);

        $this->actingAs($doctor)->put(route('doctor.account.update'), [
            'name' => 'Doctor Updated',
            'email' => 'doctor-updated@example.com',
            'phone' => '123',
            'address' => 'Clinic',
        ])->assertRedirect();
        $this->actingAs($doctor)->put(route('doctor.profile.update'), [
            'professional_title' => 'Senior Physician',
            'bio' => 'Fictional biography',
            'license_number' => 'LIC-OWN',
            'consultation_fee' => '95.00',
            'years_of_experience' => 12,
            'is_accepting_patients' => 1,
            'specialty_ids' => [$specialty->id],
        ])->assertRedirect();

        $this->assertSame('Senior Physician', $doctor->doctorProfile->fresh()->professional_title);
        $this->assertTrue($doctor->doctorProfile->fresh()->specialties->contains($specialty));
    }

    public function test_admin_can_manage_users_and_doctors_but_cannot_remove_the_last_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-ADMIN',
            'consultation_fee' => 50,
        ]);

        $this->actingAs($admin)->patch(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'patient',
        ])->assertSessionHasErrors('role');

        $this->actingAs($admin)->patch(route('admin.doctors.update', $profile), [
            'name' => 'Managed Doctor',
            'email' => $doctor->email,
            'professional_title' => 'Specialist',
            'license_number' => 'LIC-ADMIN',
            'consultation_fee' => 100,
            'years_of_experience' => 10,
            'is_accepting_patients' => 1,
        ])->assertRedirect();

        $this->assertSame('Managed Doctor', $doctor->fresh()->name);
        $this->assertSame('Specialist', $profile->fresh()->professional_title);
    }

    public function test_patient_can_deactivate_own_account_and_cannot_continue_authenticated_access(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $this->actingAs($patient)->delete(route('patient.account.deactivate'))
            ->assertRedirect(route('home'));

        $this->assertNotNull($patient->fresh()->deactivated_at);
        $this->actingAs($patient)->get(route('patient.dashboard'))->assertForbidden();
    }
}
