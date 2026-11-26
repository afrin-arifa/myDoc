<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ProvisionsAssistants;
use Tests\TestCase;

class PatientRegistrationTest extends TestCase
{
    use ProvisionsAssistants, RefreshDatabase;

    public function test_a_doctor_creates_a_patient_and_sees_the_one_time_password_once(): void
    {
        [$doctor, $profile] = $this->doctor();

        $this->flushSession();

        $this->actingAs($doctor)->get(route('doctor.patients.index'))->assertSee('Add patient');
        $this->get(route('doctor.patients.create'))->assertOk()->assertSee('Add a patient account');

        $response = $this->post(route('doctor.patients.store'), ['name' => '  Nadia   Islam ', 'email' => 'Nadia@Example.com', 'phone' => '01711000000'])
            ->assertRedirect(route('doctor.patients.index'))->assertSessionHas('issuedCredentials');
        $credentials = session('issuedCredentials');

        $patient = User::where('email', 'nadia@example.com')->sole();
        $this->assertSame(['Nadia Islam', 'patient', true, $profile->id, $doctor->id], [$patient->name, $patient->role, $patient->password_change_required, $patient->registered_by_doctor_profile_id, $patient->registered_by]);
        $this->assertTrue(Hash::check($credentials['password'], $patient->password));
        $this->assertMatchesRegularExpression('/^[A-Za-z2-9]{5}-[A-Za-z2-9]{5}$/', $credentials['password']);
        $this->assertTrue($patient->temporary_password_expires_at->between(now()->addHours(47), now()->addHours(49)));
        $this->assertDatabaseHas('doctor_workspace_events', ['doctor_profile_id' => $profile->id, 'actor_id' => $doctor->id, 'action' => 'patient_registered']);

        // Shown on the next page only, then gone; the patient is in this doctor's list and patient search.
        $this->followRedirects($response)->assertSee($credentials['password'])->assertSee('nadia@example.com');
        $this->get(route('doctor.patients.index'))->assertDontSee($credentials['password'])->assertSee('Nadia Islam')->assertSee('Awaiting first sign-in');
        $this->getJson(route('doctor.patients.search', ['search' => 'Nadia']))->assertJsonPath('data.0.name', 'Nadia Islam');

        // An existing email is refused.
        $this->post(route('doctor.patients.store'), ['name' => 'Copy', 'email' => 'nadia@example.com'])->assertSessionHasErrors('email');
    }

    public function test_the_patient_must_choose_a_new_password_at_first_sign_in(): void
    {
        [$doctor] = $this->doctor();
        $this->flushSession();
        $this->actingAs($doctor)->post(route('doctor.patients.store'), ['name' => 'Rafi', 'email' => 'rafi@example.com']);
        $password = session('issuedCredentials')['password'];
        $this->post(route('logout'));

        $this->post(route('login'), ['email' => 'rafi@example.com', 'password' => $password])->assertRedirect(route('password.first'));
        $this->get(route('patient.dashboard'))->assertRedirect(route('password.first'));
        $this->get(route('password.first'))->assertOk()->assertSee('Choose your own password');

        $this->put(route('password.first.update'), ['password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->put(route('password.first.update'), ['password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->put(route('password.first.update'), ['password' => 'Strong-pass-1', 'password_confirmation' => 'Strong-pass-1'])
            ->assertRedirect(route('patient.dashboard'));

        $patient = User::where('email', 'rafi@example.com')->sole();
        $this->assertFalse($patient->password_change_required);
        $this->assertNull($patient->temporary_password_expires_at);
        $this->get(route('patient.dashboard'))->assertOk();
        $this->get(route('password.first'))->assertRedirect(route('patient.dashboard'));
    }

    public function test_an_expired_one_time_password_is_refused_and_staff_can_issue_a_new_one_until_first_sign_in(): void
    {
        [$doctor, $profile] = $this->doctor();
        $this->flushSession();
        $this->actingAs($doctor)->post(route('doctor.patients.store'), ['name' => 'Mila', 'email' => 'mila@example.com']);
        $first = session('issuedCredentials')['password'];
        $patient = User::where('email', 'mila@example.com')->sole();
        $this->post(route('logout'));

        $this->travel(49)->hours();
        $this->post(route('login'), ['email' => 'mila@example.com', 'password' => $first])->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->flushSession();

        $this->actingAs($doctor)->get(route('doctor.patients.index'))->assertSee('One-time password expired')->assertSee('New one-time password');
        $this->post(route('doctor.patients.password', $patient))->assertRedirect(route('doctor.patients.index'))->assertSessionHas('issuedCredentials');
        $second = session('issuedCredentials')['password'];
        $this->assertNotSame($first, $second);
        $this->post(route('logout'));

        $this->post(route('login'), ['email' => 'mila@example.com', 'password' => $first])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => 'mila@example.com', 'password' => $second])->assertRedirect(route('password.first'));
        $this->put(route('password.first.update'), ['password' => 'Strong-pass-1', 'password_confirmation' => 'Strong-pass-1']);
        $this->post(route('logout'));

        // After the patient chose their own password, staff can no longer set one.
        $this->flushSession();
        $this->actingAs($doctor)->post(route('doctor.patients.password', $patient))->assertNotFound();
    }

    public function test_assistants_register_for_their_doctor_only_and_other_doctors_cannot_reset(): void
    {
        [, $profile] = $this->doctor();
        [$otherDoctor, $otherProfile] = $this->doctor();
        $assistant = $this->activeAssistant();
        $profile->assistants()->attach($assistant->id, ['assigned_by' => $this->admin()->id]);

        $this->flushSession();

        $this->actingAs($assistant)->get(route('assistant.workspace.patients.index', $profile))->assertSee('Add patient');
        $this->post(route('assistant.workspace.patients.store', $profile), ['name' => 'Tania', 'email' => 'tania@example.com'])
            ->assertRedirect(route('assistant.workspace.patients.index', $profile));
        $patient = User::where('email', 'tania@example.com')->sole();
        $this->assertSame([$profile->id, $assistant->id], [$patient->registered_by_doctor_profile_id, $patient->registered_by]);

        $this->post(route('assistant.workspace.patients.store', $otherProfile), ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();
        $this->post(route('logout'));

        $this->flushSession();

        $this->actingAs($otherDoctor)->post(route('doctor.patients.password', $patient))->assertNotFound();
        $this->get(route('doctor.patients.index'))->assertDontSee('Tania');

        foreach ([User::factory()->create(['role' => 'patient']), $this->admin()] as $user) {
            $this->post(route('logout'));
            $this->flushSession();
            $this->actingAs($user)->post(route('doctor.patients.store'), ['name' => 'Y', 'email' => 'y@example.com'])->assertForbidden();
        }
        $this->assertSame(0, User::where('email', 'y@example.com')->count());
    }
}
