<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Services\AppointmentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class WalkInPatientTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctor_books_a_walk_in_with_only_a_name_and_mobile_number(): void
    {
        [$doctor, $profile] = $this->doctor();

        $this->actingAs($doctor)->get(route('doctor.appointments.create'))->assertOk()->assertSee('Walk-in or phone call');
        $this->book($doctor, $this->slot($profile), ['walk_in_name' => '  Karim   Uddin ', 'walk_in_phone' => '017-1234-5678'])->assertSessionHasNoErrors();

        $patient = User::where('is_walk_in', true)->sole();
        $this->assertSame(['Karim Uddin', '8801712345678', null, 'patient', false], [$patient->name, $patient->phone, $patient->email, $patient->role, $patient->sms_notifications_enabled]);
        $appointment = Appointment::sole();
        $this->assertSame([$patient->id, 'booked'], [$appointment->patient_id, $appointment->status]);
        $this->assertTrue(DoctorWorkspaceEvent::where('action', 'booked')->sole()->context['walk_in']);
        $this->assertSame(0, AppointmentNotification::count(), 'There is no email address to write to.');

        foreach ([route('doctor.patients.index'), route('doctor.appointments.show', $appointment), route('doctor.appointments.index'), route('doctor.dashboard')] as $page) {
            $this->get($page)->assertOk()->assertSee('Karim Uddin');
        }
        $this->get(route('doctor.patients.index'))->assertSee('Walk-in');
        $this->getJson(route('doctor.patients.search', ['search' => '1712345']))->assertOk()->assertJsonPath('data.0.email', '8801712345678');
    }

    public function test_an_assistant_can_book_a_walk_in_and_is_recorded_as_the_actor(): void
    {
        [, $profile] = $this->doctor();
        $assistant = User::factory()->create(['role' => User::ROLE_ASSISTANT]);
        $profile->assistants()->attach($assistant->id, ['assigned_by' => $this->admin()->id]);

        $this->actingAs($assistant)->post(route('assistant.workspace.appointments.store', $profile), $this->payload($this->slot($profile), ['walk_in_name' => 'Rahima', 'walk_in_phone' => '01812345678', 'walk_in_sms' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(User::where('is_walk_in', true)->sole()->sms_notifications_enabled);
        $this->assertSame($assistant->id, DoctorWorkspaceEvent::where('action', 'booked')->sole()->actor_id);
    }

    public function test_the_same_person_is_reused_within_a_practice_and_never_across_practices(): void
    {
        [$doctor, $profile] = $this->doctor();
        [$otherDoctor, $otherProfile] = $this->doctor();
        $person = ['walk_in_name' => 'Karim Uddin', 'walk_in_phone' => '01712345678'];

        $this->book($doctor, $this->slot($profile, 10), $person)->assertSessionHasNoErrors();
        $this->book($doctor, $this->slot($profile, 11), ['walk_in_name' => 'karim uddin', 'walk_in_phone' => '+8801712345678'])->assertSessionHasNoErrors();
        $this->assertSame(1, User::where('is_walk_in', true)->count());

        // A family member on the same number is another patient, and another practice keeps its own record.
        $this->book($doctor, $this->slot($profile, 12), ['walk_in_name' => 'Salma Uddin', 'walk_in_phone' => '01712345678'])->assertSessionHasNoErrors();
        $this->book($otherDoctor, $this->slot($otherProfile, 13), $person)->assertSessionHasNoErrors();
        $this->assertSame(3, User::where('is_walk_in', true)->count());
    }

    public function test_a_registered_patient_of_the_doctor_is_not_duplicated_and_strangers_are_not_revealed(): void
    {
        [$doctor, $profile] = $this->doctor();
        $known = User::factory()->create(['role' => 'patient', 'name' => 'Known Person', 'phone' => '8801711111111']);
        User::factory()->create(['role' => 'patient', 'name' => 'Stranger', 'phone' => '8801722222222']);
        Appointment::create(['appointment_slot_id' => $this->slot($profile, 9, 'booked')->id, 'patient_id' => $known->id, 'status' => 'confirmed', 'consultation_type' => 'physical']);

        $this->book($doctor, $this->slot($profile, 10), ['walk_in_name' => 'Known person', 'walk_in_phone' => '01711111111'])->assertSessionHasErrors('walk_in_name');
        $this->assertSame(0, User::where('is_walk_in', true)->count());

        $this->book($doctor, $this->slot($profile, 11), ['walk_in_name' => 'Stranger', 'walk_in_phone' => '01722222222'])->assertSessionHasNoErrors();
        $this->assertSame(1, User::where('is_walk_in', true)->count());
    }

    public function test_invalid_or_refused_bookings_leave_no_patient_behind(): void
    {
        [$doctor, $profile] = $this->doctor();
        $slot = $this->slot($profile);
        $person = ['walk_in_name' => 'Karim', 'walk_in_phone' => '01712345678'];

        foreach ([
            [['walk_in_name' => 'Karim'], 'walk_in_phone'],
            [['walk_in_phone' => '01712345678'], 'walk_in_name'],
            [['walk_in_name' => 'Karim', 'walk_in_phone' => '12345'], 'walk_in_phone'],
            [[...$person, 'patient_email' => 'a@example.com'], 'patient_email'],
            [[...$person, 'patient_id' => 1], 'patient_id'],
            [[...$person, 'follow_up_of_id' => 1], 'walk_in_name'],
            [[], 'patient_id'],
        ] as [$fields, $error]) {
            $this->book($doctor, $slot, $fields)->assertSessionHasErrors($error);
        }

        $taken = $this->slot($profile, 12, 'blocked');
        $this->book($doctor, $taken, $person)->assertSessionHasErrors('slot');
        $this->assertSame(0, User::where('is_walk_in', true)->count());
        $this->assertSame(0, Appointment::count());
    }

    public function test_walk_in_appointments_can_be_confirmed_rescheduled_and_cancelled_without_email(): void
    {
        [$doctor, $profile] = $this->doctor();
        $this->book($doctor, $this->slot($profile, 10), ['walk_in_name' => 'Karim', 'walk_in_phone' => '01712345678', 'walk_in_sms' => 1])->assertSessionHasNoErrors();
        $appointment = Appointment::sole();
        $smsCount = AppointmentNotification::where('channel', 'sms')->count();
        $this->assertSame(1, $smsCount, 'With consent the patient still gets SMS.');

        $this->post(route('doctor.appointments.confirm', $appointment))->assertSessionHasNoErrors();
        $this->post(route('doctor.appointments.reschedule.store', $appointment), ['slot_id' => $this->slot($profile, 11)->id])->assertSessionHasNoErrors();
        $this->post(route('doctor.appointments.cancel', $appointment), ['reason' => 'Clinic closed'])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertSame(0, AppointmentNotification::query()->where('channel', 'email')->get()
            ->filter(fn (AppointmentNotification $row): bool => ($row->context['audience'] ?? null) === 'patient' || in_array($row->type, ['confirmed', 'rescheduled'], true))->count());
    }

    public function test_patients_with_an_email_still_get_their_emails(): void
    {
        [, $profile] = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient']);
        $appointment = Appointment::create(['appointment_slot_id' => $this->slot($profile, 9, 'booked')->id, 'patient_id' => $patient->id, 'status' => 'confirmed', 'consultation_type' => 'physical']);

        DB::transaction(fn () => app(AppointmentNotificationService::class)->record($appointment, AppointmentNotification::TYPE_CONFIRMED));

        $this->assertSame(1, AppointmentNotification::where('channel', 'email')->count());
    }

    public function test_nobody_can_sign_in_until_an_admin_adds_an_email_and_the_patient_claims_the_record(): void
    {
        [$doctor, $profile] = $this->doctor();
        $this->book($doctor, $this->slot($profile), ['walk_in_name' => 'Karim', 'walk_in_phone' => '01712345678'])->assertSessionHasNoErrors();
        $patient = User::where('is_walk_in', true)->sole();
        $admin = $this->admin();
        $details = ['name' => 'Karim', 'phone' => '01712345678', 'role' => 'patient'];
        auth()->logout();
        $this->flushSession();
        $this->post(route('login'), ['email' => '', 'password' => ''])->assertSessionHasErrors('email');

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertSee('Walk-in');
        $this->get(route('admin.users.show', $patient))->assertOk()->assertSee('Walk-in patient without an account');
        $this->patch(route('admin.users.update', $patient), $details)->assertSessionHasNoErrors();
        $this->assertTrue($patient->fresh()->is_walk_in);
        $this->patch(route('admin.users.update', $patient), [...$details, 'role' => 'admin'])->assertSessionHasErrors('email');
        $this->patch(route('admin.users.update', $patient), [...$details, 'email' => 'karim@example.com'])->assertSessionHasNoErrors();
        $this->assertSame([false, 'karim@example.com'], [$patient->fresh()->is_walk_in, $patient->fresh()->email]);
        // An ordinary account still needs its email.
        $this->patch(route('admin.users.update', $patient), $details)->assertSessionHasErrors('email');

        auth()->logout();
        $this->flushSession();
        $token = Password::broker()->createToken($patient->fresh());
        $this->post(route('password.update'), ['token' => $token, 'email' => 'karim@example.com', 'password' => 'Claimed-pass-1', 'password_confirmation' => 'Claimed-pass-1'])->assertSessionHasNoErrors();
        $this->post(route('login'), ['email' => 'karim@example.com', 'password' => 'Claimed-pass-1'])->assertRedirect(route('patient.dashboard'));
        $this->get(route('patient.appointments.index'))->assertOk()->assertSee($doctor->name);
    }

    private function book(User $doctor, AppointmentSlot $slot, array $patient)
    {
        $this->flushSession();

        return $this->actingAs($doctor)->post(route('doctor.appointments.store'), $this->payload($slot, $patient));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AppointmentSlot $slot, array $patient): array
    {
        return ['slot_id' => $slot->id, 'consultation_type' => 'physical', ...$patient];
    }

    /**
     * @return array{0: User, 1: DoctorProfile}
     */
    private function doctor(): array
    {
        $doctor = User::factory()->create(['role' => 'doctor']);

        return [$doctor, DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10])];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function slot(DoctorProfile $profile, int $hour = 10, string $status = 'available'): AppointmentSlot
    {
        $start = now()->addDays(3)->setTime($hour, 0);

        return AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(10), 'status' => $status]);
    }
}
