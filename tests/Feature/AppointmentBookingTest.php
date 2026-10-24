<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_browse_accepting_doctors_and_future_slots(): void
    {
        $doctor = $this->doctorWithProfile();
        $specialty = Specialty::create([
            'name' => 'Family medicine',
            'is_active' => true,
        ]);

        $doctor->doctorProfile->specialties()->attach($specialty);

        AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => 'available',
        ]);

        $this->get(route('doctors.index', [
            'specialty' => $specialty->id,
        ]))
            ->assertOk()
            ->assertSee($doctor->name);

        $this->get(route('doctors.show', $doctor->doctorProfile))
            ->assertOk()
            ->assertSee(
                $doctor->doctorProfile->appointmentSlots
                    ->first()
                    ->starts_at
                    ->format('M j')
            );

        $this->get(route('home'))
            ->assertOk()
            ->assertSee($doctor->name);
    }

    public function test_booking_rechecks_that_the_doctor_accepts_patients(): void
    {
        $doctor = $this->doctorWithProfile();

        $doctor->doctorProfile->update([
            'is_accepting_patients' => false,
        ]);

        $patient = User::factory()->create([
            'role' => 'patient',
        ]);

        $slot = $this->slotFor($doctor);

        $this->actingAs($patient)
            ->post(route('appointment-slots.book', $slot), [
                'consultation_type' => 'physical',
            ])
            ->assertSessionHasErrors('slot');

        $this->assertDatabaseMissing('appointments', [
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
        ]);

        $this->assertSame('available', $slot->fresh()->status);
    }

    public function test_only_patients_can_book_and_booking_claims_the_slot(): void
    {
        $doctor = $this->doctorWithProfile();
        $slot = $this->slotFor($doctor);

        $patient = User::factory()->create([
            'role' => 'patient',
        ]);

        $this->actingAs($doctor)
            ->post(route('appointment-slots.book', $slot))
            ->assertForbidden();

        $this->actingAs($patient)
            ->post(route('appointment-slots.book', $slot), [
                'consultation_type' => 'physical',
            ])
            ->assertRedirect(route('patient.appointments.index'));

        $this->assertDatabaseHas('appointments', [
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => 'booked',
            'consultation_type' => 'physical',
        ]);

        $this->assertSame('booked', $slot->fresh()->status);

        $anotherPatient = User::factory()->create([
            'role' => 'patient',
        ]);

        $this->actingAs($anotherPatient)
            ->post(route('appointment-slots.book', $slot), [
                'consultation_type' => 'physical',
            ])
            ->assertSessionHasErrors('slot');
    }

    public function test_past_and_blocked_slots_cannot_be_booked(): void
    {
        $patient = User::factory()->create([
            'role' => 'patient',
        ]);

        $doctor = $this->doctorWithProfile();

        $past = $this->slotFor(
            $doctor,
            CarbonImmutable::now()->subHour(),
            'available'
        );

        $blocked = $this->slotFor(
            $doctor,
            CarbonImmutable::now()->addDay(),
            'blocked'
        );

        $this->actingAs($patient)
            ->post(route('appointment-slots.book', $past), [
                'consultation_type' => 'physical',
            ])
            ->assertSessionHasErrors('slot');

        $this->actingAs($patient)
            ->post(route('appointment-slots.book', $blocked), [
                'consultation_type' => 'physical',
            ])
            ->assertSessionHasErrors('slot');
    }

    public function test_booking_persists_each_supported_consultation_type_and_rejects_invalid_values(): void
    {
        $doctor = $this->doctorWithProfile();

        $patient = User::factory()->create([
            'role' => 'patient',
        ]);

        foreach (['physical', 'voice', 'video'] as $index => $type) {
            $slot = $this->slotFor(
                $doctor,
                CarbonImmutable::now()->addDays(2)->addHours($index)
            );

            $this->actingAs($patient)
                ->post(route('appointment-slots.book', $slot), [
                    'consultation_type' => $type,
                ])
                ->assertRedirect(route('patient.appointments.index'));

            $this->assertDatabaseHas('appointments', [
                'appointment_slot_id' => $slot->id,
                'consultation_type' => $type,
            ]);
        }

        $invalidTypeSlot = $this->slotFor(
            $doctor,
            CarbonImmutable::now()->addDays(3)
        );

        $this->actingAs($patient)
            ->post(route('appointment-slots.book', $invalidTypeSlot), [
                'consultation_type' => 'teleportation',
            ])
            ->assertSessionHasErrors('consultation_type');
    }

    public function test_patients_can_cancel_only_their_own_eligible_appointments_and_restore_slot(): void
    {
        $doctor = $this->doctorWithProfile();

        $patient = User::factory()->create([
            'role' => 'patient',
        ]);

        $otherPatient = User::factory()->create([
            'role' => 'patient',
        ]);

        $slot = $this->slotFor($doctor);

        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => 'booked',
            'booked_at' => now(),
        ]);

        $slot->update([
            'status' => 'booked',
        ]);

        $this->actingAs($otherPatient)
            ->post(route('appointments.cancel', $appointment))
            ->assertForbidden();

        $this->actingAs($patient)
            ->post(route('appointments.cancel', $appointment))
            ->assertRedirect();

        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertSame('available', $slot->fresh()->status);
    }

    public function test_completed_appointments_cannot_be_cancelled(): void
    {
        $doctor = $this->doctorWithProfile();

        $patient = User::factory()->create([
            'role' => 'patient',
        ]);

        $slot = $this->slotFor($doctor);

        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => 'completed',
            'booked_at' => now(),
        ]);

        $this->actingAs($patient)
            ->post(route('appointments.cancel', $appointment))
            ->assertSessionHasErrors('appointment');

        $this->assertSame('completed', $appointment->fresh()->status);
    }

    public function test_doctors_see_only_appointments_for_their_profile(): void
    {
        $doctor = $this->doctorWithProfile();
        $otherDoctor = $this->doctorWithProfile();

        $patient = User::factory()->create([
            'role' => 'patient',
        ]);

        $this->createAppointment($doctor, $patient);

        $otherAppointment = $this->createAppointment(
            $otherDoctor,
            $patient
        );

        $this->actingAs($doctor)
            ->get(route('doctor.appointments.index'))
            ->assertOk()
            ->assertDontSee(
                $otherAppointment->slot->starts_at->format('Y-m-d H:i')
            );
    }

    private function doctorWithProfile(): User
    {
        $doctor = User::factory()->create([
            'role' => 'doctor',
        ]);

        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'bio' => 'Primary care',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
            'is_accepting_patients' => true,
        ]);

        return $doctor->load('doctorProfile');
    }

    private function slotFor(
        User $doctor,
        ?\DateTimeInterface $startsAt = null,
        string $status = 'available'
    ): AppointmentSlot {
        $startsAt = $startsAt
            ? CarbonImmutable::instance($startsAt)
            : CarbonImmutable::now()->addDays(2);

        return AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'status' => $status,
        ]);
    }

    private function createAppointment(
        User $doctor,
        User $patient
    ): Appointment {
        $slot = $this->slotFor(
            $doctor,
            CarbonImmutable::now()->addDays(3),
            'booked'
        );

        return Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => 'booked',
            'booked_at' => now(),
        ]);
    }
}
