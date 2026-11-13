<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sessions are bound to the user's password (Laravel's AuthenticateSession middleware, enabled with
 * $middleware->authenticateSessions()): changing the password signs out every other session and remember-me cookie.
 */
class PasswordChangeSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_signs_out_existing_sessions(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'OldPassword1']);
        $existingSession = $this->signedInSession($user);

        $this->resetPasswordAsGuest($user, 'BrandNew123');

        $this->withSession($existingSession)->actingAs($user->fresh())
            ->get(route('patient.dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_password_reset_invalidates_remember_me_cookies(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'OldPassword1']);
        $recallerName = Auth::guard()->getRecallerName();

        $login = $this->post(route('login'), ['email' => $user->email, 'password' => 'OldPassword1', 'remember' => 1]);
        $recaller = $login->getCookie($recallerName)->getValue();
        $this->assertNotEmpty($recaller);

        $this->startFreshBrowser();
        $this->withCookie($recallerName, $recaller);
        $this->get(route('patient.dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->startFreshBrowser();
        $this->resetPasswordAsGuest($user, 'BrandNew123');

        $this->startFreshBrowser();
        $this->withCookie($recallerName, $recaller);
        $this->get(route('patient.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_user_can_sign_in_normally_after_a_reset(): void
    {
        $user = User::factory()->create(['role' => 'doctor', 'password' => 'OldPassword1']);
        $this->signedInSession($user);
        $this->resetPasswordAsGuest($user, 'BrandNew123');

        $this->startFreshBrowser();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'BrandNew123'])->assertRedirect(route('doctor.dashboard'));
        $this->get(route('doctor.dashboard'))->assertOk();
        $this->get(route('doctor.profile'))->assertForbidden();
        $this->assertAuthenticatedAs($user);
    }

    public function test_changing_password_in_profile_keeps_the_current_session_but_signs_out_others(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'OldPassword1']);
        $otherDevice = $this->signedInSession($user);

        $this->startFreshBrowser();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'OldPassword1'])->assertRedirect();
        $this->put(route('profile.password.update'), [
            'current_password' => 'OldPassword1',
            'password' => 'BrandNew123',
            'password_confirmation' => 'BrandNew123',
        ])->assertSessionHasNoErrors();
        $this->get(route('patient.dashboard'))->assertOk();
        $this->get(route('patient.profile'))->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->startFreshBrowser();
        $this->withSession($otherDevice)->actingAs($user->fresh())->get(route('patient.dashboard'))->assertRedirect(route('login'));
    }

    public function test_sessions_are_unaffected_when_the_password_does_not_change(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'OldPassword1']);
        $session = $this->signedInSession($user);

        $user->forceFill(['name' => 'Renamed', 'phone' => '0123'])->save();

        $this->startFreshBrowser();
        $this->withSession($session)->actingAs($user->fresh())->get(route('patient.dashboard'))->assertOk();
    }

    /**
     * Sign in through the login form and return that browser session's data.
     *
     * @return array<string, mixed>
     */
    private function signedInSession(User $user): array
    {
        $this->post(route('login'), ['email' => $user->email, 'password' => 'OldPassword1'])->assertRedirect();
        $this->get(route($user->role.'.dashboard'))->assertOk();
        $this->assertNotNull(session('password_hash_web'), 'The session is bound to the password.');

        $session = session()->all();
        $this->startFreshBrowser();

        return $session;
    }

    private function resetPasswordAsGuest(User $user, string $newPassword): void
    {
        $this->post(route('password.email'), ['email' => $user->email])->assertRedirect();
        $body = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getTextBody();
        preg_match('#/reset-password/([^?\s"]+)\?#', $body, $matches);

        $this->post(route('password.update'), [
            'token' => $matches[1], 'email' => $user->email, 'password' => $newPassword, 'password_confirmation' => $newPassword,
        ])->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    /**
     * Simulate a different browser: no session data, no cookies and no in-memory authenticated user.
     */
    private function startFreshBrowser(): void
    {
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app['auth']->forgetGuards();
    }
}
