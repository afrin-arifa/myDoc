<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ProvisionsAssistants;
use Tests\TestCase;

class AssistantInvitationTest extends TestCase
{
    use ProvisionsAssistants;
    use RefreshDatabase;

    private const INVALID = 'This invitation link is invalid or has expired. Please ask your administrator for a new invitation.';

    public function test_admin_invites_an_assistant_with_a_hashed_single_use_token_and_no_password(): void
    {
        ['assistant' => $assistant, 'token' => $token] = $this->invite();

        $this->assertSame(User::ROLE_ASSISTANT, $assistant->role);
        $this->assertNotNull($assistant->deactivated_at, 'Inactive until the invitation is accepted.');
        $this->assertNull($assistant->email_verified_at);
        $this->assertSame('8801712345678', $assistant->phone);
        $stored = DB::table('assistant_invitation_tokens')->where('email', $assistant->email)->value('token');
        $this->assertNotSame($token, $stored, 'Only a hash is stored.');
        $this->assertTrue(Hash::check($token, $stored));
        $this->assertSame(0, DB::table('doctor_invitation_tokens')->count(), 'Doctor invitations are separate.');

        $email = $this->sentEmails()->last();
        $this->assertSame($assistant->email, $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('Doctor Assistant', $email->getSubject());
        $this->assertStringContainsString('72 hours', $email->getTextBody());
        $this->assertDoesNotMatchRegularExpression('/^\s*(temporary\s+)?password\s*:/im', $email->getTextBody(), 'No password is ever sent.');
    }

    public function test_only_admins_can_invite_assistants(): void
    {
        [$doctor] = $this->doctor();
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => 'assistant'])->save();
        $payload = ['name' => 'Sneaky', 'email' => 'sneaky@example.com'];

        foreach ([User::factory()->create(['role' => 'patient']), $doctor, $assistant, $this->admin(['deactivated_at' => now()])] as $user) {
            $this->actingAs($user)->post(route('admin.assistants.store'), $payload)->assertForbidden();
            $this->actingAs($user)->get(route('admin.assistants.create'))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->post(route('admin.assistants.store'), $payload)->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_the_assistant_sets_a_password_once_and_can_then_sign_in(): void
    {
        ['assistant' => $assistant, 'token' => $token] = $this->invite();

        $this->get(route('assistant.invitation.show', ['token' => $token, 'email' => $assistant->email]))
            ->assertOk()->assertSee('Set your password')->assertSee($assistant->email);
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($token, $assistant->email))
            ->assertRedirect(route('assistant.invitation.activated'));
        $this->assertGuest();

        $assistant->refresh();
        $this->assertNull($assistant->deactivated_at);
        $this->assertNotNull($assistant->email_verified_at);
        $this->assertTrue(Hash::check('Assistant-pass-1', $assistant->password));
        $this->assertSame(0, DB::table('assistant_invitation_tokens')->count(), 'The token is consumed.');

        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($token, $assistant->email, 'Another-pass-2'))->assertOk()->assertSee(self::INVALID);
        $this->assertTrue(Hash::check('Assistant-pass-1', $assistant->fresh()->password), 'A used invitation cannot set the password again.');

        $this->post(route('login'), ['email' => $assistant->email, 'password' => 'Assistant-pass-1'])
            ->assertRedirect(route('assistant.dashboard'));
        $this->assertAuthenticatedAs($assistant->fresh());
    }

    public function test_expired_revoked_and_replaced_invitations_are_rejected(): void
    {
        ['assistant' => $expired, 'token' => $expiredToken] = $this->invite('expired@example.com');
        $this->travel(73)->hours();
        $this->get(route('assistant.invitation.show', ['token' => $expiredToken, 'email' => $expired->email]))->assertOk()->assertSee(self::INVALID);
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($expiredToken, $expired->email))->assertOk()->assertSee(self::INVALID);
        $this->travelBack();

        ['assistant' => $revoked, 'token' => $revokedToken, 'admin' => $admin] = $this->invite('revoked@example.com');
        $this->actingAs($admin)->patch(route('admin.assistants.status', $revoked), ['action' => 'deactivate'])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($revokedToken, $revoked->email))->assertOk()->assertSee(self::INVALID);

        ['assistant' => $replaced, 'token' => $oldToken, 'admin' => $admin] = $this->invite('replaced@example.com');
        $this->travel(2)->minutes();
        $this->actingAs($admin)->post(route('admin.assistants.invitation', $replaced))->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $newToken = $this->tokenFromLastEmail();
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($oldToken, $replaced->email))->assertOk()->assertSee(self::INVALID);
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($newToken, $replaced->email))->assertRedirect(route('assistant.invitation.activated'));

        foreach ([$expired, $revoked] as $assistant) {
            $this->assertNotNull($assistant->fresh()->deactivated_at);
        }
    }

    public function test_invitations_cannot_be_used_across_accounts_or_invitation_types(): void
    {
        ['assistant' => $first, 'token' => $firstToken] = $this->invite('first@example.com');
        ['assistant' => $second] = $this->invite('second@example.com');
        $patient = User::factory()->create(['role' => 'patient', 'deactivated_at' => now()]);

        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($firstToken, $second->email))->assertOk()->assertSee(self::INVALID);
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($firstToken, $patient->email))->assertOk()->assertSee(self::INVALID);
        $this->get(route('doctor.invitation.show', ['token' => $firstToken, 'email' => $first->email]))->assertOk()->assertSee(self::INVALID);
        $this->post(route('doctor.invitation.accept'), $this->acceptPayload($firstToken, $first->email))->assertOk()->assertSee(self::INVALID);
        $this->assertNotNull($first->fresh()->deactivated_at);
        $this->assertNotNull($second->fresh()->deactivated_at);
        $this->assertNotNull($patient->fresh()->deactivated_at);

        $this->actingAs($this->admin())->post(route('admin.doctors.store'), [
            'name' => 'Dr. Invited', 'email' => 'invited.doctor@example.com', 'professional_title' => 'GP',
            'license_number' => 'LIC-INV', 'consultation_fee' => '500',
        ])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        preg_match('#/doctor/invitation/([^?\s"]+)\?#', $this->sentEmails()->last()->getTextBody(), $matches);
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($matches[1], 'invited.doctor@example.com'))->assertOk()->assertSee(self::INVALID);
        $this->post(route('doctor.invitation.accept'), $this->acceptPayload($matches[1], 'invited.doctor@example.com'))->assertRedirect(route('doctor.invitation.activated'));
    }

    public function test_signed_in_users_cannot_use_assistant_invitation_pages(): void
    {
        ['assistant' => $assistant, 'token' => $token] = $this->invite();

        $this->actingAs(User::factory()->create(['role' => 'patient']))
            ->get(route('assistant.invitation.show', ['token' => $token, 'email' => $assistant->email]))
            ->assertRedirect();
        $this->assertNotNull($assistant->fresh()->deactivated_at);
    }

    public function test_existing_login_redirects_are_unchanged(): void
    {
        [$doctor] = $this->doctor(['password' => 'Secret-pass-1']);

        foreach (['patient' => 'patient.dashboard', 'doctor' => 'doctor.dashboard', 'admin' => 'admin.dashboard'] as $role => $route) {
            $user = $role === 'doctor' ? $doctor : User::factory()->create(['role' => $role, 'password' => 'Secret-pass-1']);
            $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret-pass-1'])->assertRedirect(route($route));
            $this->post(route('logout'));
            $this->flushSession();
        }
    }
}
