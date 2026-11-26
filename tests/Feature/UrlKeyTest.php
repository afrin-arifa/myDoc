<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Addresses show a doctor's name or a letters-only code instead of database ids, without widening access, and older
 * numbered addresses still open.
 */
class UrlKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_are_letters_only_unique_and_reversible(): void
    {
        $codes = [];

        foreach ([...range(1, 2000), 999_999_999, 1_279_999_999] as $id) {
            $code = Appointment::urlKey($id);
            $this->assertMatchesRegularExpression('/\A[bcdfghjklmnpqrstvwxz]{7}\z/', $code);
            $this->assertSame($id, Appointment::idFromUrlKey($code));
            $codes[] = $code;
        }

        $this->assertSame($codes, array_unique($codes));
        $this->assertNotSame(Appointment::urlKey(1), AppointmentSlot::urlKey(1));
        $this->assertSame(12, Appointment::idFromUrlKey('12'));

        foreach (['create', 'BCDFGHJ', 'bcdfgh', '1a', '-1', '', null, ['x'], str_repeat('9', 30)] as $invalid) {
            $this->assertNull(Appointment::idFromUrlKey($invalid));
        }
    }

    public function test_doctors_get_a_readable_name_that_is_unique_and_kept_when_they_are_renamed(): void
    {
        $first = $this->doctor('Dr. Maya Bennett');
        $this->assertSame('maya-bennett', $first->slug);
        $this->assertSame('maya-bennett-b', $this->doctor('Maya Bennett')->slug);
        $this->assertSame('maya-bennett-c', $this->doctor('Doctor Maya Bennett 2')->slug);
        $this->assertSame('doctor', $this->doctor('Create')->slug);
        $this->assertSame('doctor-b', $this->doctor('123')->slug);

        $first->user->update(['name' => 'Dr. Maya Carter']);
        $this->assertSame('maya-bennett', $first->fresh()->getRouteKey());
    }

    public function test_assistant_addresses_have_no_digits_and_stay_limited_to_assigned_doctors(): void
    {
        $profile = $this->doctor('Dr. Maya Bennett');
        $other = $this->doctor('Dr. Ethan Brooks');
        $assistant = $this->assistant($profile);
        $appointment = $this->appointment($profile);

        $url = route('assistant.workspace.appointments.show', [$profile, $appointment], false);
        $this->assertSame('/assistant/doctors/maya-bennett/appointments/'.$appointment->getRouteKey(), $url);
        $this->assertDoesNotMatchRegularExpression('/\d/', $url);

        $this->actingAs($assistant)->get($url)->assertOk();
        $this->get('/assistant/doctors/ethan-brooks/appointments')->assertForbidden();
        $this->get('/assistant/doctors/nobody/appointments')->assertNotFound();
        $this->get(route('assistant.workspace.appointments.show', [$profile, $this->appointment($other)]))->assertForbidden();
    }

    public function test_numbered_addresses_move_to_the_current_one_only_after_access_is_checked(): void
    {
        $profile = $this->doctor('Dr. Maya Bennett');
        $other = $this->doctor('Dr. Ethan Brooks');
        $assistant = $this->assistant($profile);
        $appointment = $this->appointment($profile);

        $this->actingAs($assistant)->get("/assistant/doctors/{$profile->id}/appointments?tab=all")
            ->assertRedirect(route('assistant.workspace.appointments.index', [$profile, 'tab' => 'all']));
        $this->get("/assistant/doctors/{$profile->id}/appointments/{$appointment->id}")
            ->assertRedirect(route('assistant.workspace.appointments.show', [$profile, $appointment]));
        $this->get("/assistant/doctors/{$other->id}/appointments")->assertForbidden();

        $this->flushSession();
        $this->actingAs(User::factory()->create(['role' => 'patient']))
            ->get("/patient/appointments/{$appointment->id}")->assertForbidden();
        $this->flushSession();
        $this->actingAs($appointment->patient)->get("/patient/appointments/{$appointment->id}")
            ->assertRedirect(route('patient.appointments.show', $appointment));
    }

    public function test_pages_link_without_ids_in_paths(): void
    {
        $profile = $this->doctor('Dr. Maya Bennett');
        $assistant = $this->assistant($profile);
        $appointment = $this->appointment($profile);
        $admin = User::factory()->create(['role' => 'admin']);

        $pages = [
            [$profile->user, ['doctor.dashboard', 'doctor.schedule', 'doctor.appointments.index', 'doctor.assistants.index']],
            [$appointment->patient, ['patient.dashboard', 'patient.appointments.index', 'doctors.index']],
            [$admin, ['admin.users.index', 'admin.doctors.index', 'admin.assistants.index', 'admin.appointments.index', 'admin.specialties.index']],
        ];

        foreach ($pages as [$user, $routes]) {
            foreach ($routes as $route) {
                $this->assertNoIdsInLinks($user, route($route));
            }
        }

        $this->assertNoIdsInLinks($profile->user, route('doctor.schedule', ['date' => $appointment->slot->starts_at->toDateString(), 'view' => 'day']));
        $this->assertNoIdsInLinks($assistant, route('assistant.workspace.schedule', $profile));
        $this->assertNoIdsInLinks($appointment->patient, route('doctors.show', $profile));
        $this->assertNoIdsInLinks($admin, route('admin.assistants.show', $assistant));
    }

    public function test_schedule_links_only_mention_filters_that_were_chosen(): void
    {
        $doctor = $this->doctor('Dr. Maya Bennett')->user;

        $this->actingAs($doctor)->get(route('doctor.schedule'))->assertOk()->assertDontSee('filters%5B0', false)->assertDontSee('filters=', false);

        $page = $this->get(route('doctor.schedule', ['filters' => 'available,booked']))->assertOk()->assertSee('filters=available%2Cbooked', false);
        $this->assertSame(['available', 'booked'], $page->viewData('activeFilters'));
        $this->get(route('doctor.schedule', ['filters' => ['booked']]))->assertOk()->assertSee('filters=booked', false);
        $this->get(route('doctor.schedule', ['filters' => 'nonsense']))->assertSessionHasErrors('filters.0');
    }

    public function test_filters_use_codes(): void
    {
        $profile = $this->doctor('Dr. Maya Bennett');
        $this->doctor('Dr. Ethan Brooks');
        $specialty = Specialty::create(['name' => 'Cardiology', 'slug' => 'cardiology', 'is_active' => true]);
        $profile->specialties()->attach($specialty);

        $this->actingAs(User::factory()->create(['role' => 'patient']));
        $this->get(route('doctors.index', ['specialty' => $specialty->getRouteKey()]))->assertOk()
            ->assertSee('Maya Bennett')->assertDontSee('Ethan Brooks');
        $this->get(route('doctors.index', ['specialty' => 'bcdfghj']))->assertSessionHasErrors('specialty');
    }

    public function test_book_on_the_calendar_preselects_the_chosen_time(): void
    {
        $profile = $this->doctor('Dr. Maya Bennett');
        $slot = $this->slot($profile, 'available');

        $this->actingAs($profile->user)
            ->get(route('doctor.appointments.create', ['date' => $slot->starts_at->toDateString(), 'slot' => $slot->getRouteKey()]))
            ->assertOk()->assertSee('value="'.$slot->id.'" required checked', false);
    }

    private function assertNoIdsInLinks(User $user, string $url): void
    {
        $this->flushSession();
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        preg_match_all('/(?:href|action)="([^"]+)"/', $html, $matches);

        foreach ($matches[1] as $link) {
            if (Str::startsWith($link, config('app.url')) || Str::startsWith($link, url('/'))) {
                $this->assertDoesNotMatchRegularExpression('#/\d+(/|\?|$)#', $link, "{$url} links to {$link}");
                $this->assertDoesNotMatchRegularExpression('/[?;&](slot|slot_id|follow_up|location|doctor|patient|specialty)=\d/', $link, "{$url} links to {$link}");
            }
        }
    }

    private function doctor(string $name): DoctorProfile
    {
        $user = User::factory()->create(['role' => 'doctor', 'name' => $name]);

        return DoctorProfile::create([
            'user_id' => $user->id,
            'professional_title' => 'GP',
            'license_number' => 'LIC-'.$user->id,
            'consultation_fee' => 10,
            'is_accepting_patients' => true,
        ]);
    }

    private function assistant(DoctorProfile $profile): User
    {
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $profile->assistants()->attach($assistant->id);

        return $assistant;
    }

    private function slot(DoctorProfile $profile, string $status): AppointmentSlot
    {
        $start = CarbonImmutable::tomorrow(config('app.timezone'))->addDay()->setTime(10, 0);

        return AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(10),
            'status' => $status,
        ]);
    }

    private function appointment(DoctorProfile $profile): Appointment
    {
        return Appointment::create([
            'appointment_slot_id' => $this->slot($profile, 'booked')->id,
            'patient_id' => User::factory()->create(['role' => 'patient'])->id,
            'status' => 'booked',
            'consultation_type' => 'physical',
        ]);
    }
}
