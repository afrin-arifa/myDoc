<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientLookupTest extends TestCase
{
    use RefreshDatabase;

    private int $pastSlotOffset = 0;

    public function test_doctor_can_search_patients_with_previous_appointments(): void
    {
        $doctor = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient', 'name' => 'Jordan Returning', 'phone' => '555-0101']);
        $this->appointmentFor($doctor, $patient);

        foreach (['Jordan', $patient->email, '555-0101'] as $term) {
            $this->actingAs($doctor)
                ->getJson(route('doctor.patients.search', ['search' => $term]))
                ->assertOk()
                ->assertExactJson(['data' => [[
                    'id' => $patient->id,
                    'name' => 'Jordan Returning',
                    'email' => $patient->email,
                ]]]);
        }
    }

    public function test_lookup_never_returns_unrelated_deactivated_or_non_patient_accounts(): void
    {
        $doctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $related = User::factory()->create(['role' => 'patient', 'name' => 'Casey Related']);
        $this->appointmentFor($doctor, $related);
        $otherDoctorsPatient = User::factory()->create(['role' => 'patient', 'name' => 'Casey Elsewhere']);
        $this->appointmentFor($otherDoctor, $otherDoctorsPatient);
        User::factory()->create(['role' => 'patient', 'name' => 'Casey Stranger']);
        $deactivated = User::factory()->create(['role' => 'patient', 'name' => 'Casey Deactivated', 'deactivated_at' => now()]);
        $this->appointmentFor($doctor, $deactivated);
        User::factory()->create(['role' => 'admin', 'name' => 'Casey Admin']);

        $this->actingAs($doctor)
            ->getJson(route('doctor.patients.search', ['search' => 'Casey']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $related->id);
    }

    public function test_lookup_cannot_be_used_to_list_every_patient(): void
    {
        $doctor = $this->doctor();
        foreach (range(1, 15) as $index) {
            $this->appointmentFor($doctor, User::factory()->create(['role' => 'patient', 'name' => "Patient {$index}"]));
        }

        $this->actingAs($doctor)->getJson(route('doctor.patients.search'))->assertJsonValidationErrors('search');
        $this->actingAs($doctor)->getJson(route('doctor.patients.search', ['search' => 'P']))->assertJsonValidationErrors('search');
        $this->actingAs($doctor)
            ->getJson(route('doctor.patients.search', ['search' => 'Patient']))
            ->assertOk()
            ->assertJsonCount(10, 'data');

        $this->actingAs($doctor)
            ->get(route('doctor.appointments.create'))
            ->assertOk()
            ->assertDontSee('Patient 1')
            ->assertSee(route('doctor.patients.search'), false);
    }

    public function test_lookup_is_restricted_to_doctors(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $this->getJson(route('doctor.patients.search', ['search' => 'any']))->assertUnauthorized();
        $this->actingAs($patient)->getJson(route('doctor.patients.search', ['search' => 'any']))->assertForbidden();
    }

    public function test_doctor_can_book_a_related_patient_by_id(): void
    {
        $doctor = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient']);
        $this->appointmentFor($doctor, $patient, 'completed');
        $slot = $this->availableSlot($doctor);

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.store'), $this->bookingPayload($slot, ['patient_id' => $patient->id]))
            ->assertRedirect(route('doctor.schedule'));

        $this->assertDatabaseHas('appointments', ['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => 'booked']);
    }

    public function test_doctor_cannot_book_an_arbitrary_patient_id(): void
    {
        $doctor = $this->doctor();
        $stranger = User::factory()->create(['role' => 'patient']);
        $slot = $this->availableSlot($doctor);

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.store'), $this->bookingPayload($slot, ['patient_id' => $stranger->id]))
            ->assertSessionHasErrors(['patient_id' => 'No eligible patient account was found.']);

        $this->assertSame('available', $slot->fresh()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_invalid_patient_identifiers_are_rejected(): void
    {
        $doctor = $this->doctor();
        $slot = $this->availableSlot($doctor);
        $related = User::factory()->create(['role' => 'patient']);
        $this->appointmentFor($doctor, $related);
        $admin = User::factory()->create(['role' => 'admin']);

        $payloads = [
            [[], 'patient_id'],
            [['patient_id' => 999999], 'patient_id'],
            [['patient_id' => 'abc'], 'patient_id'],
            [['patient_email' => 'not-an-email'], 'patient_email'],
            [['patient_email' => 'nobody@example.com'], 'patient_email'],
            [['patient_email' => $admin->email], 'patient_email'],
            [['patient_id' => $related->id, 'patient_email' => $related->email], 'patient_id'],
        ];

        foreach ($payloads as [$patientFields, $errorKey]) {
            $this->actingAs($doctor)
                ->post(route('doctor.appointments.store'), $this->bookingPayload($slot, $patientFields))
                ->assertSessionHasErrors($errorKey);
        }

        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('available', $slot->fresh()->status);
    }

    public function test_deactivated_patients_cannot_be_booked(): void
    {
        $doctor = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient']);
        $this->appointmentFor($doctor, $patient);
        $patient->forceFill(['deactivated_at' => now()])->save();
        $slot = $this->availableSlot($doctor);

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.store'), $this->bookingPayload($slot, ['patient_id' => $patient->id]))
            ->assertSessionHasErrors('patient_id');
        $this->actingAs($doctor)
            ->post(route('doctor.appointments.store'), $this->bookingPayload($slot, ['patient_email' => $patient->email]))
            ->assertSessionHasErrors('patient_email');

        $this->assertSame('available', $slot->fresh()->status);
        $this->assertDatabaseMissing('appointments', ['appointment_slot_id' => $slot->id]);
    }

    /**
     * @param  array<string, mixed>  $patientFields
     * @return array<string, mixed>
     */
    private function bookingPayload(AppointmentSlot $slot, array $patientFields): array
    {
        return [
            ...$patientFields,
            'slot_id' => $slot->id,
            'consultation_type' => 'physical',
        ];
    }

    private function appointmentFor(User $doctor, User $patient, string $status = 'cancelled'): Appointment
    {
        $startsAt = now()->subDays(2)->startOfHour()->subMinutes(10 * ++$this->pastSlotOffset);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => 'available',
        ]);

        return Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => $status,
            'consultation_type' => 'physical',
        ]);
    }

    private function availableSlot(User $doctor): AppointmentSlot
    {
        return AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => now()->addDay()->startOfMinute(),
            'ends_at' => now()->addDay()->startOfMinute()->addMinutes(10),
            'status' => 'available',
        ]);
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
