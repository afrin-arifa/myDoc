<?php

namespace Tests\Feature;

use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentEvent;
use App\Models\User;
use App\Services\AssistantAssignmentService;
use App\Services\DoctorAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\ProvisionsAssistants;
use Tests\TestCase;

class AssistantAssignmentManagementTest extends TestCase
{
    use ProvisionsAssistants;
    use RefreshDatabase;

    private User $admin;

    private User $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
        $this->assistant = $this->activeAssistant();
    }

    public function test_admin_assigns_several_doctors_and_each_is_recorded_once_with_the_admin_as_actor(): void
    {
        [, $first] = $this->doctor();
        [, $second] = $this->doctor();

        $this->actingAs($this->admin)->post(route('admin.assistants.assignments.store', $this->assistant), ['doctor_profile_ids' => [$first->id, $second->id]])
            ->assertSessionHasNoErrors()->assertSessionHas('status', '2 doctor(s) assigned.');

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->assistant->assignedDoctors()->pluck('doctor_profiles.id')->all());
        $this->assertTrue(AssistantAssignment::all()->every(fn ($assignment) => $assignment->assigned_by === $this->admin->id));
        $events = AssistantAssignmentEvent::orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn ($event) => $event->action === 'assigned' && $event->actor_id === $this->admin->id && $event->assistant_id === $this->assistant->id));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $events->pluck('doctor_profile_id')->all());
        $this->assertTrue(app(DoctorAccess::class)->canActFor($this->assistant, $second, 'view_appointments'));
    }

    public function test_a_doctor_can_have_several_assistants(): void
    {
        [, $profile] = $this->doctor();
        $other = $this->activeAssistant('second.assistant@example.com');

        foreach ([$this->assistant, $other] as $assistant) {
            $this->actingAs($this->admin)->post(route('admin.assistants.assignments.store', $assistant), ['doctor_profile_ids' => [$profile->id]])->assertSessionHasNoErrors();
        }

        $this->assertEqualsCanonicalizing([$this->assistant->id, $other->id], $profile->assistants()->pluck('users.id')->all());
    }

    public function test_duplicates_are_rejected_without_new_history(): void
    {
        [, $profile] = $this->doctor();
        $assign = fn (array $ids) => $this->actingAs($this->admin)->post(route('admin.assistants.assignments.store', $this->assistant), ['doctor_profile_ids' => $ids]);

        $assign([$profile->id])->assertSessionHasNoErrors();
        $assign([$profile->id])->assertSessionHasErrors(['doctor_profile_ids' => 'This assistant is already assigned to one of these doctors.']);
        $assign([$profile->id, $profile->id])->assertSessionHasErrors('doctor_profile_ids.0');

        $this->assertSame(1, AssistantAssignment::count());
        $this->assertSame(1, AssistantAssignmentEvent::count());
    }

    public function test_inactive_or_invited_doctors_and_unknown_ids_cannot_be_assigned(): void
    {
        [, $active] = $this->doctor();
        [, $deactivated] = $this->doctor(['deactivated_at' => now()]);
        $this->actingAs($this->admin)->post(route('admin.doctors.store'), [
            'name' => 'Dr. Pending', 'email' => 'pending.doctor@example.com', 'professional_title' => 'GP', 'license_number' => 'LIC-P', 'consultation_fee' => '1',
        ]);
        $pending = User::where('email', 'pending.doctor@example.com')->sole()->doctorProfile;

        foreach ([[$deactivated->id], [$pending->id], [$active->id, $deactivated->id]] as $ids) {
            $this->actingAs($this->admin)->post(route('admin.assistants.assignments.store', $this->assistant), ['doctor_profile_ids' => $ids])
                ->assertSessionHasErrors(['doctor_profile_ids' => 'Only active doctors can be assigned.']);
        }
        $this->actingAs($this->admin)->post(route('admin.assistants.assignments.store', $this->assistant), ['doctor_profile_ids' => [999999]])
            ->assertSessionHasErrors('doctor_profile_ids.0');
        $this->actingAs($this->admin)->post(route('admin.assistants.assignments.store', $this->assistant), ['doctor_profile_ids' => []])
            ->assertSessionHasErrors('doctor_profile_ids');

        $this->assertSame(0, AssistantAssignment::count(), 'A rejected batch assigns nothing, not even its valid doctors.');
        $this->assertSame(0, AssistantAssignmentEvent::count());
    }

    public function test_inactive_or_invited_assistants_cannot_receive_assignments(): void
    {
        [, $profile] = $this->doctor();
        ['assistant' => $invited] = $this->invite('invited@example.com', $this->admin);
        $this->actingAs($this->admin)->patch(route('admin.assistants.status', $this->assistant), ['action' => 'deactivate']);

        foreach ([$invited, $this->assistant] as $assistant) {
            $this->actingAs($this->admin)->post(route('admin.assistants.assignments.store', $assistant), ['doctor_profile_ids' => [$profile->id]])
                ->assertSessionHasErrors(['doctor_profile_ids' => 'Only active assistants can be assigned to doctors.']);
        }

        $this->assertSame(0, AssistantAssignment::count());
    }

    public function test_removal_keeps_history_and_revokes_access_immediately(): void
    {
        [, $profile] = $this->doctor();
        $this->actingAs($this->admin)->post(route('admin.assistants.assignments.store', $this->assistant), ['doctor_profile_ids' => [$profile->id]]);
        $this->assertTrue(app(DoctorAccess::class)->canActFor($this->assistant, $profile, 'view_appointments'));

        $this->actingAs($this->admin)->delete(route('admin.assistants.assignments.destroy', [$this->assistant, $profile]))->assertSessionHasNoErrors();

        $this->assertFalse(app(DoctorAccess::class)->canActFor($this->assistant, $profile, 'view_appointments'), 'Revoked on the next check.');
        $this->assertSame(0, AssistantAssignment::count());
        $history = AssistantAssignmentEvent::orderBy('id')->get();
        $this->assertSame(['assigned', 'removed'], $history->pluck('action')->all());
        $this->assertSame($this->admin->id, $history->last()->actor_id);
        $this->actingAs($this->admin)->get(route('admin.assistants.show', $this->assistant))->assertOk()->assertSee('Removed')->assertSee('Not assigned to any doctor.');

        $this->actingAs($this->admin)->delete(route('admin.assistants.assignments.destroy', [$this->assistant, $profile]))->assertNotFound();
        $this->assertSame(2, AssistantAssignmentEvent::count(), 'Removing a missing assignment records nothing.');
    }

    public function test_only_admins_can_change_assignments_and_assistants_cannot_assign_themselves(): void
    {
        [$doctor, $profile] = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient']);

        foreach ([$this->assistant, $doctor, $patient] as $user) {
            $this->flushSession();
            $this->actingAs($user)->post(route('admin.assistants.assignments.store', $this->assistant), ['doctor_profile_ids' => [$profile->id]])->assertForbidden();
        }
        $this->assertSame(0, AssistantAssignment::count());

        foreach ([$this->assistant, $doctor, $patient, $this->admin(['deactivated_at' => now()])] as $actor) {
            try {
                app(AssistantAssignmentService::class)->assign($actor, $this->assistant, [$profile->id]);
                $this->fail('Only active admins may assign.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, AssistantAssignmentEvent::count());
    }

    public function test_a_failed_change_leaves_no_partial_assignment_or_history(): void
    {
        [, $first] = $this->doctor();
        [, $second] = $this->doctor();
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "assistant_assignment_events"') && ++$writes === 2) {
                throw new RuntimeException('Simulated failure while recording history.');
            }
        });

        try {
            app(AssistantAssignmentService::class)->assign($this->admin, $this->assistant, [$first->id, $second->id]);
            $this->fail('The simulated failure must surface.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, AssistantAssignment::count(), 'The first assignment was rolled back too.');
        $this->assertSame(0, AssistantAssignmentEvent::count());
        $this->assertFalse(app(DoctorAccess::class)->canActFor($this->assistant, $first, 'view_appointments'));
    }

    public function test_the_assign_form_only_offers_active_unassigned_doctors(): void
    {
        [$doctor, $assigned] = $this->doctor(['name' => 'Dr. Already Assigned']);
        [, $available] = $this->doctor(['name' => 'Dr. Available']);
        $this->doctor(['name' => 'Dr. Inactive', 'deactivated_at' => now()]);
        $this->actingAs($this->admin)->post(route('admin.assistants.assignments.store', $this->assistant), ['doctor_profile_ids' => [$assigned->id]]);

        $this->actingAs($this->admin)->get(route('admin.assistants.show', $this->assistant))->assertOk()
            ->assertViewHas('availableDoctors', fn ($doctors) => $doctors->pluck('id')->all() === [$available->id])
            ->assertSee('value="'.$available->id.'"', false)
            ->assertDontSee('Dr. Inactive');
    }
}
