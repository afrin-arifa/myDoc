<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AssistantAssignmentEvent;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\UserPhotoStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ProvisionsAssistants;
use Tests\TestCase;

class AssistantScenariosTest extends TestCase
{
    use ProvisionsAssistants, RefreshDatabase;

    private const PASSWORD = 'Brand-new-1';

    public function test_an_admin_activates_a_waiting_assistant_by_setting_a_password(): void
    {
        ['assistant' => $assistant, 'token' => $token, 'admin' => $admin] = $this->invite();

        $this->setPassword($admin, $assistant)->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'activated'));
        $this->assertNull($assistant->fresh()->deactivated_at);

        auth()->logout();
        $this->flushSession();
        $this->post(route('assistant.invitation.accept'), $this->acceptPayload($token, $assistant->email, 'Other-pass-1'))->assertSee('invalid or has expired');
        $this->post(route('login'), ['email' => $assistant->email, 'password' => self::PASSWORD])->assertRedirect(route('assistant.dashboard'));
    }

    public function test_a_new_password_replaces_the_old_one_and_ends_existing_sessions(): void
    {
        $assistant = $this->activeAssistant();
        $this->post(route('login'), ['email' => $assistant->email, 'password' => 'Assistant-pass-1'])->assertRedirect(route('assistant.dashboard'));
        $this->get(route('assistant.dashboard'))->assertOk();
        $oldToken = $assistant->fresh()->remember_token;

        User::query()->whereKey($assistant->id)->update(['password' => Hash::make(self::PASSWORD)]);
        auth()->forgetGuards();
        $this->get(route('assistant.dashboard'))->assertRedirect(route('login'));

        $this->setPassword($this->admin(), $assistant)->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::PASSWORD, $assistant->fresh()->password));
        $this->assertNotSame($oldToken, $assistant->fresh()->remember_token);
    }

    public function test_setting_a_password_never_restores_a_deactivated_account_and_is_admin_only(): void
    {
        $assistant = $this->activeAssistant();
        $admin = $this->admin();
        $this->actingAs($admin)->patch(route('admin.assistants.status', $assistant), ['action' => 'deactivate'])->assertSessionHasNoErrors();

        $this->setPassword($admin, $assistant)->assertSessionHasErrors('password', errorBag: 'assistantPassword');
        $this->assertNotNull($assistant->fresh()->deactivated_at);
        $this->assertFalse(Hash::check(self::PASSWORD, $assistant->fresh()->password));
        $this->get(route('admin.assistants.show', $assistant))->assertOk()->assertDontSee('Set new password');

        $active = $this->activeAssistant('other@example.com');
        $this->setPassword($admin, $active, 'weak')->assertSessionHasErrors('password', errorBag: 'assistantPassword');
        [$doctor] = $this->doctor();
        foreach ([$active, $doctor, User::factory()->create(['role' => 'patient'])] as $user) {
            $this->setPassword($user, $active)->assertForbidden();
        }
        // The route only ever resolves assistant accounts.
        $this->setPassword($admin, $doctor)->assertNotFound();
    }

    public function test_doctors_can_be_assigned_while_creating_and_a_bad_choice_creates_nothing(): void
    {
        [, $first] = $this->doctor();
        [, $second] = $this->doctor();
        [$inactive, $inactiveProfile] = $this->doctor();
        $inactive->forceFill(['deactivated_at' => now()])->save();
        $admin = $this->admin();
        $account = ['name' => 'Nila', 'email' => 'nila@example.com', 'setup' => 'password', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];

        $this->actingAs($admin)->get(route('admin.assistants.create'))->assertOk()->assertSee($first->user->name)->assertDontSee($inactive->name);
        $this->post(route('admin.assistants.store'), [...$account, 'doctor_profile_ids' => [$first->id, $inactiveProfile->id]])->assertSessionHasErrors('doctor_profile_ids');
        $this->assertSame(0, User::where('email', 'nila@example.com')->count());

        $this->post(route('admin.assistants.store'), [...$account, 'doctor_profile_ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();
        $assistant = User::where('email', 'nila@example.com')->sole();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $assistant->assignedDoctors()->pluck('doctor_profiles.id')->all());
        $this->assertSame(2, AssistantAssignmentEvent::where('assistant_id', $assistant->id)->where('actor_id', $admin->id)->count());

        // An invited account is not active yet, so doctors sent with it are ignored.
        $this->post(route('admin.assistants.store'), ['name' => 'Later', 'email' => 'later@example.com', 'setup' => 'invitation', 'doctor_profile_ids' => [$first->id]])->assertSessionHasNoErrors();
        $this->assertSame(0, User::where('email', 'later@example.com')->sole()->assistantAssignments()->count());
    }

    public function test_a_doctor_sees_only_their_own_assistants_and_what_they_did(): void
    {
        [$doctor, $profile] = $this->doctor();
        [$otherDoctor, $otherProfile] = $this->doctor();
        $mine = $this->assigned('mine@example.com', $profile);
        $this->assigned('theirs@example.com', $otherProfile);
        $appointment = $this->appointment($profile);

        $this->flushSession();
        $this->actingAs($mine)->post(route('assistant.workspace.appointments.confirm', [$profile, $appointment]))->assertSessionHasNoErrors();

        $this->flushSession();
        $this->actingAs($doctor)->get(route('doctor.assistants.index'))->assertOk()
            ->assertSee('mine@example.com')->assertDontSee('theirs@example.com')
            ->assertSee('Confirmed appointment')->assertSee($appointment->reference());
        $this->get(route('doctor.assistants.index', ['assistant' => $mine->id + 999]))->assertOk()->assertSee('No assistant activity yet.');
        $this->flushSession();
        $this->actingAs($otherDoctor)->get(route('doctor.assistants.index'))->assertOk()->assertDontSee($appointment->reference());

        // The record stays after the assistant leaves.
        $this->flushSession();
        $this->actingAs($this->admin())->delete(route('admin.assistants.assignments.destroy', [$mine, $profile]))->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($doctor)->get(route('doctor.assistants.index'))->assertSee('No assistants yet')->assertSee('Confirmed appointment');

        foreach ([$mine, $this->admin(), User::factory()->create(['role' => 'patient'])] as $user) {
            $this->flushSession();
            $this->actingAs($user)->get(route('doctor.assistants.index'))->assertForbidden();
        }
    }

    public function test_admins_see_a_doctors_assistants_and_find_assistants_without_a_doctor(): void
    {
        [, $profile] = $this->doctor();
        $this->assigned('busy@example.com', $profile);
        $this->activeAssistant('idle@example.com');
        $this->invite('waiting@example.com');
        $this->actingAs($this->admin());

        $this->get(route('admin.doctors.show', $profile))->assertOk()->assertSee('busy@example.com')->assertDontSee('idle@example.com');
        $found = $this->get(route('admin.assistants.index', ['status' => 'needs_doctor']))->assertOk()->assertSee('Needs a doctor')->viewData('assistants');
        $this->assertSame(['idle@example.com'], $found->pluck('email')->all());
    }

    public function test_losing_access_shows_a_helpful_page(): void
    {
        [, $profile] = $this->doctor();
        $assistant = $this->assigned('gone@example.com', $profile);
        $this->actingAs($assistant)->get(route('assistant.workspace.schedule', $profile))->assertOk();

        $profile->assistants()->detach($assistant->id);
        $this->get(route('assistant.workspace.schedule', $profile))->assertForbidden()
            ->assertSee('You cannot open this page')->assertSee('no longer be assigned')->assertSee(route('home'));
    }

    public function test_the_dashboard_puts_requests_of_the_assistants_doctors_first(): void
    {
        [, $profile] = $this->doctor();
        [, $otherProfile] = $this->doctor();
        $assistant = $this->assigned('desk@example.com', $profile);
        $request = $this->appointment($profile);
        $foreign = $this->appointment($otherProfile);

        $page = $this->actingAs($assistant)->get(route('assistant.dashboard'))->assertOk()
            ->assertSee('Requests to answer')
            ->assertSee(route('assistant.workspace.appointments.confirm', [$profile, $request]))
            ->assertDontSee($foreign->patient->name);
        $this->assertSame(1, $page->viewData('stats')['pending']);

        $this->from(route('assistant.dashboard'))->post(route('assistant.workspace.appointments.confirm', [$profile, $request]))->assertRedirect(route('assistant.dashboard'));
        $this->assertSame('confirmed', $request->fresh()->status);
        $this->get(route('assistant.dashboard'))->assertDontSee('Requests to answer');
    }

    public function test_an_assistant_uploads_replaces_and_removes_their_own_photo_only(): void
    {
        Storage::fake(UserPhotoStorage::DISK);
        $assistant = $this->activeAssistant();
        $this->actingAs($assistant)->get(route('assistant.account'))->assertOk()->assertSee('Add photo');

        $this->put(route('assistant.account.photo'), ['photo' => $this->png(), 'name' => 'Hacked', 'email' => 'hacked@example.com', 'role' => 'admin'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Your photo has been saved.');
        $first = $assistant->fresh()->photo_path;
        Storage::disk(UserPhotoStorage::DISK)->assertExists($first);
        $this->assertSame([$assistant->name, $assistant->email, User::ROLE_ASSISTANT], [$assistant->fresh()->name, $assistant->fresh()->email, $assistant->fresh()->role]);

        $this->put(route('assistant.account.photo'), ['photo' => UploadedFile::fake()->create('cv.pdf', 5, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->put(route('assistant.account.photo'), [])->assertSessionHasNoErrors();
        $this->assertSame($first, $assistant->fresh()->photo_path);

        $this->put(route('assistant.account.photo'), ['remove_photo' => 1])->assertSessionHas('status', 'Your photo has been removed.');
        $this->assertNull($assistant->fresh()->photo_path);
        Storage::disk(UserPhotoStorage::DISK)->assertMissing($first);

        foreach ([$this->admin(), $this->doctor()[0], User::factory()->create(['role' => 'patient'])] as $user) {
            $this->flushSession();
            $this->actingAs($user)->put(route('assistant.account.photo'), ['photo' => $this->png()])->assertForbidden();
        }
    }

    public function test_an_assistant_photo_is_seen_only_by_the_assistant_admins_and_their_current_doctors(): void
    {
        Storage::fake(UserPhotoStorage::DISK);
        [$doctor, $profile] = $this->doctor();
        [$otherDoctor] = $this->doctor();
        $assistant = $this->assigned('photo@example.com', $profile);
        $colleague = $this->assigned('colleague@example.com', $profile);
        $this->actingAs($assistant)->put(route('assistant.account.photo'), ['photo' => $this->png()])->assertSessionHasNoErrors();
        $url = route('users.photo', $assistant);
        $seenBy = function (User $user) use ($url): int {
            $this->flushSession();

            return $this->actingAs($user->fresh())->get($url)->getStatusCode();
        };

        foreach ([$assistant, $doctor, $this->admin()] as $user) {
            $this->assertSame(200, $seenBy($user), $user->role);
        }
        foreach ([$otherDoctor, $colleague, User::factory()->create(['role' => 'patient'])] as $user) {
            $this->assertSame(404, $seenBy($user), $user->role);
        }

        $this->flushSession();
        $this->actingAs($doctor)->get(route('doctor.assistants.index'))->assertOk()->assertSee($url);
        $this->flushSession();
        $this->actingAs($this->admin());
        foreach ([route('admin.assistants.index'), route('admin.assistants.show', $assistant), route('admin.doctors.show', $profile), route('admin.profile')] as $page) {
            $this->get($page)->assertOk()->assertSee($url);
        }

        $profile->assistants()->detach($assistant->id);
        $this->assertSame(404, $seenBy($doctor));
    }

    private function png(): UploadedFile
    {
        $raw = str_repeat("\0".str_repeat("\x16\x7d\x91", 64), 64);
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return UploadedFile::fake()->createWithContent('me.png', "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', 64, 64, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', ''));
    }

    private function setPassword(User $actor, User $assistant, string $password = self::PASSWORD): TestResponse
    {
        $this->flushSession();

        return $this->actingAs($actor)->put(route('admin.assistants.password', $assistant), ['password' => $password, 'password_confirmation' => $password]);
    }

    private function assigned(string $email, DoctorProfile $profile): User
    {
        $assistant = $this->activeAssistant($email);
        $profile->assistants()->attach($assistant->id, ['assigned_by' => $this->admin()->id]);

        return $assistant;
    }

    private function appointment(DoctorProfile $profile): Appointment
    {
        $start = now()->startOfHour()->addDays(2);
        $slot = AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(10), 'status' => 'booked']);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => User::factory()->create(['role' => 'patient'])->id, 'status' => 'booked', 'consultation_type' => 'physical', 'booked_at' => now()]);
    }
}
