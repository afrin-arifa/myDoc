<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 7: the Doctor Assistant's own account page and password change through the shared endpoint.
 */
class AssistantAccountTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'OldPassword1';

    private User $assistant;

    private DoctorProfile $assignedProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assistant = User::factory()->create(['name' => 'Asha Assistant', 'email' => 'asha@example.com', 'password' => self::PASSWORD]);
        $this->assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->assignedProfile = $this->doctor('Dr. Alpha Assigned');
        $this->assignedProfile->assistants()->attach($this->assistant->id);
        $this->doctor('Dr. Gamma Unassigned');
        $deactivatedDoctor = $this->doctor('Dr. Delta Deactivated');
        $deactivatedDoctor->assistants()->attach($this->assistant->id);
        $deactivatedDoctor->user->forceFill(['deactivated_at' => now()])->save();
    }

    public function test_assistant_sees_their_own_details_and_only_doctors_they_currently_work_for(): void
    {
        $response = $this->actingAs($this->assistant)->get(route('assistant.account'))->assertOk();

        $response->assertSee('My account')->assertSee('Asha Assistant')->assertSee('asha@example.com')->assertSee('Doctor Assistant')
            ->assertSee('Dr. Alpha Assigned')->assertSee(route('assistant.workspace.appointments.index', $this->assignedProfile), false)
            ->assertDontSee('Dr. Gamma Unassigned')->assertDontSee('Dr. Delta Deactivated')
            ->assertSee('action="'.route('profile.password.update').'"', false)
            ->assertDontSee('name="email"', false)->assertDontSee('name="name"', false)->assertDontSee('name="role"', false);
    }

    public function test_assistant_changes_their_password_and_signs_in_with_it(): void
    {
        $this->actingAs($this->assistant)->from(route('assistant.account'))->put(route('profile.password.update'), [
            'current_password' => self::PASSWORD,
            'password' => 'NewPassword2',
            'password_confirmation' => 'NewPassword2',
        ])->assertRedirect(route('assistant.account'))->assertSessionHas('status', 'Your password has been updated.');

        $this->assertTrue(Hash::check('NewPassword2', $this->assistant->fresh()->password));

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => 'asha@example.com', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login'), ['email' => 'asha@example.com', 'password' => 'NewPassword2'])->assertRedirect(route('assistant.dashboard'));
        $this->assertAuthenticatedAs($this->assistant);
    }

    public function test_invalid_password_changes_are_rejected_with_inline_errors(): void
    {
        $attempts = [
            'current_password' => ['current_password' => 'WrongPassword1', 'password' => 'NewPassword2', 'password_confirmation' => 'NewPassword2'],
            'password' => ['current_password' => self::PASSWORD, 'password' => 'NewPassword2', 'password_confirmation' => 'Different3'],
        ];
        $weak = ['current_password' => self::PASSWORD, 'password' => 'alllowercase', 'password_confirmation' => 'alllowercase'];

        foreach ([...array_values($attempts), $weak] as $index => $input) {
            $field = array_keys($attempts)[$index] ?? 'password';
            $this->actingAs($this->assistant)->from(route('assistant.account'))->put(route('profile.password.update'), $input)
                ->assertRedirect(route('assistant.account'))->assertSessionHasErrors($field);
        }
        $this->assertTrue(Hash::check(self::PASSWORD, $this->assistant->fresh()->password));

        $this->actingAs($this->assistant)->from(route('assistant.account'))->put(route('profile.password.update'), $attempts['current_password']);
        $this->actingAs($this->assistant)->get(route('assistant.account'))
            ->assertSee('The current password is incorrect.')->assertSee('is-invalid', false);
    }

    public function test_assistant_cannot_change_role_status_email_or_assignments_through_the_password_endpoint(): void
    {
        $this->actingAs($this->assistant)->put(route('profile.password.update'), [
            'current_password' => self::PASSWORD,
            'password' => 'NewPassword2',
            'password_confirmation' => 'NewPassword2',
            'role' => 'admin',
            'email' => 'takeover@example.com',
            'name' => 'Someone Else',
            'deactivated_at' => null,
            'doctor_profile_ids' => [DoctorProfile::query()->whereKeyNot($this->assignedProfile->id)->value('id')],
        ])->assertSessionHasNoErrors();

        $assistant = $this->assistant->fresh();
        $this->assertSame(User::ROLE_ASSISTANT, $assistant->role);
        $this->assertSame('asha@example.com', $assistant->email);
        $this->assertSame('Asha Assistant', $assistant->name);
        $this->assertSame([$this->assignedProfile->id, DoctorProfile::whereHas('user', fn ($users) => $users->where('name', 'Dr. Delta Deactivated'))->value('id')],
            $assistant->assignedDoctors()->orderBy('doctor_profiles.id')->pluck('doctor_profiles.id')->all());
    }

    public function test_a_deactivated_session_can_neither_open_the_account_nor_change_the_password(): void
    {
        $patient = User::factory()->create(['role' => 'patient', 'password' => self::PASSWORD]);

        foreach ([$this->assistant, $patient] as $user) {
            $user->forceFill(['deactivated_at' => now()])->save();
            $this->flushSession();
            $this->actingAs($user)->put(route('profile.password.update'), [
                'current_password' => self::PASSWORD,
                'password' => 'NewPassword2',
                'password_confirmation' => 'NewPassword2',
            ])->assertForbidden();
            $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        }

        $this->flushSession();
        $this->actingAs($this->assistant)->get(route('assistant.account'))->assertForbidden();
    }

    public function test_only_assistants_can_open_the_assistant_account_page(): void
    {
        foreach (['patient', 'doctor', 'admin'] as $role) {
            $this->flushSession();
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('assistant.account'))->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->get(route('assistant.account'))->assertRedirect(route('login'));
    }

    public function test_staff_keep_the_invitation_password_strength_while_patients_keep_the_registration_rule(): void
    {
        $patient = User::factory()->create(['role' => 'patient', 'password' => self::PASSWORD]);
        $doctor = $this->assignedProfile->user;
        $doctor->forceFill(['password' => self::PASSWORD])->save();
        $weak = ['current_password' => self::PASSWORD, 'password' => 'alllowercase', 'password_confirmation' => 'alllowercase'];

        $this->actingAs($doctor)->put(route('profile.password.update'), $weak)->assertSessionHasErrors('password');
        $this->flushSession();
        $this->actingAs($patient)->put(route('profile.password.update'), $weak)->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('alllowercase', $patient->fresh()->password));
    }

    private function doctor(string $name): DoctorProfile
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name]);

        return DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);
    }
}
