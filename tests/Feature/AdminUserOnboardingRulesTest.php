<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\User;
use App\Notifications\Account\DoctorInvitationNotification;
use App\Services\DoctorOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The general Admin → Users page must follow the doctor onboarding rules.
 */
class AdminUserOnboardingRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_page_cannot_activate_a_doctor_who_has_not_accepted_the_invitation(): void
    {
        [$admin, $pending] = $this->pendingDoctor();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $pending), $this->userPayload($pending))
            ->assertSessionHasErrors('deactivated');

        $this->assertNotNull($pending->fresh()->deactivated_at);
        $this->assertSame(DoctorOnboardingService::STATUS_INVITATION_PENDING, $this->onboardingStatus($pending));

        DB::table('doctor_invitation_tokens')->update(['created_at' => now()->subDays(4)]);
        $this->actingAs($admin)->patch(route('admin.users.update', $pending), $this->userPayload($pending))->assertSessionHasErrors('deactivated');
        $this->assertNotNull($pending->fresh()->deactivated_at, 'An expired invitation cannot be bypassed either.');
    }

    public function test_resaving_a_pending_doctor_keeps_the_invitation(): void
    {
        [$admin, $pending] = $this->pendingDoctor();
        $token = DB::table('doctor_invitation_tokens')->value('token');

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $pending), $this->userPayload($pending, ['name' => 'Dr. Renamed', 'deactivated' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Dr. Renamed', $pending->fresh()->name);
        $this->assertSame($token, DB::table('doctor_invitation_tokens')->value('token'));
        $this->assertSame(DoctorOnboardingService::STATUS_INVITATION_PENDING, $this->onboardingStatus($pending));
    }

    public function test_changing_a_pending_doctors_email_reissues_the_invitation_to_the_new_address(): void
    {
        foreach (['admin.users.update', 'admin.doctors.update'] as $route) {
            [$admin, $pending] = $this->pendingDoctor();
            $oldEmail = $pending->email;
            $oldToken = DB::table('doctor_invitation_tokens')->where('email', $oldEmail)->value('token');
            $newEmail = 'corrected-'.$pending->id.'@example.com';

            $target = $route === 'admin.users.update' ? $pending : $pending->doctorProfile;
            $payload = $route === 'admin.users.update'
                ? $this->userPayload($pending, ['email' => $newEmail, 'deactivated' => 1])
                : $this->doctorPayload($pending, ['email' => $newEmail]);

            $this->actingAs($admin)->patch(route($route, $target), $payload)
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', fn (string $status) => str_contains($status, 'sent to the new email address'));

            $this->assertDatabaseMissing('doctor_invitation_tokens', ['email' => $oldEmail]);
            $newToken = DB::table('doctor_invitation_tokens')->where('email', $newEmail)->value('token');
            $this->assertNotNull($newToken, "{$route}: a fresh invitation exists for the new address.");
            $this->assertNotSame($oldToken, $newToken);
            $this->assertSame(DoctorOnboardingService::STATUS_INVITATION_PENDING, $this->onboardingStatus($pending));
            Notification::assertSentTo($pending->fresh(), DoctorInvitationNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->email === $newEmail);
        }
    }

    public function test_old_invitation_link_stops_working_after_an_email_change(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.doctors.store'), [
            'name' => 'Dr. Typo', 'email' => 'typo@example.com', 'professional_title' => 'GP', 'license_number' => 'LIC-TYPO', 'consultation_fee' => 10,
        ]);
        $doctor = User::query()->where('email', 'typo@example.com')->firstOrFail();
        $oldToken = null;
        Notification::assertSentTo($doctor, DoctorInvitationNotification::class, function ($notification, $channels, $notifiable) use (&$oldToken) {
            preg_match('#/doctor/invitation/([^?]+)\?#', $notification->toMail($notifiable)->viewData['actionUrl'], $matches);
            $oldToken = $matches[1];

            return true;
        });

        $this->actingAs($admin)->patch(route('admin.users.update', $doctor), $this->userPayload($doctor, ['email' => 'fixed@example.com', 'deactivated' => 1]));
        Auth::logout();

        foreach (['typo@example.com', 'fixed@example.com'] as $email) {
            $this->post(route('doctor.invitation.accept'), [
                'token' => $oldToken, 'email' => $email, 'password' => 'NewDoctor123', 'password_confirmation' => 'NewDoctor123',
            ])->assertSee('invalid or has expired');
        }
        $this->assertNotNull($doctor->fresh()->deactivated_at);
    }

    public function test_users_page_cannot_assign_or_remove_the_doctor_role(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);
        $doctor = $this->activeDoctor();

        $this->actingAs($admin)->patch(route('admin.users.update', $patient), $this->userPayload($patient, ['role' => 'doctor']))->assertSessionHasErrors('role');
        $this->assertSame('patient', $patient->fresh()->role);

        foreach (['patient', 'admin'] as $role) {
            $this->actingAs($admin)->patch(route('admin.users.update', $doctor), $this->userPayload($doctor, ['role' => $role]))->assertSessionHasErrors('role');
            $this->assertSame('doctor', $doctor->fresh()->role);
        }

        $this->actingAs($admin)->get(route('admin.users.show', $patient))->assertOk()->assertDontSee('<option value="doctor"', false);
        $this->actingAs($admin)->get(route('admin.users.show', $doctor))->assertOk()->assertSee('This is a doctor account');
    }

    public function test_users_page_deactivation_and_reactivation_follow_the_onboarding_service(): void
    {
        [$admin, $pending] = $this->pendingDoctor();
        $active = $this->activeDoctor();

        $this->actingAs($admin)->patch(route('admin.users.update', $active), $this->userPayload($active, ['deactivated' => 1]))->assertSessionHasNoErrors();
        $this->assertSame(DoctorOnboardingService::STATUS_DEACTIVATED, $this->onboardingStatus($active));

        $this->actingAs($admin)->patch(route('admin.users.update', $active), $this->userPayload($active))->assertSessionHasNoErrors();
        $this->assertSame(DoctorOnboardingService::STATUS_ACTIVE, $this->onboardingStatus($active));

        // Cancelling a pending doctor's access from the Users page revokes the invitation; it still cannot be activated.
        $this->actingAs($admin)->patch(route('admin.doctors.status', $pending->doctorProfile), ['action' => 'deactivate']);
        $this->assertSame(DoctorOnboardingService::STATUS_DEACTIVATED, $this->onboardingStatus($pending));
        $this->assertDatabaseCount('doctor_invitation_tokens', 0);
    }

    public function test_patient_and_admin_management_is_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);

        $this->actingAs($admin)->patch(route('admin.users.update', $patient), $this->userPayload($patient, ['name' => 'Updated Patient', 'deactivated' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('Updated Patient', $patient->fresh()->name);
        $this->assertNotNull($patient->fresh()->deactivated_at);

        $this->actingAs($admin)->patch(route('admin.users.update', $patient), $this->userPayload($patient, ['role' => 'admin']))->assertSessionHasNoErrors();
        $this->assertSame('admin', $patient->fresh()->role);
        $this->assertNull($patient->fresh()->deactivated_at);

        $this->actingAs($admin)->patch(route('admin.users.update', $admin), $this->userPayload($admin, ['role' => 'patient']))->assertSessionHasErrors('role');
        $this->actingAs($admin)->patch(route('admin.users.update', $otherAdmin), $this->userPayload($otherAdmin, ['role' => 'patient']))->assertSessionHasNoErrors();
    }

    public function test_only_admins_can_use_the_users_page(): void
    {
        [, $pending] = $this->pendingDoctor();
        $doctor = $this->activeDoctor();
        $patient = User::factory()->create(['role' => 'patient']);

        foreach ([$doctor, $patient] as $user) {
            $this->actingAs($user)->patch(route('admin.users.update', $pending), $this->userPayload($pending))->assertForbidden();
            $this->actingAs($user)->patch(route('admin.users.update', $user), $this->userPayload($user, ['role' => 'admin']))->assertForbidden();
        }

        $this->assertNotNull($pending->fresh()->deactivated_at);
        $this->assertSame('doctor', $doctor->fresh()->role);
        $this->assertSame('patient', $patient->fresh()->role);
    }

    /**
     * @return array{User, User}
     */
    private function pendingDoctor(): array
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $email = 'pending-'.uniqid().'@example.com';
        $this->actingAs($admin)->post(route('admin.doctors.store'), [
            'name' => 'Dr. Pending', 'email' => $email, 'professional_title' => 'GP',
            'license_number' => 'LIC-'.uniqid(), 'consultation_fee' => 10,
        ])->assertRedirect();

        return [$admin, User::query()->where('email', $email)->firstOrFail()];
    }

    private function activeDoctor(): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        return $doctor->load('doctorProfile');
    }

    private function onboardingStatus(User $doctor): string
    {
        return app(DoctorOnboardingService::class)->statusFor($doctor->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user, array $override = []): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $user->role, ...$override];
    }

    /**
     * @return array<string, mixed>
     */
    private function doctorPayload(User $doctor, array $override = []): array
    {
        $profile = $doctor->doctorProfile;

        return [
            'name' => $doctor->name, 'email' => $doctor->email, 'professional_title' => $profile->professional_title,
            'license_number' => $profile->license_number, 'consultation_fee' => $profile->consultation_fee, ...$override,
        ];
    }
}
