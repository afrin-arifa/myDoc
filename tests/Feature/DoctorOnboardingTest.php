<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use App\Notifications\Account\DoctorAccountActivatedNotification;
use App\Notifications\Account\DoctorInvitationNotification;
use App\Services\DoctorOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class DoctorOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_an_inactive_doctor_with_profile_and_invitation(): void
    {
        Notification::fake();
        $specialty = Specialty::create(['name' => 'Cardiology', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('admin.doctors.store'), $this->doctorPayload([
                'email' => '  New.Doctor@Example.com ',
                'specialty_ids' => [$specialty->id],
                'qualifications' => 'MBBS, FCPS',
            ]))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'invitation has been emailed'));

        $doctor = User::query()->where('email', 'new.doctor@example.com')->firstOrFail();
        $this->assertSame('doctor', $doctor->role);
        $this->assertNotNull($doctor->deactivated_at, 'The account stays inactive until the invitation is accepted.');
        $this->assertNull($doctor->email_verified_at);
        $this->assertSame('Dr. Nadia Rahman', $doctor->name);
        $profile = $doctor->doctorProfile;
        $this->assertSame('Consultant Cardiologist', $profile->professional_title);
        $this->assertSame('LIC-NEW-1', $profile->license_number);
        $this->assertSame('MBBS, FCPS', $profile->qualifications);
        $this->assertEquals([$specialty->id], $profile->specialties->pluck('id')->all());
        $this->assertSame(DoctorOnboardingService::STATUS_INVITATION_PENDING, app(DoctorOnboardingService::class)->statusFor($doctor));

        Notification::assertSentTo($doctor, DoctorInvitationNotification::class);
        $this->assertDatabaseCount('doctor_invitation_tokens', 1);
    }

    public function test_no_known_or_hardcoded_password_is_assigned_and_the_doctor_cannot_log_in_before_activation(): void
    {
        Notification::fake();
        $this->actingAs($this->admin())->post(route('admin.doctors.store'), $this->doctorPayload([
            'password' => 'Attacker123', 'password_confirmation' => 'Attacker123',
        ]));
        $second = $this->doctorPayload(['email' => 'second@example.com', 'license_number' => 'LIC-NEW-2']);
        $this->actingAs($this->admin())->post(route('admin.doctors.store'), $second);
        Auth::logout();

        $first = User::query()->where('email', 'new.doctor@example.com')->firstOrFail();
        $secondDoctor = User::query()->where('email', 'second@example.com')->firstOrFail();
        foreach (['Attacker123', 'password', 'password123', 'secret', ''] as $guess) {
            $this->assertFalse(Hash::check($guess, $first->password), "Password must not be '{$guess}'.");
        }
        $this->assertNotSame($first->password, $secondDoctor->password, 'Each account gets its own random password hash.');

        $this->post(route('login'), ['email' => $first->email, 'password' => 'Attacker123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_only_admins_can_open_or_submit_doctor_creation(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $doctor = $this->activeDoctor();

        foreach ([$patient, $doctor] as $user) {
            $this->actingAs($user)->get(route('admin.doctors.create'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.doctors.store'), $this->doctorPayload())->assertForbidden();
        }
        Auth::logout();
        $this->get(route('admin.doctors.create'))->assertRedirect(route('login'));
        $this->post(route('admin.doctors.store'), $this->doctorPayload())->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => 'new.doctor@example.com']);
        $this->assertSame(1, User::where('role', 'doctor')->count());
    }

    public function test_role_and_status_cannot_be_injected_into_doctor_creation(): void
    {
        Notification::fake();
        $this->actingAs($this->admin())->post(route('admin.doctors.store'), $this->doctorPayload([
            'role' => 'admin',
            'deactivated_at' => null,
            'email_verified_at' => now()->toDateTimeString(),
            'user_id' => 1,
        ]))->assertRedirect();

        $doctor = User::query()->where('email', 'new.doctor@example.com')->firstOrFail();
        $this->assertSame('doctor', $doctor->role);
        $this->assertNotNull($doctor->deactivated_at);
        $this->assertNull($doctor->email_verified_at);
        $this->assertSame($doctor->id, $doctor->doctorProfile->user_id);
    }

    public function test_duplicate_email_and_invalid_data_are_rejected_without_creating_anything(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $existing = User::factory()->create(['email' => 'taken@example.com']);
        DoctorProfile::create(['user_id' => $this->activeDoctor()->id, 'professional_title' => 'X', 'license_number' => 'LIC-TAKEN', 'consultation_fee' => 1]);

        $invalidPayloads = [
            [['email' => 'taken@example.com'], 'email'],
            [['email' => 'TAKEN@example.com'], 'email'],
            [['email' => 'not-an-email'], 'email'],
            [['name' => ''], 'name'],
            [['professional_title' => ''], 'professional_title'],
            [['license_number' => 'LIC-TAKEN'], 'license_number'],
            [['consultation_fee' => -5], 'consultation_fee'],
            [['years_of_experience' => 150], 'years_of_experience'],
            [['specialty_ids' => [99999]], 'specialty_ids.0'],
        ];

        foreach ($invalidPayloads as [$override, $field]) {
            $this->actingAs($admin)->post(route('admin.doctors.store'), $this->doctorPayload($override))->assertSessionHasErrors($field);
        }

        $this->assertSame(1, User::where('role', 'doctor')->count());
        $this->assertDatabaseCount('doctor_invitation_tokens', 0);
        Notification::assertNothingSent();
        $this->assertSame('taken@example.com', $existing->fresh()->email);
    }

    public function test_repeated_submission_does_not_create_a_duplicate_account(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.doctors.store'), $this->doctorPayload())->assertRedirect();
        $this->actingAs($admin)->post(route('admin.doctors.store'), $this->doctorPayload())->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'new.doctor@example.com')->count());
        $this->assertSame(1, DoctorProfile::count());
        Notification::assertCount(1);
    }

    public function test_invitation_email_failure_keeps_the_account_and_allows_a_resend(): void
    {
        $this->useFailingMailer();

        $this->actingAs($this->admin())
            ->post(route('admin.doctors.store'), $this->doctorPayload())
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'could not be sent'));

        $doctor = User::query()->where('email', 'new.doctor@example.com')->firstOrFail();
        $this->assertNotNull($doctor->doctorProfile);
        $this->assertSame(DoctorOnboardingService::STATUS_INVITATION_PENDING, app(DoctorOnboardingService::class)->statusFor($doctor));
    }

    public function test_invitation_email_contains_a_working_link_and_expiry_but_no_password(): void
    {
        $invitation = $this->createInvitedDoctor();

        $email = $this->sentEmails()->last();
        $this->assertStringContainsString('You are invited to join', $email->getSubject());
        foreach ([$email->getHtmlBody(), $email->getTextBody()] as $body) {
            $this->assertStringContainsString('72 hours', $body);
            $this->assertStringNotContainsStringIgnoringCase('password:', $body);
        }
        $this->assertStringContainsString(e($invitation['url']), $email->getHtmlBody());
        $this->assertStringContainsString($invitation['url'], $email->getTextBody());

        $stored = DB::table('doctor_invitation_tokens')->where('email', $invitation['doctor']->email)->value('token');
        $this->assertNotSame($invitation['token'], $stored, 'Only a hash of the token is stored.');
        $this->assertTrue(Hash::check($invitation['token'], $stored));
    }

    public function test_valid_invitation_activates_the_account_once_and_the_doctor_can_log_in(): void
    {
        Queue::fake();
        $invitation = $this->createInvitedDoctor();
        $doctor = $invitation['doctor'];

        $this->get($invitation['url'])->assertOk()->assertSee('Activate your account')->assertSee($doctor->email);

        $this->post(route('doctor.invitation.accept'), [
            'token' => $invitation['token'],
            'email' => $doctor->email,
            'password' => 'NewDoctor123',
            'password_confirmation' => 'NewDoctor123',
        ])->assertRedirect(route('doctor.invitation.activated'));

        $doctor->refresh();
        $this->assertNull($doctor->deactivated_at);
        $this->assertNotNull($doctor->email_verified_at);
        $this->assertTrue(Hash::check('NewDoctor123', $doctor->password));
        $this->assertDatabaseCount('doctor_invitation_tokens', 0);
        $this->assertSame(DoctorOnboardingService::STATUS_ACTIVE, app(DoctorOnboardingService::class)->statusFor($doctor));
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof DoctorAccountActivatedNotification);

        $this->post(route('login'), ['email' => $doctor->email, 'password' => 'NewDoctor123'])->assertRedirect(route('doctor.dashboard'));
        $this->assertAuthenticatedAs($doctor);
        $this->get(route('doctor.profile'))->assertOk()->assertSee('Complete your profile');
    }

    public function test_used_invalid_and_cross_account_tokens_are_rejected_with_one_generic_message(): void
    {
        $first = $this->createInvitedDoctor();
        $second = $this->createInvitedDoctor(['email' => 'second@example.com', 'license_number' => 'LIC-NEW-2']);
        $generic = 'This invitation link is invalid or has expired';

        $this->get(route('doctor.invitation.show', ['token' => 'not-a-real-token', 'email' => $first['doctor']->email]))->assertOk()->assertSee($generic);
        $this->get(route('doctor.invitation.show', ['token' => $first['token'], 'email' => $second['doctor']->email]))->assertOk()->assertSee($generic);
        $this->get(route('doctor.invitation.show', ['token' => $first['token'], 'email' => 'nobody@example.com']))->assertOk()->assertSee($generic);
        $this->get(route('doctor.invitation.show', ['token' => $first['token']]))->assertOk()->assertSee($generic);

        // First doctor's token cannot activate the second doctor's account.
        $this->post(route('doctor.invitation.accept'), $this->acceptPayload($first['token'], $second['doctor']->email))->assertOk()->assertSee($generic);
        $this->assertNotNull($second['doctor']->fresh()->deactivated_at);

        // Accept legitimately, then try to reuse the same token.
        $this->post(route('doctor.invitation.accept'), $this->acceptPayload($first['token'], $first['doctor']->email))->assertRedirect(route('doctor.invitation.activated'));
        $passwordAfterActivation = $first['doctor']->fresh()->password;
        $this->post(route('doctor.invitation.accept'), $this->acceptPayload($first['token'], $first['doctor']->email, 'Hijack12345'))->assertOk()->assertSee($generic);
        $this->get($first['url'])->assertOk()->assertSee($generic);

        $this->assertSame($passwordAfterActivation, $first['doctor']->fresh()->password);
        $this->assertNotNull($second['doctor']->fresh()->deactivated_at);
    }

    public function test_expired_invitation_is_rejected_and_shown_as_expired_to_the_admin(): void
    {
        $invitation = $this->createInvitedDoctor();

        $this->travel(72)->hours();
        $this->travel(1)->minutes();

        $this->get($invitation['url'])->assertOk()->assertSee('invalid or has expired');
        $this->post(route('doctor.invitation.accept'), $this->acceptPayload($invitation['token'], $invitation['doctor']->email))->assertSee('invalid or has expired');
        $this->assertNotNull($invitation['doctor']->fresh()->deactivated_at);

        $this->actingAs($this->admin())
            ->get(route('admin.doctors.show', $invitation['doctor']->doctorProfile))
            ->assertOk()
            ->assertSee('Invitation expired')
            ->assertSee('Resend invitation');
    }

    public function test_password_rules_are_enforced_on_acceptance(): void
    {
        $invitation = $this->createInvitedDoctor();
        $email = $invitation['doctor']->email;

        foreach ([['short1A', 'short1A'], ['alllowercase1', 'alllowercase1'], ['NoNumbersHere', 'NoNumbersHere'], ['Matching123', 'Different123']] as [$password, $confirmation]) {
            $this->post(route('doctor.invitation.accept'), [
                'token' => $invitation['token'], 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation,
            ])->assertSessionHasErrors('password');
        }

        $this->assertNotNull($invitation['doctor']->fresh()->deactivated_at);
        $this->assertDatabaseCount('doctor_invitation_tokens', 1);
    }

    public function test_resend_replaces_the_link_is_throttled_and_limited_to_unactivated_doctors(): void
    {
        $invitation = $this->createInvitedDoctor();
        $admin = $this->admin();
        $profile = $invitation['doctor']->doctorProfile;

        $this->actingAs($admin)->post(route('admin.doctors.invitation', $profile))->assertSessionHasErrors('invitation');
        $this->assertCount(1, $this->sentEmails(), 'A resend within the throttle window sends nothing.');

        $this->travel(61)->seconds();
        $this->actingAs($admin)->post(route('admin.doctors.invitation', $profile))->assertSessionHas('status');
        $this->assertCount(2, $this->sentEmails());
        $this->assertStringStartsWith('Reminder:', $this->sentEmails()->last()->getSubject());
        $this->assertDatabaseCount('doctor_invitation_tokens', 1);

        Auth::logout();
        $this->get($invitation['url'])->assertSee('invalid or has expired', false);
        $newToken = $this->tokenFromLastEmail();
        $this->post(route('doctor.invitation.accept'), $this->acceptPayload($newToken, $invitation['doctor']->email))->assertRedirect(route('doctor.invitation.activated'));

        $this->assertStringContainsString('doctor account is active', $this->sentEmails()->last()->getSubject(), 'Activation confirmation follows acceptance.');

        $this->travel(61)->seconds();
        $this->actingAs($admin)->post(route('admin.doctors.invitation', $profile))->assertSessionHasErrors('invitation');
        $this->assertCount(2, $this->sentEmails()->filter(fn ($email) => str_contains($email->getSubject(), 'activate your') || str_contains($email->getSubject(), 'invited')), 'Active doctors cannot be re-invited.');
    }

    public function test_only_admins_can_resend_invitations(): void
    {
        $invitation = $this->createInvitedDoctor();
        $profile = $invitation['doctor']->doctorProfile;
        $this->travel(61)->seconds();

        $this->actingAs(User::factory()->create(['role' => 'patient']))->post(route('admin.doctors.invitation', $profile))->assertForbidden();
        $this->actingAs($this->activeDoctor())->post(route('admin.doctors.invitation', $profile))->assertForbidden();
        Auth::logout();
        $this->post(route('admin.doctors.invitation', $profile))->assertRedirect(route('login'));

        $this->assertCount(1, $this->sentEmails());
    }

    public function test_logged_in_users_cannot_use_invitation_pages(): void
    {
        $invitation = $this->createInvitedDoctor();

        $this->actingAs($this->activeDoctor())->get($invitation['url'])->assertRedirect();
        $this->actingAs($this->activeDoctor())
            ->post(route('doctor.invitation.accept'), $this->acceptPayload($invitation['token'], $invitation['doctor']->email))
            ->assertRedirect();

        $this->assertNotNull($invitation['doctor']->fresh()->deactivated_at);
    }

    public function test_invitation_endpoints_are_rate_limited(): void
    {
        $invitation = $this->createInvitedDoctor();

        foreach (range(1, 10) as $attempt) {
            $this->post(route('doctor.invitation.accept'), $this->acceptPayload('guess-'.$attempt, $invitation['doctor']->email))->assertOk();
        }

        $this->post(route('doctor.invitation.accept'), $this->acceptPayload($invitation['token'], $invitation['doctor']->email))->assertStatus(429);
        $this->assertNotNull($invitation['doctor']->fresh()->deactivated_at);
    }

    /**
     * @return array{doctor: User, token: string, url: string}
     */
    private function createInvitedDoctor(array $override = []): array
    {
        $this->actingAs($this->admin())->post(route('admin.doctors.store'), $this->doctorPayload($override))->assertRedirect();
        Auth::logout();
        $doctor = User::query()->where('email', $override['email'] ?? 'new.doctor@example.com')->firstOrFail();
        $token = $this->tokenFromLastEmail();

        return ['doctor' => $doctor, 'token' => $token, 'url' => route('doctor.invitation.show', ['token' => $token, 'email' => $doctor->email])];
    }

    private function tokenFromLastEmail(): string
    {
        preg_match('#/doctor/invitation/([^?\s"]+)\?#', $this->sentEmails()->last()->getTextBody(), $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'Invitation email must contain a link.');

        return $matches[1];
    }

    /**
     * @return array<string, string>
     */
    private function acceptPayload(string $token, string $email, string $password = 'NewDoctor123'): array
    {
        return ['token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $password];
    }

    /**
     * @return array<string, mixed>
     */
    private function doctorPayload(array $override = []): array
    {
        return [
            'name' => 'Dr. Nadia Rahman',
            'email' => 'new.doctor@example.com',
            'phone' => '01700000000',
            'professional_title' => 'Consultant Cardiologist',
            'license_number' => 'LIC-NEW-1',
            'consultation_fee' => '1200',
            'years_of_experience' => 12,
            'bio' => 'Heart specialist.',
            'is_accepting_patients' => 1,
            ...$override,
        ];
    }

    private function sentEmails()
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage());
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function activeDoctor(): User
    {
        return User::factory()->create(['role' => 'doctor']);
    }
}
