<?php

namespace Tests\Feature;

use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentEvent;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AssistantRoleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_roles_and_the_default_are_unchanged(): void
    {
        foreach (['patient', 'doctor', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertSame($role, $user->fresh()->role);
        }

        $id = DB::table('users')->insertGetId(['name' => 'Default Role', 'email' => 'default@example.com', 'password' => 'x']);
        $this->assertSame('patient', DB::table('users')->where('id', $id)->value('role'));
        $this->assertSame('Patient', User::find($id)->roleLabel());
    }

    public function test_the_assistant_role_is_stored_as_assistant_with_its_display_label(): void
    {
        $assistant = $this->assistant();

        $this->assertSame('assistant', $assistant->fresh()->role);
        $this->assertSame(User::ROLE_ASSISTANT, $assistant->fresh()->role);
        $this->assertSame('Doctor Assistant', $assistant->roleLabel());
        $this->assertSame(['patient' => 'Patient', 'doctor' => 'Doctor', 'admin' => 'Admin', 'assistant' => 'Doctor Assistant'], User::ROLE_LABELS);
    }

    public function test_the_database_rejects_unknown_roles(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert(['name' => 'Guest', 'email' => 'guest@example.com', 'password' => 'x', 'role' => 'guest']);
    }

    public function test_role_is_still_not_mass_assignable_and_no_existing_form_can_grant_assistant(): void
    {
        $user = new User(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret', 'role' => 'assistant']);
        $this->assertNull($user->role, 'role is ignored by mass assignment.');

        $this->post(route('register'), [
            'name' => 'New Person',
            'email' => 'new.person@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'assistant',
        ])->assertRedirect(route('patient.dashboard'));
        $this->assertSame('patient', User::where('email', 'new.person@example.com')->value('role'));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);
        $this->actingAs($admin)->patch(route('admin.users.update', $patient), [
            'name' => $patient->name,
            'email' => $patient->email,
            'role' => 'assistant',
        ])->assertSessionHasErrors('role');
        $this->assertSame('patient', $patient->fresh()->role);
    }

    public function test_an_assistant_account_cannot_reach_any_existing_role_area_yet(): void
    {
        $assistant = $this->assistant();

        foreach (['patient.dashboard', 'doctor.dashboard', 'admin.dashboard', 'patient.documents.index', 'doctor.medical-records.index', 'admin.users.index'] as $route) {
            $this->actingAs($assistant)->get(route($route))->assertForbidden();
        }
    }

    public function test_an_assistant_can_serve_several_doctors_and_a_doctor_can_have_several_assistants(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$first, $second] = [$this->doctorProfile(), $this->doctorProfile()];
        [$amina, $rafi] = [$this->assistant(), $this->assistant()];

        $amina->assignedDoctors()->attach([$first->id => ['assigned_by' => $admin->id], $second->id => ['assigned_by' => $admin->id]]);
        $first->assistants()->attach($rafi->id, ['assigned_by' => $admin->id]);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $amina->assignedDoctors()->pluck('doctor_profiles.id')->all());
        $this->assertEqualsCanonicalizing([$amina->id, $rafi->id], $first->assistants()->pluck('users.id')->all());
        $this->assertSame([$amina->id], $second->assistants()->pluck('users.id')->all());
        $this->assertSame(2, $amina->assistantAssignments()->count());
        $this->assertSame(2, $first->assistantAssignments()->count());

        $assignment = $first->assistantAssignments()->where('assistant_id', $amina->id)->sole();
        $this->assertInstanceOf(AssistantAssignment::class, $assignment);
        $this->assertTrue($assignment->doctorProfile->is($first));
        $this->assertTrue($assignment->assistant->is($amina));
        $this->assertTrue($assignment->assignedBy->is($admin));
        $this->assertInstanceOf(AssistantAssignment::class, $amina->assignedDoctors()->first()->pivot);
        $this->assertSame($admin->id, $amina->assignedDoctors()->first()->pivot->assigned_by);
        $this->assertNotNull($assignment->created_at);
    }

    public function test_the_same_doctor_and_assistant_cannot_be_assigned_twice(): void
    {
        $doctor = $this->doctorProfile();
        $assistant = $this->assistant();
        $doctor->assistants()->attach($assistant->id);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('doctor_assistant')->insert(['doctor_profile_id' => $doctor->id, 'assistant_id' => $assistant->id]);
    }

    public function test_assignment_foreign_keys_are_enforced_and_cascade_with_the_assignment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doctor = $this->doctorProfile();
        $assistant = $this->assistant();

        foreach ([['doctor_profile_id' => 999999, 'assistant_id' => $assistant->id], ['doctor_profile_id' => $doctor->id, 'assistant_id' => 999999], ['doctor_profile_id' => $doctor->id, 'assistant_id' => $assistant->id, 'assigned_by' => 999999]] as $row) {
            try {
                DB::table('doctor_assistant')->insert($row);
                $this->fail('An assignment must reference existing records.');
            } catch (QueryException) {
            }
        }

        $doctor->assistants()->attach($assistant->id, ['assigned_by' => $admin->id]);
        $admin->delete();
        $this->assertNull(AssistantAssignment::sole()->assigned_by, 'The assignment survives when the assigning user is deleted.');

        $assistant->delete();
        $this->assertSame(0, AssistantAssignment::count(), 'Deleting the assistant removes their assignment.');

        $other = $this->assistant();
        $doctor->assistants()->attach($other->id);
        $doctor->delete();
        $this->assertSame(0, AssistantAssignment::count(), 'Deleting the doctor profile removes its assignments.');
        $this->assertModelExists($other);
    }

    public function test_assignment_history_records_assigned_and_removed_and_survives_removal(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doctor = $this->doctorProfile();
        $assistant = $this->assistant();

        $doctor->assistants()->attach($assistant->id, ['assigned_by' => $admin->id]);
        $this->recordEvent($assistant, $doctor, AssistantAssignmentEvent::ACTION_ASSIGNED, $admin);
        $this->travel(1)->minutes();
        $doctor->assistants()->detach($assistant->id);
        $this->recordEvent($assistant, $doctor, AssistantAssignmentEvent::ACTION_REMOVED, $admin);

        $this->assertSame(0, AssistantAssignment::count(), 'Removing an assignment deletes its row.');
        $history = AssistantAssignmentEvent::query()->orderBy('created_at')->get();
        $this->assertSame(['assigned', 'removed'], $history->pluck('action')->all());
        $this->assertTrue($history->every(fn ($event) => $event->assistant_id === $assistant->id && $event->doctor_profile_id === $doctor->id && $event->actor_id === $admin->id));
        $this->assertTrue($history->first()->created_at->lt($history->last()->created_at));
        $this->assertTrue($history->first()->assistant->is($assistant));
        $this->assertTrue($history->first()->doctorProfile->is($doctor));
        $this->assertTrue($history->first()->actor->is($admin));
        $this->assertSame(0, $assistant->assistantAssignments()->count(), 'No assignment remains.');
        $this->assertSame(2, AssistantAssignmentEvent::where('assistant_id', $assistant->id)->count(), 'Its history does.');
    }

    public function test_history_is_append_only_and_validates_its_action(): void
    {
        $event = $this->recordEvent($this->assistant(), $this->doctorProfile(), AssistantAssignmentEvent::ACTION_ASSIGNED, null);

        foreach ([fn () => $event->forceFill(['action' => 'removed'])->save(), fn () => $event->delete()] as $change) {
            try {
                $change();
                $this->fail('History must not change.');
            } catch (LogicException) {
            }
        }
        $this->assertSame('assigned', $event->fresh()->action);

        $this->expectException(LogicException::class);
        $this->recordEvent($this->assistant(), $this->doctorProfile(), 'promoted', null);
    }

    public function test_history_blocks_deleting_its_assistant_or_doctor_and_keeps_events_when_the_actor_is_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doctor = $this->doctorProfile();
        $assistant = $this->assistant();
        $this->recordEvent($assistant, $doctor, AssistantAssignmentEvent::ACTION_ASSIGNED, $admin);

        foreach ([fn () => $assistant->delete(), fn () => $doctor->delete()] as $delete) {
            try {
                $delete();
                $this->fail('History must not be silently destroyed.');
            } catch (QueryException) {
            }
        }
        $this->assertModelExists($assistant);
        $this->assertModelExists($doctor);

        $admin->delete();
        $this->assertNull(AssistantAssignmentEvent::sole()->actor_id);
        $this->assertSame(1, AssistantAssignmentEvent::count());
    }

    public function test_assignment_models_are_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        AssistantAssignmentEvent::create(['assistant_id' => 1, 'doctor_profile_id' => 1, 'action' => 'assigned']);
    }

    private function recordEvent(User $assistant, DoctorProfile $doctor, string $action, ?User $actor): AssistantAssignmentEvent
    {
        $event = new AssistantAssignmentEvent;
        $event->forceFill([
            'assistant_id' => $assistant->id,
            'doctor_profile_id' => $doctor->id,
            'action' => $action,
            'actor_id' => $actor?->id,
        ])->save();

        return $event;
    }

    private function assistant(): User
    {
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();

        return $assistant->fresh();
    }

    private function doctorProfile(): DoctorProfile
    {
        $doctor = User::factory()->create(['role' => 'doctor']);

        return DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);
    }
}
