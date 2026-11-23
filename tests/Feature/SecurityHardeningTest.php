<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Account\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 9 security fixes: account emails never take their link host from the request, and self-registration is
 * rate limited.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_forged_host_header_cannot_redirect_password_reset_links(): void
    {
        config(['app.url' => 'https://mydoc.example']);
        Notification::fake();
        $user = User::factory()->create(['role' => 'patient', 'email' => 'victim@example.com']);

        $this->withServerVariables(['HTTP_HOST' => 'attacker.example'])
            ->post('http://attacker.example/forgot-password', ['email' => 'victim@example.com'])
            ->assertRedirect();

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $html = (string) $notification->toMail($user)->render();

            return str_contains($html, 'https://mydoc.example/reset-password/')
                && ! str_contains($html, 'attacker.example');
        });
    }

    public function test_doctor_and_assistant_invitation_links_use_the_application_url(): void
    {
        config(['app.url' => 'https://mydoc.example']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->withServerVariables(['HTTP_HOST' => 'attacker.example'])
            ->post('http://attacker.example/admin/assistants', ['name' => 'Amina Assistant', 'email' => 'amina@example.com', 'phone' => '01712345678'])
            ->assertRedirect();

        $emails = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $emails);
        $body = $emails->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('https://mydoc.example/assistant/invitation/', $body);
        $this->assertStringNotContainsString('attacker.example', $body);
    }

    public function test_self_registration_is_rate_limited_per_ip(): void
    {
        foreach (range(1, 5) as $number) {
            $this->post(route('register'), ['name' => "Patient {$number}", 'email' => "patient{$number}@example.com", 'password' => 'password-123', 'password_confirmation' => 'password-123'])
                ->assertRedirect();
            $this->app['auth']->forgetGuards();
            $this->flushSession();
        }

        $this->post(route('register'), ['name' => 'Patient 6', 'email' => 'patient6@example.com', 'password' => 'password-123', 'password_confirmation' => 'password-123'])
            ->assertTooManyRequests();
        $this->assertDatabaseMissing('users', ['email' => 'patient6@example.com']);
        $this->assertSame(5, User::where('role', 'patient')->count());
    }
}
