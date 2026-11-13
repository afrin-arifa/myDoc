<?php

namespace Tests\Feature;

use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentEvent;
use App\Models\User;
use App\Services\DoctorAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProvisionsAssistants;
use Tests\TestCase;

class AdminAssistantManagementTest extends TestCase
{
    use ProvisionsAssistants;
    use RefreshDatabase;

    public function test_admin_sees_assistants_with_status_assignments_and_creation_date(): void
    {
        $admin = $this->admin();
        $active = $this->activeAssistant('active@example.com');
        ['assistant' => $pending] = $this->invite('pending@example.com');
        [, $profile] = $this->doctor();
        $this->actingAs($admin)->post(route('admin.assistants.assignments.store', $active), ['doctor_profile_ids' => [$profile->id]])->assertSessionHasNoErrors();
        User::factory()->create(['role' => 'patient', 'name' => 'Not An Assistant']);

        $page = $this->actingAs($admin)->get(route('admin.assistants.index'))->assertOk();

        $page->assertSee('active@example.com')->assertSee('pending@example.com')->assertDontSee('Not An Assistant')
            ->assertSee('Active')->assertSee('Invitation pending')
            ->assertSee($active->created_at->format('M j, Y'));
        $page->assertViewHas('assistants', fn ($assistants) => $assistants->firstWhere('id', $active->id)->assistant_assignments_count === 1
            && $assistants->firstWhere('id', $pending->id)->assistant_assignments_count === 0);

        $statusOf = fn (string $status) => $this->actingAs($admin)->get(route('admin.assistants.index', ['status' => $status]))->viewData('assistants')->pluck('email')->all();
        $this->assertSame(['active@example.com'], $statusOf('active'));
        $this->assertSame(['pending@example.com'], $statusOf('invitation_pending'));
        $this->travel(73)->hours();
        $this->assertSame(['pending@example.com'], $statusOf('invitation_expired'));
        $this->assertSame(['active@example.com'], $this->actingAs($admin)->get(route('admin.assistants.index', ['search' => 'active@']))->viewData('assistants')->pluck('email')->all());
    }

    public function test_the_list_is_paginated(): void
    {
        $admin = $this->admin();
        foreach (range(1, 16) as $number) {
            $assistant = User::factory()->create(['email' => "assistant{$number}@example.com"]);
            $assistant->forceFill(['role' => 'assistant'])->save();
        }

        $this->actingAs($admin)->get(route('admin.assistants.index'))->assertOk()
            ->assertViewHas('assistants', fn ($assistants) => $assistants->count() === 15 && $assistants->total() === 16);
        $this->actingAs($admin)->get(route('admin.assistants.index', ['page' => 2]))->assertOk()
            ->assertViewHas('assistants', fn ($assistants) => $assistants->count() === 1);
    }

    public function test_details_show_assignments_history_and_invitation_but_no_secrets(): void
    {
        ['assistant' => $pending, 'token' => $token, 'admin' => $admin] = $this->invite('pending@example.com');

        $html = $this->actingAs($admin)->get(route('admin.assistants.show', $pending))->assertOk()
            ->assertSee('Invitation pending')->assertSee('Invitation expires')->assertSee('Resend invitation')
            ->assertSee('Doctors can be assigned once the assistant')->assertSee('No assignment changes yet.')
            ->getContent();
        $this->assertStringNotContainsString($token, $html);
        $this->assertStringNotContainsString(DB::table('assistant_invitation_tokens')->value('token'), $html);
        $this->assertStringNotContainsString($pending->password, $html);

        $active = $this->activeAssistant();
        [$doctor, $profile] = $this->doctor(['name' => 'Dr. Assigned']);
        $this->actingAs($admin)->post(route('admin.assistants.assignments.store', $active), ['doctor_profile_ids' => [$profile->id]]);
        $this->actingAs($admin)->get(route('admin.assistants.show', $active))->assertOk()
            ->assertSee('Dr. Assigned')->assertSee($doctor->email)->assertSee('Assigned')->assertSee($admin->name)
            ->assertSee(route('admin.assistants.assignments.destroy', [$active, $profile]), false)
            ->assertDontSee($active->password);
    }

