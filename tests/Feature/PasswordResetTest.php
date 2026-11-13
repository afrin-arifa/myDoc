<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Account\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'If an active account exists for that email address';

    public function test_login_links_to_the_forgot_password_page(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'));
        $this->get(route('password.request'))->assertOk()->assertSee('Forgot your password?');
    }

    public function test_reset_link_is_sent_for_every_role_with_the_same_generic_response(): void
    {
        Notification::fake();

        foreach (['patient', 'doctor', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->post(route('password.email'), ['email' => $user->email])
                ->assertRedirect()
                ->assertSessionHas('status', fn (string $status) => str_starts_with($status, self::GENERIC));

            Notification::assertSentTo($user, ResetPasswordNotification::class);
        }
    }

    public function test_unknown_and_deactivated_accounts_get_the_identical_response_and_no_email(): void
    {
        Notification::fake();
        $deactivated = User::factory()->create(['role' => 'doctor', 'deactivated_at' => now()]);
        $active = User::factory()->create(['role' => 'patient']);

        $responses = collect(['nobody@example.com', $deactivated->email, $active->email])->map(function (string $email) {
            $response = $this->from(route('password.request'))->post(route('password.email'), ['email' => $email]);

            return [$response->status(), $response->headers->get('Location'), session('status'), session('errors')?->all()];
        });

        $this->assertCount(1, $responses->unique(), 'Responses must not reveal whether an account exists.');
        Notification::assertNotSentTo($deactivated, ResetPasswordNotification::class);
        Notification::assertSentTo($active, ResetPasswordNotification::class);
        Notification::assertCount(1);
    }

    public function test_reset_email_contains_the_link_and_expiry_and_the_token_is_stored_hashed(): void
    {
        $user = User::factory()->create(['role' => 'patient']);

        $this->post(route('password.email'), ['email' => $user->email]);

        $email = $this->lastEmail();
        $this->assertStringContainsString('Reset your', $email->getSubject());
        $token = $this->tokenFrom($email->getTextBody());
        $this->assertStringContainsString('Link valid for: 1 hour', $email->getTextBody());
        $this->assertStringNotContainsString('1 hours', $email->getTextBody());
        $this->assertStringContainsString(e(route('password.reset', ['token' => $token, 'email' => $user->email])), $email->getHtmlBody());

        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));
    }

    public function test_valid_token_resets_the_password_once_and_the_user_can_log_in(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'OldPassword1']);
        $this->post(route('password.email'), ['email' => $user->email]);
        $token = $this->tokenFrom($this->lastEmail()->getTextBody());

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Choose a new password');
        $this->post(route('password.update'), $this->resetPayload($token, $user->email, 'BrandNew123'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('BrandNew123', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);

        $this->post(route('password.update'), $this->resetPayload($token, $user->email, 'Reused12345'))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('BrandNew123', $user->fresh()->password), 'A used token cannot reset again.');

        $this->post(route('login'), ['email' => $user->email, 'password' => 'OldPassword1'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => $user->email, 'password' => 'BrandNew123'])->assertRedirect(route('patient.dashboard'));
    }

    public function test_invalid_cross_account_and_expired_tokens_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'OldPassword1']);
        $other = User::factory()->create(['role' => 'patient', 'password' => 'OtherPassword1']);
        $this->post(route('password.email'), ['email' => $user->email]);
        $token = $this->tokenFrom($this->lastEmail()->getTextBody());

        $this->post(route('password.update'), $this->resetPayload('forged-token', $user->email))->assertSessionHasErrors('email');
        $this->post(route('password.update'), $this->resetPayload($token, $other->email))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('OtherPassword1', $other->fresh()->password));

        $this->travel(61)->minutes();
        $this->post(route('password.update'), $this->resetPayload($token, $user->email))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('OldPassword1', $user->fresh()->password));
    }

    public function test_deactivated_accounts_cannot_complete_a_reset(): void
    {
        $user = User::factory()->create(['role' => 'doctor', 'password' => 'OldPassword1']);
        $this->post(route('password.email'), ['email' => $user->email]);
        $token = $this->tokenFrom($this->lastEmail()->getTextBody());
        $user->forceFill(['deactivated_at' => now()])->save();

        $this->post(route('password.update'), $this->resetPayload($token, $user->email))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('OldPassword1', $user->fresh()->password));
    }

    public function test_password_confirmation_and_strength_are_enforced(): void
    {
        $user = User::factory()->create(['role' => 'patient', 'password' => 'OldPassword1']);
        $this->post(route('password.email'), ['email' => $user->email]);
        $token = $this->tokenFrom($this->lastEmail()->getTextBody());

        foreach ([['BrandNew123', 'Different123'], ['short1A', 'short1A'], ['lowercase123', 'lowercase123'], ['NoDigitsHere', 'NoDigitsHere']] as [$password, $confirmation]) {
            $this->post(route('password.update'), [
                'token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $confirmation,
            ])->assertSessionHasErrors('password');
        }

        $this->assertTrue(Hash::check('OldPassword1', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_reset_requests_are_rate_limited_and_throttled_per_account(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'patient']);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('password.email'), ['email' => $user->email])->assertRedirect();
        }
        $this->post(route('password.email'), ['email' => $user->email])->assertStatus(429);

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('password.update'), $this->resetPayload('guess-'.$attempt, $user->email));
        }
        $this->post(route('password.update'), $this->resetPayload('guess-6', $user->email))->assertStatus(429);
    }

    public function test_raw_reset_tokens_are_never_logged(): void
    {
        Log::spy();
        $this->useFailingMailer();
        $user = User::factory()->create(['role' => 'patient']);

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status', fn (string $status) => str_starts_with($status, self::GENERIC));

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'Password reset email could not be sent.'
                && array_keys($context) === ['exception']
                && ! str_contains(json_encode($context), 'token');
        })->once();
    }

    public function test_logged_in_users_are_redirected_away_from_reset_pages(): void
    {
        $user = User::factory()->create(['role' => 'patient']);

        $this->actingAs($user)->get(route('password.request'))->assertRedirect();
        $this->actingAs($user)->post(route('password.email'), ['email' => $user->email])->assertRedirect();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    /**
     * @return array<string, string>
     */
    private function resetPayload(string $token, string $email, string $password = 'BrandNew123'): array
    {
        return ['token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $password];
    }

    private function tokenFrom(string $body): string
    {
        preg_match('#/reset-password/([^?\s"]+)\?#', $body, $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'Reset email must contain a link.');

        return $matches[1];
    }

    private function lastEmail()
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    private function useFailingMailer(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP unavailable');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
    }
}
