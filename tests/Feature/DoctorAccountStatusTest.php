<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\DoctorOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DoctorAccountStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_deactivate_and_reactivate_a_doctor_without_losing_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$doctor, $appointment] = $this->doctorWithAppointment();
        $profile = $doctor->doctorProfile;
        GoogleCalendarConnection::create(['doctor_profile_id' => $profile->id, 'access_token' => 'token', 'refresh_token' => 'refresh']);
        $availability = DoctorAvailability::create(['doctor_profile_id' => $profile->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration_minutes' => 10, 'is_active' => true]);

        $this->actingAs($admin)->patch(route('admin.doctors.status', $profile), ['action' => 'deactivate'])->assertSessionHas('status');

        $this->assertNotNull($doctor->fresh()->deactivated_at);
        $this->assertSame(DoctorOnboardingService::STATUS_DEACTIVATED, app(DoctorOnboardingService::class)->statusFor($doctor->fresh()));
        $this->assertModelExists($appointment);
        $this->assertModelExists($appointment->slot);
        $this->assertModelExists($availability);
        $this->assertDatabaseHas('google_calendar_connections', ['doctor_profile_id' => $profile->id]);
        $this->assertSame('confirmed', $appointment->fresh()->status);

        $this->actingAs($admin)->patch(route('admin.doctors.status', $profile), ['action' => 'activate'])->assertSessionHas('status');
        $this->assertNull($doctor->fresh()->deactivated_at);
        Auth::logout();
        $this->post(route('login'), ['email' => $doctor->email, 'password' => 'password'])->assertRedirect(route('doctor.dashboard'));
    }

    public function test_deactivated_doctor_cannot_log_in_or_use_any_doctor_functionality(): void
    {
        [$doctor, $appointment] = $this->doctorWithAppointment();
        $profile = $doctor->doctorProfile;
        $availability = DoctorAvailability::create(['doctor_profile_id' => $profile->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration_minutes' => 10, 'is_active' => true]);
        $freeSlot = $this->slot($profile, 5);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch(route('admin.doctors.status', $profile), ['action' => 'deactivate']);
        Auth::logout();

        $this->post(route('login'), ['email' => $doctor->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // An already-open session is cut off by the role middleware on every protected route.
        $doctor->refresh();
        $requests = [
            ['GET', route('doctor.dashboard')],
            ['GET', route('doctor.schedule')],
            ['GET', route('doctor.profile')],
            ['GET', route('doctor.settings')],
            ['PUT', route('doctor.profile.update')],
            ['GET', route('doctor.appointments.index')],
            ['GET', route('doctor.appointments.create')],
            ['POST', route('doctor.appointments.store')],
            ['GET', route('doctor.appointments.show', $appointment)],
            ['POST', route('doctor.appointments.confirm', $appointment)],
            ['POST', route('doctor.appointments.reject', $appointment)],
            ['POST', route('doctor.appointments.complete', $appointment)],
            ['POST', route('doctor.appointments.start', $appointment)],
            ['GET', route('doctor.appointments.reschedule', $appointment)],
            ['POST', route('doctor.appointments.reschedule.store', $appointment)],
            ['GET', route('doctor.patients.search', ['search' => 'pat'])],
            ['POST', route('doctor.availability.store')],
            ['PATCH', route('doctor.availability.update', $availability)],
            ['DELETE', route('doctor.availability.destroy', $availability)],
            ['POST', route('doctor.slots.preview')],
            ['POST', route('doctor.slots.generate')],
            ['GET', route('doctor.google-calendar.connect')],
        ];
        foreach ($requests as [$method, $url]) {
            $this->actingAs($doctor)->call($method, $url, ['slot_id' => $freeSlot->id, 'weekdays' => [1]])->assertForbidden();
        }

        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->assertModelExists($availability);
        $this->assertSame('available', $freeSlot->fresh()->status);
        foreach (['view', 'confirm', 'reject', 'reschedule', 'complete', 'start'] as $ability) {
            $this->assertFalse($doctor->can($ability, $appointment));
        }
    }

    public function test_deactivated_doctor_is_hidden_from_patients_but_history_stays_visible(): void
    {
        [$doctor, $appointment] = $this->doctorWithAppointment();
        $profile = $doctor->doctorProfile;
        $freeSlot = $this->slot($profile, 5);
        $patient = $appointment->patient;
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patch(route('admin.doctors.status', $profile), ['action' => 'deactivate']);

        $this->actingAs($patient)->get(route('doctors.index'))->assertOk()->assertDontSee($doctor->name);
        $this->actingAs($patient)->get(route('doctors.show', $profile))->assertNotFound();
        $this->actingAs($patient)->post(route('appointment-slots.book', $freeSlot), ['consultation_type' => 'physical'])->assertSessionHasErrors('slot');
        $this->actingAs($patient)->get(route('patient.appointments.show', $appointment))->assertOk()->assertSee($doctor->name);
        $this->actingAs($admin)->get(route('admin.appointments.show', $appointment))->assertOk();
        $this->actingAs($admin)->get(route('admin.doctors.show', $profile))->assertOk()->assertSee('Deactivated');
    }

    public function test_only_admins_can_change_doctor_account_status(): void
    {
        [$doctor] = $this->doctorWithAppointment();
        $otherDoctor = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient']);

        foreach (['deactivate', 'activate'] as $action) {
            $this->actingAs($doctor)->patch(route('admin.doctors.status', $doctor->doctorProfile), ['action' => $action])->assertForbidden();
            $this->actingAs($doctor)->patch(route('admin.doctors.status', $otherDoctor->doctorProfile), ['action' => $action])->assertForbidden();
            $this->actingAs($patient)->patch(route('admin.doctors.status', $doctor->doctorProfile), ['action' => $action])->assertForbidden();
        }
        Auth::logout();
        $this->patch(route('admin.doctors.status', $doctor->doctorProfile), ['action' => 'deactivate'])->assertRedirect(route('login'));

        $this->assertNull($doctor->fresh()->deactivated_at);
        $this->assertNull($otherDoctor->fresh()->deactivated_at);
    }

    public function test_doctor_cannot_change_status_through_account_or_profile_forms(): void
    {
        [$doctor] = $this->doctorWithAppointment();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch(route('admin.doctors.status', $doctor->doctorProfile), ['action' => 'deactivate']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch(route('admin.doctors.status', $doctor->doctorProfile), ['action' => 'activate']);
        $doctor->refresh();

        $this->actingAs($doctor)->put(route('doctor.account.update'), [
            'name' => 'Renamed', 'email' => $doctor->email, 'role' => 'admin', 'deactivated_at' => now()->toDateTimeString(),
        ])->assertRedirect();

        $this->assertSame('doctor', $doctor->fresh()->role);
        $this->assertNull($doctor->fresh()->deactivated_at);
        $this->assertSame('Renamed', $doctor->fresh()->name);
    }

    public function test_pending_invitation_cannot_be_activated_by_admin_but_can_be_cancelled(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.doctors.store'), [
            'name' => 'Dr. Pending', 'email' => 'pending@example.com', 'professional_title' => 'GP',
            'license_number' => 'LIC-PENDING', 'consultation_fee' => 10,
        ]);
        $pending = User::query()->where('email', 'pending@example.com')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.doctors.status', $pending->doctorProfile), ['action' => 'activate'])->assertSessionHasErrors('status');
        $this->assertNotNull($pending->fresh()->deactivated_at);

        $this->actingAs($admin)->patch(route('admin.doctors.status', $pending->doctorProfile), ['action' => 'deactivate'])->assertSessionHas('status');
        $this->assertDatabaseCount('doctor_invitation_tokens', 0);
        $this->assertSame(DoctorOnboardingService::STATUS_DEACTIVATED, app(DoctorOnboardingService::class)->statusFor($pending->fresh()));
    }

    public function test_admin_can_filter_and_search_doctors_by_status(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $active = $this->doctor('Alice Active');
        $deactivated = $this->doctor('Dora Deactivated');
        $deactivated->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($admin)->post(route('admin.doctors.store'), [
            'name' => 'Pat Pending', 'email' => 'pending@example.com', 'professional_title' => 'GP', 'license_number' => 'LIC-P', 'consultation_fee' => 10,
        ]);
        $this->actingAs($admin)->post(route('admin.doctors.store'), [
            'name' => 'Ezra Expired', 'email' => 'expired@example.com', 'professional_title' => 'GP', 'license_number' => 'LIC-E', 'consultation_fee' => 10,
        ]);
        DB::table('doctor_invitation_tokens')->where('email', 'expired@example.com')->update(['created_at' => now()->subDays(4)]);

        $expectations = [
            'active' => 'Alice Active',
            'deactivated' => 'Dora Deactivated',
            'invitation_pending' => 'Pat Pending',
            'invitation_expired' => 'Ezra Expired',
        ];
        foreach ($expectations as $status => $name) {
            $response = $this->actingAs($admin)->get(route('admin.doctors.index', ['status' => $status]))->assertOk()->assertSee($name);
            foreach (array_diff($expectations, [$name]) as $other) {
                $response->assertDontSee($other);
            }
        }

        $this->actingAs($admin)->get(route('admin.doctors.index', ['search' => 'pending@example.com']))->assertSee('Pat Pending')->assertDontSee('Alice Active');
        $this->actingAs($admin)->get(route('admin.doctors.index', ['status' => 'bogus']))->assertSessionHasErrors('status');
        $this->actingAs($admin)->get(route('admin.doctors.index', ['search' => 'nobody-here']))->assertSee('No doctors found');
    }

    /**
     * @return array{User, Appointment}
     */
    private function doctorWithAppointment(): array
    {
        $doctor = $this->doctor();
        $slot = $this->slot($doctor->doctorProfile, 2, 'booked');
        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => User::factory()->create(['role' => 'patient', 'name' => 'Historic Patient'])->id,
            'status' => 'confirmed',
            'consultation_type' => 'physical',
        ]);

        return [$doctor, $appointment];
    }

    private function slot(DoctorProfile $profile, int $daysAhead, string $status = 'available'): AppointmentSlot
    {
        return AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => now()->startOfHour()->addDays($daysAhead),
            'ends_at' => now()->startOfHour()->addDays($daysAhead)->addMinutes(10),
            'status' => $status,
        ]);
    }

    private function doctor(?string $name = null): User
    {
        $doctor = User::factory()->create(['role' => 'doctor', ...($name ? ['name' => $name] : [])]);
        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);

        return $doctor->load('doctorProfile');
    }
}
