<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Policies\AppointmentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AppointmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private int $slotOffset = 0;

    public function test_policy_is_discovered_for_the_appointment_model(): void
    {
        $this->assertInstanceOf(AppointmentPolicy::class, Gate::getPolicyFor(Appointment::class));
    }

    public function test_patient_can_view_only_their_own_appointment_without_leaking_others(): void
    {
        [$doctor, $patientA] = [$this->doctor(), $this->patient('Alex Owner')];
        $patientB = $this->patient('Blair Private');
        $own = $this->appointment($doctor, $patientA);
        $others = $this->appointment($doctor, $patientB, notes: 'Sensitive symptom note');

        $this->actingAs($patientA)->get(route('patient.appointments.show', $own))->assertOk();

        $this->actingAs($patientA)
            ->get(route('patient.appointments.show', $others))
            ->assertForbidden()
            ->assertDontSee('Blair Private')
            ->assertDontSee('Sensitive symptom note');
    }

    public function test_patient_cannot_cancel_another_patients_appointment(): void
    {
        $doctor = $this->doctor();
        $other = $this->appointment($doctor, $this->patient(), daysAhead: 5);

        $this->actingAs($this->patient())
            ->post(route('appointments.cancel', $other))
            ->assertForbidden();

        $this->assertSame('booked', $other->fresh()->status);
        $this->assertSame('booked', $other->slot->fresh()->status);
    }

    public function test_patient_cannot_use_doctor_or_admin_appointment_operations(): void
    {
        $patient = $this->patient();
        $appointment = $this->appointment($this->doctor(), $patient);

        foreach ($this->doctorActionRequests($appointment) as [$method, $url]) {
            $this->actingAs($patient)->call($method, $url)->assertForbidden();
        }
        $this->actingAs($patient)->get(route('admin.appointments.show', $appointment))->assertForbidden();

        $this->assertSame('booked', $appointment->fresh()->status);
    }

    public function test_guests_are_redirected_from_every_protected_appointment_operation(): void
    {
        $appointment = $this->appointment($this->doctor(), $this->patient());
        $requests = [
            ...$this->doctorActionRequests($appointment),
            ['GET', route('patient.appointments.index')],
            ['GET', route('patient.appointments.show', $appointment)],
            ['POST', route('appointments.cancel', $appointment)],
            ['POST', route('appointment-slots.book', $appointment->slot)],
            ['GET', route('admin.appointments.index')],
            ['GET', route('admin.appointments.show', $appointment)],
        ];

        foreach ($requests as [$method, $url]) {
            $this->call($method, $url)->assertRedirect(route('login'));
        }

        $this->assertSame('booked', $appointment->fresh()->status);
    }

    public function test_doctor_can_view_only_their_own_appointments(): void
    {
        [$doctorA, $doctorB] = [$this->doctor(), $this->doctor()];
        $own = $this->appointment($doctorA, $this->patient());
        $others = $this->appointment($doctorB, $this->patient('Private Patient'));

        $this->actingAs($doctorA)->get(route('doctor.appointments.show', $own))->assertOk();
        $this->actingAs($doctorA)
            ->get(route('doctor.appointments.show', $others))
            ->assertForbidden()
            ->assertDontSee('Private Patient');
        $this->actingAs($doctorA)->get(route('doctor.appointments.reschedule', $others))->assertForbidden();
    }

    public function test_doctor_cannot_confirm_reject_reschedule_complete_or_start_another_doctors_appointment(): void
    {
        Queue::fake();
        [$doctorA, $doctorB] = [$this->doctor(), $this->doctor()];
        $booked = $this->appointment($doctorB, $this->patient());
        $confirmed = $this->appointment($doctorB, $this->patient(), status: 'confirmed');
        $doctorASlot = $this->slot($doctorA);
        $doctorBSlot = $this->slot($doctorB);

        $this->actingAs($doctorA)->post(route('doctor.appointments.confirm', $booked))->assertForbidden();
        $this->actingAs($doctorA)->post(route('doctor.appointments.reject', $booked))->assertForbidden();
        $this->actingAs($doctorA)->post(route('doctor.appointments.complete', $confirmed))->assertForbidden();
        $this->actingAs($doctorA)->post(route('doctor.appointments.start', $confirmed))->assertForbidden();
        foreach ([$doctorASlot, $doctorBSlot] as $targetSlot) {
            $this->actingAs($doctorA)
                ->post(route('doctor.appointments.reschedule.store', $confirmed), ['slot_id' => $targetSlot->id])
                ->assertForbidden();
        }

        $this->assertSame('booked', $booked->fresh()->status);
        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame($confirmed->appointment_slot_id, $confirmed->fresh()->appointment_slot_id);
        $this->assertSame('available', $doctorASlot->fresh()->status);
        $this->assertSame('available', $doctorBSlot->fresh()->status);
        $this->assertDatabaseCount('appointment_reschedule_histories', 0);
        Queue::assertNothingPushed();
    }

    public function test_doctor_cannot_manually_book_into_another_doctors_schedule(): void
    {
        [$doctorA, $doctorB] = [$this->doctor(), $this->doctor()];
        $patient = $this->patient();
        $doctorBSlot = $this->slot($doctorB);

        $this->actingAs($doctorA)
            ->post(route('doctor.appointments.store'), [
                'patient_email' => $patient->email,
                'slot_id' => $doctorBSlot->id,
                'consultation_type' => 'physical',
            ])
            ->assertSessionHasErrors('slot');

        $this->assertSame('available', $doctorBSlot->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_doctor_without_a_profile_cannot_open_manual_booking(): void
    {
        $doctorWithoutProfile = User::factory()->create(['role' => 'doctor']);

        $this->actingAs($doctorWithoutProfile)->get(route('doctor.appointments.create'))->assertForbidden();
        $this->actingAs($doctorWithoutProfile)
            ->post(route('doctor.appointments.store'), ['patient_email' => 'x@example.com', 'slot_id' => 1, 'consultation_type' => 'physical'])
            ->assertForbidden();
    }

    public function test_admin_can_view_the_directory_and_any_appointment_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $appointment = $this->appointment($this->doctor(), $this->patient('Visible Patient'));

        $this->actingAs($admin)->get(route('admin.appointments.index'))->assertOk()->assertSee('Visible Patient');
        $this->actingAs($admin)->get(route('admin.appointments.show', $appointment))->assertOk()->assertSee('Visible Patient');
    }

    public function test_admin_remains_unable_to_modify_or_create_appointments(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $appointment = $this->appointment($this->doctor(), $this->patient());

        foreach (['cancel', 'confirm', 'reject', 'reschedule', 'complete', 'start'] as $ability) {
            $this->assertFalse($admin->can($ability, $appointment), "Admin must not be able to {$ability}.");
        }
        $this->assertFalse($admin->can('create', Appointment::class));

        foreach ($this->doctorActionRequests($appointment) as [$method, $url]) {
            $this->actingAs($admin)->call($method, $url)->assertForbidden();
        }
        $this->actingAs($admin)->post(route('appointments.cancel', $appointment))->assertForbidden();

        $this->assertSame('booked', $appointment->fresh()->status);
    }

    public function test_policy_abilities_match_each_role_and_deny_deactivated_accounts(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient();
        $appointment = $this->appointment($doctor, $patient);
        $stranger = $this->patient();

        $this->assertTrue($patient->can('view', $appointment));
        $this->assertTrue($patient->can('cancel', $appointment));
        $this->assertTrue($patient->can('create', Appointment::class));
        $this->assertFalse($stranger->can('view', $appointment));
        foreach (['confirm', 'reject', 'reschedule', 'complete', 'start'] as $ability) {
            $this->assertFalse($patient->can($ability, $appointment));
            $this->assertTrue($doctor->can($ability, $appointment));
        }
        $this->assertTrue($doctor->can('view', $appointment));
        $this->assertFalse($doctor->can('cancel', $appointment));

        $patient->forceFill(['deactivated_at' => now()])->save();
        $doctor->forceFill(['deactivated_at' => now()])->save();
        $this->assertFalse($patient->fresh()->can('view', $appointment));
        $this->assertFalse($patient->fresh()->can('cancel', $appointment));
        $this->assertFalse($doctor->fresh()->can('confirm', $appointment));
        $this->assertFalse($doctor->fresh()->can('viewAny', Appointment::class));
    }

    public function test_authorized_users_still_face_status_deadline_and_slot_business_rules(): void
    {
        Queue::fake();
        $doctor = $this->doctor();
        $patient = $this->patient();

        $confirmed = $this->appointment($doctor, $patient, status: 'confirmed');
        $this->actingAs($doctor)->post(route('doctor.appointments.confirm', $confirmed))->assertSessionHasErrors('status');
        $this->actingAs($doctor)->post(route('doctor.appointments.reject', $confirmed))->assertSessionHasErrors('status');

        $booked = $this->appointment($doctor, $patient);
        $this->actingAs($doctor)->post(route('doctor.appointments.complete', $booked))->assertSessionHasErrors('status');

        $soon = $this->appointment($doctor, $patient, daysAhead: 0, hoursAhead: 2);
        $this->actingAs($patient)->post(route('appointments.cancel', $soon))->assertSessionHasErrors('appointment');
        $this->assertSame('booked', $soon->fresh()->status);

        $taken = $this->appointment($doctor, $this->patient())->slot;
        $this->actingAs($doctor)
            ->post(route('doctor.appointments.reschedule.store', $confirmed), ['slot_id' => $taken->id])
            ->assertSessionHasErrors('slot_id');
        $this->assertSame($confirmed->appointment_slot_id, $confirmed->fresh()->appointment_slot_id);

        $cancelled = $this->appointment($doctor, $patient, status: 'cancelled');
        $this->actingAs($doctor)
            ->post(route('doctor.appointments.reschedule.store', $cancelled), ['slot_id' => $this->slot($doctor)->id])
            ->assertSessionHasErrors('status');

        Queue::assertNothingPushed();
    }

    public function test_google_calendar_dispatch_behaviour_is_unchanged_for_authorized_actions(): void
    {
        Queue::fake();
        $doctor = $this->doctor();
        $toConfirm = $this->appointment($doctor, $this->patient());
        $toReject = $this->appointment($doctor, $this->patient());
        $toComplete = $this->appointment($doctor, $this->patient(), status: 'confirmed');
        $toReschedule = $this->appointment($doctor, $this->patient(), status: 'confirmed');
        $patient = $this->patient();
        $toCancel = $this->appointment($doctor, $patient, daysAhead: 5);

        $this->actingAs($doctor)->post(route('doctor.appointments.confirm', $toConfirm))->assertRedirect();
        $this->actingAs($doctor)->post(route('doctor.appointments.reject', $toReject))->assertRedirect();
        $this->actingAs($doctor)->post(route('doctor.appointments.complete', $toComplete))->assertRedirect();
        $this->actingAs($doctor)
            ->post(route('doctor.appointments.reschedule.store', $toReschedule), ['slot_id' => $this->slot($doctor)->id])
            ->assertRedirect(route('doctor.appointments.show', $toReschedule));
        $this->actingAs($patient)->post(route('appointments.cancel', $toCancel))->assertRedirect();

        $pushed = fn (Appointment $appointment, string $operation) => Queue::pushed(
            SyncAppointmentToGoogleCalendar::class,
            fn ($job) => $job->appointmentId === $appointment->id && $job->operation === $operation
        )->count();
        $this->assertSame(1, $pushed($toConfirm, 'sync'));
        $this->assertSame(1, $pushed($toReject, 'delete'));
        $this->assertSame(1, $pushed($toReschedule, 'sync'));
        $this->assertSame(1, $pushed($toCancel, 'delete'));
        $this->assertSame(0, $pushed($toComplete, 'sync') + $pushed($toComplete, 'delete'));
        Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, 4);
    }

    /**
     * @return list<array{string, string}>
     */
    private function doctorActionRequests(Appointment $appointment): array
    {
        return [
            ['GET', route('doctor.appointments.index')],
            ['GET', route('doctor.appointments.create')],
            ['POST', route('doctor.appointments.store')],
            ['GET', route('doctor.appointments.show', $appointment)],
            ['POST', route('doctor.appointments.confirm', $appointment)],
            ['POST', route('doctor.appointments.reject', $appointment)],
            ['POST', route('doctor.appointments.start', $appointment)],
            ['POST', route('doctor.appointments.complete', $appointment)],
            ['GET', route('doctor.appointments.reschedule', $appointment)],
            ['POST', route('doctor.appointments.reschedule.store', $appointment)],
        ];
    }

    private function appointment(
        User $doctor,
        User $patient,
        string $status = 'booked',
        int $daysAhead = 2,
        int $hoursAhead = 0,
        ?string $notes = null
    ): Appointment {
        $slot = $this->slot($doctor, $daysAhead, $hoursAhead, in_array($status, ['cancelled', 'rejected'], true) ? 'available' : 'booked');

        return Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => $status,
            'consultation_type' => 'physical',
            'patient_notes' => $notes,
        ]);
    }

    private function slot(User $doctor, int $daysAhead = 3, int $hoursAhead = 0, string $status = 'available'): AppointmentSlot
    {
        $startsAt = now()->startOfHour()->addDays($daysAhead)->addHours($hoursAhead)->addMinutes(10 * $this->slotOffset++);

        return AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => $status,
        ]);
    }

    private function patient(?string $name = null): User
    {
        return User::factory()->create(['role' => 'patient', ...($name ? ['name' => $name] : [])]);
    }

    private function doctor(): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);

        return $doctor->load('doctorProfile');
    }
}
