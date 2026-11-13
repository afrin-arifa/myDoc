<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_doctor_and_admin_can_log_in_normally(): void
    {
        foreach (['patient' => 'patient.dashboard', 'doctor' => 'doctor.dashboard', 'admin' => 'admin.dashboard'] as $role => $dashboard) {
            $user = User::factory()->create(['role' => $role, 'password' => 'password123']);

            $this->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
                ->assertRedirect(route($dashboard));
            $this->assertAuthenticatedAs($user);

            $this->post(route('logout'));
        }
    }

    public function test_repeated_failed_attempts_are_throttled_even_with_the_correct_password(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'password123']);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        }

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringStartsWith('Too many login attempts.', session('errors')->first('email'));
    }

    public function test_throttle_message_is_the_same_for_unknown_accounts(): void
    {
        foreach (range(1, 6) as $attempt) {
            $response = $this->post(route('login'), ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
        }

        $response->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Too many login attempts.', session('errors')->first('email'));
    }

    public function test_account_throttling_is_scoped_to_the_ip_address(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'password123']);

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect(route('patient.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_one_ip_address_is_throttled_across_many_email_addresses(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
                ->post(route('login'), ['email' => "guess{$attempt}@example.com", 'password' => 'wrong-password']);
        }

        $user = User::factory()->create(['role' => 'patient', 'password' => 'password123']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
            ->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_successful_login_clears_the_account_limiter(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'password123']);
        $accountKey = 'login:'.strtolower($user->email).'|127.0.0.1';

        foreach (range(1, 4) as $attempt) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);
        }
        $this->assertSame(4, RateLimiter::attempts($accountKey));

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect(route('patient.dashboard'));

        $this->assertSame(0, RateLimiter::attempts($accountKey));
    }

    public function test_login_throttling_does_not_affect_authenticated_routes(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'password123']);

        foreach (range(1, 20) as $attempt) {
            RateLimiter::hit('login-ip:127.0.0.1');
            RateLimiter::hit('login:'.strtolower($user->email).'|127.0.0.1');
        }
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->actingAs($user)->get(route('patient.dashboard'))->assertOk();
        $this->actingAs($user)->get(route('patient.appointments.index'))->assertOk();
    }
}
