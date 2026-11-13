<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_assistant_signs_in_to_their_dashboard(): void
    {
        $assistant = $this->assistant();
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr. Assigned']);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-1', 'consultation_fee' => 10]);
        $profile->assistants()->attach($assistant->id);

        $this->post(route('login'), ['email' => $assistant->email, 'password' => 'Assistant-pass-1'])->assertRedirect(route('assistant.dashboard'));
        $this->assertAuthenticatedAs($assistant);
        $this->get(route('assistant.dashboard'))->assertOk()->assertSee('Dr. Assigned');
    }

    public function test_a_deactivated_assistant_cannot_sign_in(): void
    {
        $assistant = $this->assistant(['deactivated_at' => now()]);

        $this->post(route('login'), ['email' => $assistant->email, 'password' => 'Assistant-pass-1'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_assistant_without_assignments_reaches_an_empty_dashboard(): void
    {
        $assistant = $this->assistant();

        $this->post(route('login'), ['email' => $assistant->email, 'password' => 'Assistant-pass-1'])->assertRedirect(route('assistant.dashboard'));
        $this->get(route('assistant.dashboard'))->assertOk()->assertSee('No doctors assigned yet');
    }

    public function test_the_navbar_shows_the_assistant_menu_and_no_other_role_links(): void
    {
        $page = $this->actingAs($this->assistant())->get(route('assistant.dashboard'))->assertOk();

        $page->assertSee(route('assistant.doctors.index'), false)->assertSee(route('assistant.dashboard'), false)
            ->assertDontSee(route('doctors.index'), false)
            ->assertDontSee(route('doctor.dashboard'), false)->assertDontSee(route('patient.dashboard'), false)
            ->assertDontSee(route('admin.dashboard'), false)->assertDontSee('How it works');
    }

    public function test_patient_doctor_and_admin_sign_in_is_unchanged(): void
    {
        foreach (['patient' => 'patient.dashboard', 'doctor' => 'doctor.dashboard', 'admin' => 'admin.dashboard'] as $role => $route) {
            $user = User::factory()->create(['role' => $role, 'password' => 'Secret-pass-1']);
            $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret-pass-1'])->assertRedirect(route($route));
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'));
            $this->flushSession();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assistant(array $attributes = []): User
    {
        $assistant = User::factory()->create(['password' => 'Assistant-pass-1', ...$attributes]);
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();

        return $assistant->fresh();
    }
}