    public function test_admin_edits_permitted_details_only(): void
    {
        $admin = $this->admin();
        $assistant = $this->activeAssistant();

        $this->actingAs($admin)->get(route('admin.assistants.edit', $assistant))->assertOk()->assertDontSee('name="role"', false);
        $this->actingAs($admin)->patch(route('admin.assistants.update', $assistant), [
            'name' => 'Renamed Assistant', 'email' => 'Renamed@Example.com', 'phone' => '+880 1812 345678',
            'role' => 'admin', 'deactivated_at' => null, 'password' => 'hijack',
        ])->assertRedirect(route('admin.assistants.show', $assistant));

        $assistant->refresh();
        $this->assertSame('Renamed Assistant', $assistant->name);
        $this->assertSame('renamed@example.com', $assistant->email);
        $this->assertSame('8801812345678', $assistant->phone);
        $this->assertSame('assistant', $assistant->role);
        $this->assertFalse(password_verify('hijack', $assistant->password));

        $taken = User::factory()->create();
        $this->actingAs($admin)->patch(route('admin.assistants.update', $assistant), ['name' => 'X', 'email' => $taken->email])->assertSessionHasErrors('email');
    }

    public function test_changing_the_email_of_a_pending_assistant_reissues_the_invitation(): void
    {
        ['assistant' => $assistant, 'token' => $oldToken, 'admin' => $admin] = $this->invite('old@example.com');

        $this->actingAs($admin)->patch(route('admin.assistants.update', $assistant), ['name' => $assistant->name, 'email' => 'new@example.com'])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'new invitation'));
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->assertSame('new@example.com', $this->sentEmails()->last()->getTo()[0]->getAddress());
        $this->assertDatabaseMissing('assistant_invitation_tokens', ['email' => 'old@example.com']);
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($oldToken, 'new@example.com'))->assertOk()->assertSee('invalid or has expired');
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($this->tokenFromLastEmail(), 'new@example.com'))->assertRedirect(route('assistant.invitation.activated'));
    }

    public function test_deactivation_removes_access_and_assignments_and_reactivation_restores_neither(): void
    {
        $admin = $this->admin();
        $assistant = $this->activeAssistant();
        [, $first] = $this->doctor();
        [, $second] = $this->doctor();
        $this->actingAs($admin)->post(route('admin.assistants.assignments.store', $assistant), ['doctor_profile_ids' => [$first->id, $second->id]]);
        $this->assertTrue(app(DoctorAccess::class)->canActFor($assistant, $first, 'view_appointments'));

        $this->actingAs($admin)->patch(route('admin.assistants.status', $assistant), ['action' => 'deactivate'])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, '2 doctor assignment(s) removed'));

        $this->assertNotNull($assistant->fresh()->deactivated_at);
        $this->assertSame(0, AssistantAssignment::count());
        $this->assertFalse(app(DoctorAccess::class)->canActFor($assistant->fresh(), $first, 'view_appointments'));
        $this->assertSame(['assigned', 'assigned', 'removed', 'removed'], AssistantAssignmentEvent::orderBy('id')->pluck('action')->all());
        $this->assertTrue(AssistantAssignmentEvent::all()->every(fn ($event) => $event->actor_id === $admin->id));

        $this->actingAs($admin)->patch(route('admin.assistants.status', $assistant), ['action' => 'activate'])->assertSessionHasNoErrors();
        $this->assertNull($assistant->fresh()->deactivated_at);
        $this->assertSame(0, AssistantAssignment::count(), 'Reactivation does not restore assignments.');
        $this->assertSame(4, AssistantAssignmentEvent::count(), 'History is kept.');
    }

    public function test_an_assistant_who_never_set_a_password_must_be_invited_again(): void
    {
        ['assistant' => $assistant, 'admin' => $admin] = $this->invite();
        $this->actingAs($admin)->patch(route('admin.assistants.status', $assistant), ['action' => 'deactivate'])->assertSessionHasNoErrors();

        $this->actingAs($admin)->patch(route('admin.assistants.status', $assistant), ['action' => 'activate'])
            ->assertSessionHasErrors(['status' => 'This assistant has not set a password yet. Send a new invitation instead.']);
        $this->assertNotNull($assistant->fresh()->deactivated_at);

        $this->travel(2)->minutes();
        $this->actingAs($admin)->post(route('admin.assistants.invitation', $assistant))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('assistant_invitation_tokens')->count());
        $active = $this->activeAssistant();
        $this->actingAs($admin)->post(route('admin.assistants.invitation', $active))
            ->assertSessionHasErrors('invitation');
    }

    public function test_only_active_admins_can_use_assistant_administration(): void
    {
        $assistant = $this->activeAssistant();
        [$doctor, $profile] = $this->doctor();
        $routes = [
            ['get', route('admin.assistants.index')],
            ['get', route('admin.assistants.create')],
            ['post', route('admin.assistants.store'), ['name' => 'X', 'email' => 'x@example.com']],
            ['get', route('admin.assistants.show', $assistant)],
            ['get', route('admin.assistants.edit', $assistant)],
            ['patch', route('admin.assistants.update', $assistant), ['name' => 'X', 'email' => 'x2@example.com']],
            ['patch', route('admin.assistants.status', $assistant), ['action' => 'deactivate']],
            ['post', route('admin.assistants.invitation', $assistant)],
            ['post', route('admin.assistants.assignments.store', $assistant), ['doctor_profile_ids' => [$profile->id]]],
            ['delete', route('admin.assistants.assignments.destroy', [$assistant, $profile])],
        ];

        foreach ([User::factory()->create(['role' => 'patient']), $doctor, $assistant, $this->admin(['deactivated_at' => now()])] as $user) {
            $this->flushSession();
            foreach ($routes as $route) {
                $this->actingAs($user)->{$route[0]}($route[1], $route[2] ?? [])->assertForbidden();
            }
        }
        $this->app['auth']->forgetGuards();
        foreach ($routes as $route) {
            $this->{$route[0]}($route[1], $route[2] ?? [])->assertRedirect(route('login'));
        }

        $this->assertNull($assistant->fresh()->deactivated_at);
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
        $this->assertSame(0, AssistantAssignment::count());
    }

    public function test_assistant_routes_only_resolve_assistant_accounts(): void
    {
        $admin = $this->admin();
        [$doctor] = $this->doctor();

        foreach ([User::factory()->create(['role' => 'patient']), $doctor, $admin] as $other) {
            $this->actingAs($admin)->get(route('admin.assistants.show', $other->id))->assertNotFound();
            $this->actingAs($admin)->patch(route('admin.assistants.status', $other->id), ['action' => 'deactivate'])->assertNotFound();
        }
        $this->assertNull($doctor->fresh()->deactivated_at);
    }

    public function test_the_ordinary_user_form_can_never_change_a_role_into_or_out_of_assistant(): void
    {
        $admin = $this->admin();
        $assistant = $this->activeAssistant();
        $patient = User::factory()->create(['role' => 'patient']);
        [$doctor] = $this->doctor();
        $payload = fn (User $user, string $role) => ['name' => $user->name, 'email' => $user->email, 'role' => $role];

        $this->actingAs($admin)->get(route('admin.users.show', $assistant))->assertOk()
            ->assertSee('This is a Doctor Assistant account')->assertSee(route('admin.assistants.show', $assistant), false)
            ->assertDontSee(route('admin.users.update', $assistant), false);
        foreach (['patient', 'admin', 'doctor'] as $role) {
            $this->actingAs($admin)->patch(route('admin.users.update', $assistant), $payload($assistant, $role))
                ->assertSessionHasErrors(['role' => 'Doctor Assistant accounts are managed from Assistants.']);
        }
        $this->actingAs($admin)->patch(route('admin.users.update', $assistant), [...$payload($assistant, 'patient'), 'deactivated' => '1']);
        $this->assertSame('assistant', $assistant->fresh()->role);
        $this->assertNull($assistant->fresh()->deactivated_at);

        $this->actingAs($admin)->patch(route('admin.users.update', $patient), $payload($patient, 'assistant'))->assertSessionHasErrors('role');
        $this->actingAs($admin)->patch(route('admin.users.update', $doctor), $payload($doctor, 'assistant'))->assertSessionHasErrors('role');
        $this->assertSame('patient', $patient->fresh()->role);
        $this->assertSame('doctor', $doctor->fresh()->role);

        $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'assistant']))->assertOk()
            ->assertSee('Doctor Assistant')->assertSee($assistant->email)->assertDontSee($patient->email);
    }

    public function test_the_admin_dashboard_links_to_assistant_management(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.assistants.index'), false)->assertSee('Doctor assistants');
    }
}
