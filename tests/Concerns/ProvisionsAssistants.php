<?php

namespace Tests\Concerns;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Shared setup for Doctor Assistant provisioning tests: admins, doctors, assistants invited through the real admin
 * flow, and the raw invitation token read back from the sent email (it is never stored in plain text).
 */
trait ProvisionsAssistants
{
    protected function admin(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'admin', ...$attributes]);
    }

    /**
     * @return array{0: User, 1: DoctorProfile}
     */
    protected function doctor(array $attributes = []): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', ...$attributes]);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        return [$doctor, $profile];
    }

    /**
     * Invite an assistant through the admin form and return the account and the raw token from the email.
     *
     * @return array{assistant: User, token: string, admin: User}
     */
    protected function invite(string $email = 'amina.assistant@example.com', ?User $admin = null): array
    {
        $admin ??= $this->admin();
        $this->actingAs($admin)->post(route('admin.assistants.store'), ['name' => 'Amina Assistant', 'email' => $email, 'phone' => '017-1234-5678'])->assertRedirect();
        Auth::logout();
        $this->flushSession();

        return ['assistant' => User::where('email', $email)->firstOrFail(), 'token' => $this->tokenFromLastEmail(), 'admin' => $admin];
    }

    /**
     * An assistant who accepted their invitation (active, password set).
     */
    protected function activeAssistant(string $email = 'active.assistant@example.com'): User
    {
        $invited = $this->invite($email);
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($invited['token'], $email))->assertRedirect(route('assistant.invitation.activated'));

        return $invited['assistant']->fresh();
    }

    /**
     * @return array<string, string>
     */
    protected function acceptPayload(string $token, string $email, string $password = 'Assistant-pass-1'): array
    {
        return ['token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $password];
    }

    protected function tokenFromLastEmail(): string
    {
        preg_match('#/assistant/invitation/([^?\s"]+)\?#', $this->sentEmails()->last()->getTextBody(), $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'The invitation email must contain an assistant invitation link.');

        return $matches[1];
    }

    protected function sentEmails(): Collection
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage());
    }
}
