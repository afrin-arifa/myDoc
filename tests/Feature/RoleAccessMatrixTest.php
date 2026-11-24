<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7 audit: every role reaches its own area and nothing else, deactivated accounts lose their area on the
 * next request, and a doctor context or record id in the URL never widens access.
 */
class RoleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, User>
     */
    private array $users;

    private DoctorProfile $assignedProfile;

    private DoctorProfile $otherProfile;

    protected function setUp(): void
    {
        parent::setUp();

        [$doctor, $this->assignedProfile] = $this->doctor('Dr. Assigned');
        [, $this->otherProfile] = $this->doctor('Dr. Other');
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->assignedProfile->assistants()->attach($assistant->id);

        $this->users = [
            'patient' => User::factory()->create(['role' => 'patient']),
            'doctor' => $doctor,
            'assistant' => $assistant,
            'admin' => User::factory()->create(['role' => 'admin']),
        ];
    }

    public function test_each_role_opens_only_its_own_area(): void
    {
        foreach ($this->areas() as $area => $urls) {
            foreach ($this->users as $role => $user) {
                foreach ($urls as $url) {
                    $this->flushSession();
                    $this->actingAs($user)->get($url)->assertStatus($role === $area ? 200 : 403);
                }
            }

            $this->app['auth']->forgetGuards();
            $this->flushSession();
            foreach ($urls as $url) {
                $this->get($url)->assertRedirect(route('login'));
            }
        }
    }

    public function test_deactivated_accounts_lose_their_area_on_the_next_request(): void
    {
        foreach ($this->users as $role => $user) {
            $user->forceFill(['deactivated_at' => now()])->save();

            foreach ($this->areas()[$role] as $url) {
                $this->flushSession();
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
    }

    public function test_the_doctor_context_in_the_url_never_widens_access(): void
    {
        $assistant = $this->users['assistant'];
        $otherAppointment = $this->appointment($this->otherProfile);

        $this->actingAs($assistant)->get(route('assistant.workspace.appointments.index', $this->otherProfile))->assertForbidden();
        $this->actingAs($assistant)->get('/assistant/doctors/999999/appointments')->assertNotFound();
        $this->actingAs($assistant)->get('/assistant/doctors/not-a-number/appointments')->assertNotFound();
        $this->actingAs($assistant)->get(route('assistant.workspace.appointments.show', [$this->assignedProfile, $otherAppointment]))->assertForbidden();
        $this->actingAs($assistant)->post(route('assistant.workspace.appointments.cancel', [$this->assignedProfile, $otherAppointment]), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($assistant)->get(route('assistant.workspace.appointments.index', $this->assignedProfile).'?doctor_profile_id='.$this->otherProfile->id)
            ->assertOk()->assertDontSee('Dr. Other');

        $this->flushSession();
        $doctor = $this->users['doctor'];
        $this->actingAs($doctor)->get(route('doctor.appointments.show', $otherAppointment))->assertForbidden();
        $this->actingAs($doctor)->post(route('doctor.appointments.cancel', $otherAppointment), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($doctor)->get(route('assistant.workspace.appointments.index', $this->assignedProfile))->assertForbidden();
        $this->assertSame('booked', $otherAppointment->fresh()->status);

        $this->assignedProfile->assistants()->detach($assistant->id);
        $this->flushSession();
        $this->actingAs($assistant)->get(route('assistant.workspace.appointments.index', $this->assignedProfile))->assertForbidden();
    }

    /**
     * Representative pages of each role's area.
     *
     * @return array<string, list<string>>
     */
    private function areas(): array
    {
        return [
            'patient' => [route('patient.dashboard'), route('patient.appointments.index'), route('patient.documents.index'), route('patient.profile')],
            'doctor' => [route('doctor.dashboard'), route('doctor.appointments.index'), route('doctor.schedule'), route('doctor.patients.index'),
                route('doctor.medical-records.index'), route('doctor.assistants.index'), route('doctor.profile'), route('doctor.settings')],
            'assistant' => [route('assistant.dashboard'), route('assistant.doctors.index'), route('assistant.account'),
                route('assistant.workspace.appointments.index', $this->assignedProfile), route('assistant.workspace.schedule', $this->assignedProfile)],
            'admin' => [route('admin.dashboard'), route('admin.profile'), route('admin.doctors.index'), route('admin.users.index'), route('admin.assistants.index'),
                route('admin.appointments.index'), route('admin.medical-documents.index')],
        ];
    }

    /**
     * @return array{0: User, 1: DoctorProfile}
     */
    private function doctor(string $name): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name]);

        return [$doctor, DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10])];
    }

    private function appointment(DoctorProfile $profile): Appointment
    {
        $startsAt = now()->startOfHour()->addDays(2);
        $slot = AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(10), 'status' => 'booked']);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $this->users['patient']->id, 'status' => 'booked', 'consultation_type' => 'physical']);
    }
}
