<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_patient_can_register_with_a_hashed_password(): void
    {
        $this->get(route('register'));
        $initialSessionId = $this->app['session']->getId();

        $response = $this->post(route('register'), [
            'name' => 'New Patient',
            'email' => 'patient@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = User::where('email', 'patient@example.com')->firstOrFail();

        $response->assertRedirect(route('patient.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('patient', $user->role);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertNotSame('password123', $user->password);
        $this->assertNotSame($initialSessionId, $this->app['session']->getId());
    }

    public function test_registration_cannot_assign_a_privileged_role(): void
    {
        $this->post(route('register'), [
            'name' => 'Attempted Admin',
            'email' => 'attempted-admin@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ])->assertRedirect(route('patient.dashboard'));

        $this->assertSame('patient', User::where('email', 'attempted-admin@example.com')->value('role'));
    }

    public function test_registration_validates_password_confirmation(): void
    {
        $response = $this->from(route('register'))->post(route('register'), [
            'name' => 'Patient',
            'email' => 'patient@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'patient@example.com']);
    }

    public function test_a_user_can_log_in_and_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'patient@example.com',
            'password' => 'password123',
            'role' => 'patient',
        ]);

        $this->get(route('login'));
        $initialSessionId = $this->app['session']->getId();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertRedirect(route('patient.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($initialSessionId, $this->app['session']->getId());
    }

    public function test_a_user_can_log_out(): void
    {
        $user = User::factory()->create(['role' => 'patient']);

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }
}
