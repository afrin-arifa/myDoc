<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_only_see_sign_in_and_registration(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $response = $this->get(route('login'))->assertOk();

        $this->assertSame([
            'Log in' => route('login'),
            'Register' => route('register'),
        ], $this->navLinks($response));
        $this->assertSame(['Log in'], $this->activeLinks($response));
        $response->assertSee('class="navbar-brand fs-3" href="'.route('login').'"', false)
            ->assertDontSee('How it works')->assertDontSee('Why myDoc')->assertDontSee(route('doctors.index'), false)
            ->assertSee('navbar-expand-lg', false);
    }

    public function test_patients_see_their_own_pages_without_informational_links(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $response = $this->actingAs($patient)->get(route('patient.documents.index'))->assertOk();

        $this->assertSame([
            'Doctors' => route('doctors.index'),
            'My appointments' => route('patient.appointments.index'),
            'Waiting list' => route('patient.waitlist.index'),
            'Medical records' => route('patient.documents.index'),
            'Profile' => route('patient.profile'),
            'Dashboard' => route('patient.dashboard'),
        ], $this->navLinks($response));
        $this->assertSame(['Medical records'], $this->activeLinks($response));
        $this->assertLogoutButton($response);
        $response->assertSee('navbar-expand-xl', false);
    }

    public function test_doctors_see_workspace_links_but_no_directory_or_patient_links(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        $response = $this->actingAs($doctor)->get(route('doctor.medical-records.index'))->assertOk();

        $this->assertSame([
            'Appointments' => route('doctor.appointments.index'),
            'Schedule' => route('doctor.schedule'),
            'Patients' => route('doctor.patients.index'),
            'Medical records' => route('doctor.medical-records.index'),
            'Profile' => route('doctor.profile'),
            'Dashboard' => route('doctor.dashboard'),
        ], $this->navLinks($response));
        $this->assertSame(['Medical records'], $this->activeLinks($response));
        $this->assertLogoutButton($response);
        $this->actingAs($doctor)->get(route('doctors.index'))->assertForbidden();
    }

    public function test_admins_see_administration_links(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.appointments.index'))->assertOk();

        $this->assertSame([
            'Doctors' => route('admin.doctors.index'),
            'Patients' => route('admin.users.index', ['role' => 'patient']),
            'Appointments' => route('admin.appointments.index'),
            'Medical records' => route('admin.medical-documents.index'),
            'Dashboard' => route('admin.dashboard'),
        ], $this->navLinks($response));
        $this->assertSame(['Appointments'], $this->activeLinks($response));
        $this->assertLogoutButton($response);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->assertSame(['Dashboard'], $this->activeLinks($this->actingAs($admin)->get(route('admin.dashboard'))));
        $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'patient']))->assertOk();
    }

    public function test_assistants_see_their_doctors_and_account_but_no_doctor_patient_or_admin_links(): void
    {
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();

        $response = $this->actingAs($assistant)->get(route('assistant.account'))->assertOk();

        $this->assertSame([
            'My doctors' => route('assistant.doctors.index'),
            'Account' => route('assistant.account'),
            'Dashboard' => route('assistant.dashboard'),
        ], $this->navLinks($response));
        $this->assertSame(['Account'], $this->activeLinks($response));
        $this->assertLogoutButton($response);
    }

    public function test_every_role_can_open_each_of_its_navigation_links(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $users = [
            'patient' => [User::factory()->create(['role' => 'patient']), route('patient.dashboard')],
            'doctor' => [$doctor, route('doctor.dashboard')],
            'assistant' => [$assistant, route('assistant.dashboard')],
            'admin' => [User::factory()->create(['role' => 'admin']), route('admin.dashboard')],
        ];

        foreach ($users as $role => [$user, $start]) {
            foreach ($this->navLinks($this->actingAs($user)->get($start)->assertOk()) as $label => $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
        }
    }

    public function test_the_root_url_sends_each_signed_in_role_to_its_dashboard_without_a_marketing_page(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();

        foreach ([
            'patient.dashboard' => User::factory()->create(['role' => 'patient']),
            'doctor.dashboard' => $doctor,
            'assistant.dashboard' => $assistant,
            'admin.dashboard' => User::factory()->create(['role' => 'admin']),
        ] as $dashboard => $user) {
            $this->flushSession();
            $this->actingAs($user)->get('/')->assertRedirect(route($dashboard));
            $this->actingAs($user)->get(route('login'))->assertRedirect(route('home'));
            $this->actingAs($user)->get(route($dashboard))->assertOk()
                ->assertDontSee('id="how-it-works"', false)->assertDontSee('>Home</a>', false);
        }
    }

    /**
     * Link label => href, in navbar order (the logout form is not a link).
     *
     * @return array<string, string>
     */
    private function navLinks(TestResponse $response): array
    {
        $links = [];
        foreach ($this->navigation($response)->getElementsByTagName('a') as $anchor) {
            $links[trim($anchor->textContent)] = $anchor->getAttribute('href');
        }

        return $links;
    }

    /**
     * @return list<string>
     */
    private function activeLinks(TestResponse $response): array
    {
        $active = [];
        foreach ($this->navigation($response)->getElementsByTagName('a') as $anchor) {
            if (in_array('active', explode(' ', $anchor->getAttribute('class')), true)) {
                $this->assertSame('page', $anchor->getAttribute('aria-current'));
                $active[] = trim($anchor->textContent);
            }
        }

        return $active;
    }

    private function assertLogoutButton(TestResponse $response): void
    {
        $form = $this->navigation($response)->getElementsByTagName('form')->item(0);

        $this->assertNotNull($form);
        $this->assertSame(route('logout'), $form->getAttribute('action'));
        $this->assertSame('Log out', trim($form->getElementsByTagName('button')->item(0)->textContent));
    }

    private function navigation(TestResponse $response): \DOMElement
    {
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());

        return $document->getElementById('main-navigation');
    }
}
