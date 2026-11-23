<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDoctorLocations;
use Tests\TestCase;

/**
 * Doctor-to-doctor workspace isolation. Every scenario runs in both directions (A against B, then B against A).
 */
class DoctorDataIsolationTest extends TestCase
{
    use CreatesDoctorLocations, RefreshDatabase;

    /**
     * @var array<string, array{doctor: User, profile: DoctorProfile, patient: User, booked: Appointment, confirmed: Appointment, freeSlot: AppointmentSlot, availability: DoctorAvailability}>
     */
    private array $workspaces = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(CarbonImmutable::create(2026, 11, 16, 8, 0, 0, config('app.timezone')));
        $this->workspaces = [
            'A' => $this->workspace('A', 9),
            'B' => $this->workspace('B', 14),
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directions(): iterable
    {
        yield 'Doctor A against Doctor B' => ['A', 'B'];
        yield 'Doctor B against Doctor A' => ['B', 'A'];
    }

    #[DataProvider('directions')]
    public function test_appointment_lists_show_only_the_doctors_own_appointments(string $self, string $other): void
    {
        $me = $this->workspaces[$self];
        $them = $this->workspaces[$other];

        foreach (['upcoming', 'all', 'cancelled', 'completed'] as $tab) {
            $response = $this->actingAs($me['doctor'])->get(route('doctor.appointments.index', ['tab' => $tab, 'search' => $them['patient']->name]))->assertOk();
            $this->assertTrue($response->viewData('appointments')->every(fn (Appointment $appointment) => $appointment->slot->doctor_profile_id === $me['profile']->id));
            $this->assertCount(0, $response->viewData('appointments'), "Searching for another doctor's patient finds nothing ({$tab}).");
        }

        $own = $this->actingAs($me['doctor'])->get(route('doctor.appointments.index', ['tab' => 'all']))->assertOk();
        $this->assertEqualsCanonicalizing(
            [$me['booked']->id, $me['confirmed']->id],
            $own->viewData('appointments')->pluck('id')->all()
        );
        $own->assertSee($me['patient']->name)->assertDontSee($them['patient']->name);
    }

    #[DataProvider('directions')]
    public function test_another_doctors_appointment_cannot_be_viewed_or_changed_by_url_or_form(string $self, string $other): void
    {
        $me = $this->workspaces[$self];
        $them = $this->workspaces[$other];
        $doctor = $me['doctor'];

        $this->actingAs($doctor)->get(route('doctor.appointments.show', $them['booked']))->assertForbidden()->assertDontSee($them['patient']->name);
        $this->actingAs($doctor)->get(route('doctor.appointments.reschedule', $them['confirmed']))->assertForbidden();
        $this->actingAs($doctor)->post(route('doctor.appointments.confirm', $them['booked']))->assertForbidden();
        $this->actingAs($doctor)->post(route('doctor.appointments.reject', $them['booked']))->assertForbidden();
        $this->actingAs($doctor)->post(route('doctor.appointments.complete', $them['confirmed']))->assertForbidden();
        $this->actingAs($doctor)->post(route('doctor.appointments.start', $them['confirmed']))->assertForbidden();
        foreach ([$me['freeSlot'], $them['freeSlot']] as $target) {
            $this->actingAs($doctor)->post(route('doctor.appointments.reschedule.store', $them['confirmed']), ['slot_id' => $target->id])->assertForbidden();
        }
        $this->actingAs($doctor)->getJson(route('doctor.appointments.show', $them['booked']))->assertForbidden();

        $this->assertSame('booked', $them['booked']->fresh()->status);
        $this->assertSame('confirmed', $them['confirmed']->fresh()->status);
        $this->assertSame($them['confirmed']->appointment_slot_id, $them['confirmed']->fresh()->appointment_slot_id);
        $this->assertSame('available', $me['freeSlot']->fresh()->status);
        $this->assertSame('available', $them['freeSlot']->fresh()->status);
        $this->assertDatabaseCount('appointment_reschedule_histories', 0);
        $this->assertDatabaseCount('appointment_notifications', 0);
    }

    #[DataProvider('directions')]
    public function test_schedule_and_dashboard_contain_only_the_doctors_own_data(string $self, string $other): void
    {
        $me = $this->workspaces[$self];
        $them = $this->workspaces[$other];

        $schedule = $this->actingAs($me['doctor'])->get(route('doctor.schedule', [
            'view' => 'month',
            'date' => '2026-11-18',
            'doctor_profile_id' => $them['profile']->id,
        ]))->assertOk();
        $this->assertNotEmpty($schedule->viewData('slots'));
        $this->assertTrue($schedule->viewData('slots')->every(fn (AppointmentSlot $slot) => $slot->doctor_profile_id === $me['profile']->id));
        $this->assertSame($me['profile']->id, $schedule->viewData('profile')->id);
        $schedule->assertSee($me['patient']->name)->assertDontSee($them['patient']->name);

        $dashboard = $this->actingAs($me['doctor'])->get(route('doctor.dashboard'))->assertOk();
        $this->assertSame(['total' => 2, 'booked' => 1, 'confirmed' => 1, 'cancelled' => 0], $dashboard->viewData('appointmentCounts'));
        $this->assertSame(1, $dashboard->viewData('pendingCount'));
        foreach (['appointments', 'pendingAppointments'] as $list) {
            $this->assertTrue($dashboard->viewData($list)->every(fn (Appointment $appointment) => $appointment->slot->doctor_profile_id === $me['profile']->id));
        }
        $dashboard->assertDontSee($them['patient']->name);

        $settings = $this->actingAs($me['doctor'])->get(route('doctor.settings'))->assertOk();
        $settings->assertSee($me['profile']->googleCalendarConnection->google_account_email);
        $settings->assertDontSee($them['profile']->googleCalendarConnection->google_account_email);
    }

    #[DataProvider('directions')]
    public function test_another_doctors_availability_and_slots_cannot_be_changed(string $self, string $other): void
    {
        $me = $this->workspaces[$self];
        $them = $this->workspaces[$other];
        $doctor = $me['doctor'];
        $theirAvailability = $them['availability'];

        $this->actingAs($doctor)->patch(route('doctor.availability.update', $theirAvailability), [
            'day_of_week' => 3, 'start_time' => '06:00', 'end_time' => '07:00', 'is_active' => 1,
        ])->assertForbidden();
        $this->actingAs($doctor)->delete(route('doctor.availability.destroy', $theirAvailability))->assertForbidden();
        $this->assertModelExists($theirAvailability);
        $this->assertSame(1, $theirAvailability->fresh()->day_of_week);

        // A forged doctor_profile_id is ignored: the availability is created for the signed-in doctor.
        $this->actingAs($doctor)->post(route('doctor.availability.store'), [
            'doctor_profile_id' => $them['profile']->id, 'doctor_location_id' => $this->locationFor($me['profile'])->id, 'day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '09:00', 'is_active' => 1,
        ])->assertRedirect();
        $this->assertSame(1, $them['profile']->availabilities()->count());
        $this->assertSame(2, $me['profile']->availabilities()->count());

        $theirSlots = $them['profile']->appointmentSlots()->count();
        $this->actingAs($doctor)->post(route('doctor.slots.generate'), [
            'doctor_profile_id' => $them['profile']->id, 'start_date' => '2026-11-16', 'end_date' => '2026-11-30', 'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        ])->assertSessionHasNoErrors();
        $this->assertSame($theirSlots, $them['profile']->appointmentSlots()->count());

        $preview = $this->actingAs($doctor)->postJson(route('doctor.slots.preview'), [
            'doctor_profile_id' => $them['profile']->id, 'start_date' => '2026-11-16', 'end_date' => '2026-11-22', 'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        ])->assertOk();
        // Only the signed-in doctor's own availability is previewed: the Monday hour plus the Friday hour created
        // above (6 slots each, all generated above). The other doctor's Monday hour would add 6 more.
        $this->assertSame(12, $preview->json('total'));
        $this->assertSame(12, $preview->json('existing'));
        $ownMondayHour = $self === 'A' ? 9 : 14;
        $this->assertTrue(collect($preview->json('slots'))->every(function (array $slot) use ($ownMondayHour): bool {
            $startsAt = CarbonImmutable::parse($slot['starts_at']);

            return match ($startsAt->dayOfWeekIso) {
                1 => $startsAt->hour === $ownMondayHour,
                5 => $startsAt->hour === 8,
                default => false,
            };
        }), 'Every previewed slot comes from the signed-in doctor\'s own availability.');
    }

    #[DataProvider('directions')]
    public function test_patient_lookup_and_manual_booking_cannot_reach_another_doctors_patients_or_slots(string $self, string $other): void
    {
        $me = $this->workspaces[$self];
        $them = $this->workspaces[$other];
        $doctor = $me['doctor'];

        $this->actingAs($doctor)->getJson(route('doctor.patients.search', ['search' => $them['patient']->name]))->assertOk()->assertExactJson(['data' => []]);
        $this->actingAs($doctor)->getJson(route('doctor.patients.search', ['search' => $them['patient']->email]))->assertOk()->assertExactJson(['data' => []]);
        $this->actingAs($doctor)->getJson(route('doctor.patients.search', ['search' => $me['patient']->name]))->assertOk()->assertJsonPath('data.0.id', $me['patient']->id);

        $this->actingAs($doctor)->post(route('doctor.appointments.store'), [
            'patient_id' => $them['patient']->id, 'slot_id' => $me['freeSlot']->id, 'consultation_type' => 'physical',
        ])->assertSessionHasErrors('patient_id');
        $this->actingAs($doctor)->post(route('doctor.appointments.store'), [
            'patient_id' => $me['patient']->id, 'slot_id' => $them['freeSlot']->id, 'consultation_type' => 'physical',
        ])->assertSessionHasErrors('slot');

        $this->assertSame('available', $them['freeSlot']->fresh()->status);
        $this->assertSame(4, Appointment::count());
    }

    #[DataProvider('directions')]
    public function test_doctor_cannot_edit_or_manage_another_doctors_profile_or_account(string $self, string $other): void
    {
        $me = $this->workspaces[$self];
        $them = $this->workspaces[$other];
        $doctor = $me['doctor'];

        $this->actingAs($doctor)->put(route('doctor.profile.update'), [
            'doctor_profile_id' => $them['profile']->id,
            'user_id' => $them['doctor']->id,
            'professional_title' => 'Overwritten',
            'license_number' => $me['profile']->license_number,
            'consultation_fee' => 1,
        ])->assertRedirect();
        $this->assertSame('Physician '.$other, $them['profile']->fresh()->professional_title);
        $this->assertSame('Overwritten', $me['profile']->fresh()->professional_title);

        $this->actingAs($doctor)->put(route('doctor.account.update'), [
            'name' => 'Renamed', 'email' => $them['doctor']->email,
        ])->assertSessionHasErrors('email');
        $this->assertNotSame('Renamed', $them['doctor']->fresh()->name);

        $this->actingAs($doctor)->get(route('admin.doctors.show', $them['profile']))->assertForbidden();
        $this->actingAs($doctor)->patch(route('admin.doctors.update', $them['profile']), ['name' => 'X'])->assertForbidden();
        $this->actingAs($doctor)->patch(route('admin.doctors.status', $them['profile']), ['action' => 'deactivate'])->assertForbidden();
        $this->assertNull($them['doctor']->fresh()->deactivated_at);
        $this->assertFalse($doctor->can('view', $them['profile']));
        $this->assertFalse($doctor->can('update', $them['profile']));
    }

    #[DataProvider('directions')]
    public function test_doctor_cannot_browse_the_directory_or_another_doctors_public_profile(string $self, string $other): void
    {
        $me = $this->workspaces[$self];
        $them = $this->workspaces[$other];
        $doctor = $me['doctor'];

        $this->actingAs($doctor)->get(route('doctors.show', $them['profile']))->assertForbidden()->assertDontSee($them['doctor']->name);
        $this->actingAs($doctor)->get(route('doctors.show', $me['profile']))->assertForbidden();
        $this->actingAs($doctor)->get(route('doctors.index'))->assertForbidden()->assertDontSee($them['doctor']->name);
        $this->actingAs($doctor)->getJson(route('doctors.index'))->assertForbidden();
        $this->actingAs($doctor)->get(route('doctors.index', ['search' => $them['doctor']->name]))->assertForbidden();
        $this->actingAs($doctor)->get(route('doctors.index', ['specialty' => 1, 'filter' => 'fee', 'sort' => 'experience']))->assertForbidden();

        // Public information about the other doctor is still shown to patients, without private workspace data.
        $profilePage = $this->actingAs($me['patient'])->get(route('doctors.show', $them['profile']))->assertOk();
        $profilePage->assertSee($them['doctor']->name)->assertSee($them['profile']->professional_title);
        $profilePage->assertDontSee($them['patient']->name);
        $profilePage->assertDontSee($them['profile']->license_number);
        $profilePage->assertDontSee($them['profile']->googleCalendarConnection->google_account_email);
    }

    public function test_admin_has_platform_wide_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $appointments = $this->actingAs($admin)->get(route('admin.appointments.index'))->assertOk();
        $doctors = $this->actingAs($admin)->get(route('admin.doctors.index'))->assertOk();

        foreach ($this->workspaces as $workspace) {
            $appointments->assertSee($workspace['patient']->name);
            $doctors->assertSee($workspace['doctor']->name);
            $this->actingAs($admin)->get(route('admin.appointments.show', $workspace['booked']))->assertOk();
            $this->actingAs($admin)->get(route('admin.doctors.show', $workspace['profile']))->assertOk();
        }
    }

    public function test_patients_can_browse_doctors_but_not_reach_private_doctor_or_other_patient_data(): void
    {
        $patientA = $this->workspaces['A']['patient'];
        $directory = $this->actingAs($patientA)->get(route('doctors.index'))->assertOk();

        foreach ($this->workspaces as $workspace) {
            $directory->assertSee($workspace['doctor']->name);
            $this->actingAs($patientA)->get(route('doctors.show', $workspace['profile']))->assertOk()->assertDontSee($workspace['profile']->license_number);
        }
        $directory->assertDontSee($this->workspaces['B']['patient']->name);

        $doctorRoutes = [
            route('doctor.dashboard'),
            route('doctor.schedule'),
            route('doctor.appointments.index'),
            route('doctor.appointments.show', $this->workspaces['B']['booked']),
            route('doctor.patients.search', ['search' => 'Patient']),
            route('admin.doctors.index'),
        ];
        foreach ($doctorRoutes as $url) {
            $this->actingAs($patientA)->get($url)->assertForbidden();
        }

        $this->actingAs($patientA)->get(route('patient.appointments.show', $this->workspaces['A']['booked']))->assertOk();
        $this->actingAs($patientA)->get(route('patient.appointments.show', $this->workspaces['B']['booked']))->assertForbidden();
        $this->actingAs($patientA)->get(route('patient.appointments.index'))->assertOk()->assertDontSee($this->workspaces['B']['doctor']->name);
    }

    /**
     * @return array{doctor: User, profile: DoctorProfile, patient: User, booked: Appointment, confirmed: Appointment, freeSlot: AppointmentSlot, availability: DoctorAvailability}
     */
    private function workspace(string $label, int $hour): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => "Doctor {$label} Isolation"]);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician '.$label,
            'license_number' => 'PRIVATE-LICENSE-'.$label,
            'consultation_fee' => 50,
        ]);
        GoogleCalendarConnection::create([
            'doctor_profile_id' => $profile->id,
            'google_account_email' => strtolower("calendar-{$label}@private.example"),
            'access_token' => 'token',
            'refresh_token' => 'refresh',
        ]);
        $availability = DoctorAvailability::create([
            'doctor_profile_id' => $profile->id, 'day_of_week' => 1, 'start_time' => sprintf('%02d:00', $hour), 'end_time' => sprintf('%02d:00', $hour + 1),
            'slot_duration_minutes' => 10, 'is_active' => true,
        ]);
        $patient = User::factory()->create(['role' => 'patient', 'name' => "Patient Of {$label}", 'email' => strtolower("patient-{$label}@example.com")]);

        $slot = fn (int $day, int $minute, string $status) => AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => CarbonImmutable::create(2026, 11, $day, $hour, $minute, 0, config('app.timezone')),
            'ends_at' => CarbonImmutable::create(2026, 11, $day, $hour, $minute + 10, 0, config('app.timezone')),
            'status' => $status,
        ]);
        $appointment = fn (AppointmentSlot $slot, string $status) => Appointment::create([
            'appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => $status, 'consultation_type' => 'physical',
        ]);

        return [
            'doctor' => $doctor->load('doctorProfile'),
            'profile' => $profile->load('googleCalendarConnection'),
            'patient' => $patient,
            'booked' => $appointment($slot(18, 0, 'booked'), 'booked'),
            'confirmed' => $appointment($slot(18, 10, 'booked'), 'confirmed'),
            'freeSlot' => $slot(19, 0, 'available'),
            'availability' => $availability,
        ];
    }
}
