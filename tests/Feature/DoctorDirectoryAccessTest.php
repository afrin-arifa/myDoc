<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use App\Services\DoctorPhotoStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The doctor directory and public doctor profiles are for guests, patients and administrators only.
 */
class DoctorDirectoryAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $listedDoctor;

    private DoctorProfile $listedProfile;

    private Specialty $specialty;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DoctorPhotoStorage::DISK);
        $this->specialty = Specialty::create(['name' => 'Cardiology', 'is_active' => true]);
        [$this->listedDoctor, $this->listedProfile] = $this->doctor('Dr. Listed Cardiologist');
        $this->listedProfile->specialties()->attach($this->specialty);
        $this->withPhoto($this->listedProfile);
    }

    public function test_patients_admins_and_guests_can_browse_search_filter_and_view_profiles(): void
    {
        $viewers = [
            'patient' => User::factory()->create(['role' => 'patient']),
            'admin' => User::factory()->create(['role' => 'admin']),
            'guest' => null,
        ];

        foreach ($viewers as $label => $viewer) {
            $this->app['auth']->forgetGuards();
            $as = fn () => $viewer ? $this->actingAs($viewer) : $this;

            $as()->get(route('doctors.index'))->assertOk()->assertSee('Dr. Listed Cardiologist');
            $as()->get(route('doctors.index', ['search' => 'Cardio']))->assertOk()->assertSee('Dr. Listed Cardiologist');
            $as()->get(route('doctors.index', ['specialty' => $this->specialty->id, 'filter' => 'fee']))->assertOk()->assertSee('Dr. Listed Cardiologist');
            $as()->get(route('doctors.show', $this->listedProfile))->assertOk()->assertSee('Dr. Listed Cardiologist');
            $as()->get(route('doctors.photo', $this->listedProfile))->assertOk();
            $as()->get(route('home'))->assertOk()->assertSee('Dr. Listed Cardiologist')->assertSee(route('doctors.index'));
        }
    }

    public function test_doctors_cannot_reach_the_directory_by_any_url_parameter_or_format(): void
    {
        [$doctor, $profile] = $this->doctor('Dr. Viewer');

        $requests = [
            route('doctors.index'),
            route('doctors.index', ['search' => 'Listed']),
            route('doctors.index', ['specialty' => $this->specialty->id]),
            route('doctors.index', ['filter' => 'today']),
            route('doctors.index', ['sort' => 'fee', 'page' => 2]),
            route('doctors.show', $this->listedProfile),
            route('doctors.show', $profile),
            route('doctors.show', ['doctor' => $this->listedProfile->id, 'preview' => 1]),
        ];
        foreach ($requests as $url) {
            $this->actingAs($doctor)->get($url)->assertForbidden()->assertDontSee('Dr. Listed Cardiologist');
            $this->actingAs($doctor)->getJson($url)->assertForbidden();
        }

        $this->actingAs($doctor)->get(route('doctors.photo', $this->listedProfile))->assertNotFound();

        $deactivatedDoctor = $this->doctor('Dr. Inactive')[0];
        $deactivatedDoctor->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($deactivatedDoctor->fresh())->get(route('doctors.index'))->assertForbidden();
    }

    public function test_doctor_home_page_and_navigation_contain_no_directory_data(): void
    {
        [$doctor] = $this->doctor('Dr. Viewer');

        $home = $this->actingAs($doctor)->get(route('home'))->assertOk();
        $home->assertDontSee('Dr. Listed Cardiologist')
            ->assertDontSee('Cardiology')
            ->assertDontSee(route('doctors.index'))
            ->assertSee(route('doctor.dashboard'));
        $this->assertCount(0, $home->viewData('featuredDoctors'));
        $this->assertCount(0, $home->viewData('featuredSpecialties'));

        foreach ([route('doctor.dashboard'), route('doctor.appointments.index'), route('doctor.profile')] as $page) {
            $this->actingAs($doctor)->get($page)->assertOk()->assertDontSee(route('doctors.index'));
        }

        $this->actingAs(User::factory()->create(['role' => 'patient']))->get(route('patient.dashboard'))->assertSee(route('doctors.index'));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.dashboard'))->assertSee(route('doctors.index'));
    }

    public function test_doctor_can_still_view_and_edit_their_own_profile_and_photo(): void
    {
        [$doctor, $profile] = $this->doctor('Dr. Self');
        $this->withPhoto($profile);
        $doctor = $doctor->fresh();

        $this->actingAs($doctor)->get(route('doctor.profile'))->assertOk()->assertSee($profile->fresh()->photoUrl());
        $this->actingAs($doctor)->get(route('doctors.photo', $profile))->assertOk();

        $this->actingAs($doctor)->put(route('doctor.profile.update'), [
            'professional_title' => 'Updated Title',
            'license_number' => $profile->license_number,
            'consultation_fee' => 99,
            'is_accepting_patients' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Updated Title', $profile->fresh()->professional_title);
        $this->assertTrue($doctor->can('update', $profile));
        $this->assertFalse($doctor->can('browseDirectory', DoctorProfile::class));
    }

    public function test_patient_booking_from_the_directory_still_works(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $this->listedProfile->id,
            'starts_at' => now()->startOfHour()->addDays(2),
            'ends_at' => now()->startOfHour()->addDays(2)->addMinutes(10),
            'status' => 'available',
        ]);

        $this->actingAs($patient)->get(route('doctors.show', $this->listedProfile))->assertOk()->assertSee(route('appointment-slots.confirm', $slot));
        $this->actingAs($patient)->get(route('appointment-slots.confirm', $slot))->assertOk()->assertSee(route('doctors.show', $this->listedProfile));
        $this->actingAs($patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])->assertRedirect(route('patient.appointments.index'));

        $appointment = Appointment::firstOrFail();
        $this->assertSame('booked', $appointment->status);
        $this->actingAs($this->listedDoctor)->get(route('doctor.appointments.show', $appointment))->assertOk();
        $this->actingAs($this->listedDoctor)->post(route('doctor.appointments.confirm', $appointment))->assertRedirect();
        $this->assertSame('confirmed', $appointment->fresh()->status);
    }

    /**
     * @return array{User, DoctorProfile}
     */
    private function doctor(string $name): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name]);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Consultant',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 10,
        ]);

        return [$doctor->load('doctorProfile'), $profile];
    }

    private function withPhoto(DoctorProfile $profile): void
    {
        $path = DoctorPhotoStorage::DIRECTORY.'/photo-'.$profile->id.'.png';
        Storage::disk(DoctorPhotoStorage::DISK)->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $profile->forceFill(['photo_path' => $path])->save();
    }
}
