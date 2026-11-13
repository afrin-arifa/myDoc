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

    public function test_guests_see_the_informational_links_and_sign_in_options(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $this->assertSame([
            'Home' => route('home'),
            'Doctors' => route('doctors.index'),
            'How it works' => url('/#how-it-works'),
            'Why myDoc' => url('/#features'),
            'Log in' => route('login'),
            'Register' => route('register'),
        ], $this->navLinks($response));
        $this->assertSame(['Home'], $this->activeLinks($response));
        $response->assertSee('id="how-it-works"', false)->assertSee('id="features"', false);
        $response->assertSee('navbar-expand-lg', false);
    }

    public function test_patients_see_their_own_pages_without_informational_links(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $response = $this->actingAs($patient)->get(route('patient.documents.index'))->assertOk();

        $this->assertSame([
            'Home' => route('home'),
            'Doctors' => route('doctors.index'),
            'My appointments' => route('patient.appointments.index'),
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
            'Home' => route('home'),
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

    public function test_every_role_can_open_each_of_its_navigation_links(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);
        $users = [
            'patient' => [User::factory()->create(['role' => 'patient']), route('patient.dashboard')],
            'doctor' => [$doctor, route('doctor.dashboard')],
            'admin' => [User::factory()->create(['role' => 'admin']), route('admin.dashboard')],
        ];

        foreach ($users as $role => [$user, $start]) {
            foreach ($this->navLinks($this->actingAs($user)->get($start)->assertOk()) as $label => $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
        }
    }

    public function test_the_how_it_works_and_why_mydoc_sections_still_exist_for_signed_in_users(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $this->actingAs($patient)->get(route('home'))->assertOk()
            ->assertSee('id="how-it-works"', false)
            ->assertSee('id="features"', false)
            ->assertDontSee('>How it works</a>', false)
            ->assertDontSee('>Why myDoc</a>', false);
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
