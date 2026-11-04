<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DoctorManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctor_can_create_a_profile_and_associate_specialties(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $specialties = collect([
            Specialty::create(['name' => 'Family medicine', 'is_active' => true]),
            Specialty::create(['name' => 'Preventive care', 'is_active' => true]),
        ]);

        $this->actingAs($doctor)
            ->put(route('doctor.profile.update'), [
                'professional_title' => 'Family physician',
                'bio' => 'Focused on primary care.',
                'license_number' => 'LIC-100',
                'consultation_fee' => '75.00',
                'years_of_experience' => 8,
                'is_accepting_patients' => 1,
                'specialty_ids' => $specialties->pluck('id')->all(),
            ])
            ->assertRedirect();

        $profile = DoctorProfile::firstOrFail();
        $this->assertSame($doctor->id, $profile->user_id);
        $this->assertCount(2, $profile->specialties);
    }

    public function test_patients_cannot_manage_doctor_profiles_or_availability(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $this->actingAs($patient)
            ->get(route('doctor.dashboard'))
            ->assertForbidden();

        $this->actingAs($patient)
            ->post(route('doctor.availability.store'), [])
            ->assertForbidden();
    }

    public function test_doctors_cannot_update_another_doctors_availability(): void
    {
        $firstDoctor = User::factory()->create(['role' => 'doctor']);
        $secondDoctor = User::factory()->create(['role' => 'doctor']);
        $secondProfile = DoctorProfile::create([
            'user_id' => $secondDoctor->id,
            'professional_title' => 'Specialist',
            'license_number' => 'LIC-SECOND',
            'consultation_fee' => 50,
        ]);
        $availability = DoctorAvailability::create([
            'doctor_profile_id' => $secondProfile->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
        ]);

        $this->actingAs($firstDoctor)
            ->patch(route('doctor.availability.update', $availability), [
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'slot_duration_minutes' => 30,
            ])
            ->assertForbidden();
    }

    public function test_overlapping_availability_is_rejected(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-OVERLAP',
            'consultation_fee' => 50,
        ]);
        DoctorAvailability::create([
            'doctor_profile_id' => $profile->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
        ]);

        $this->actingAs($doctor)
            ->post(route('doctor.availability.store'), [
                'day_of_week' => 1,
                'start_time' => '11:00',
                'end_time' => '13:00',
                'slot_duration_minutes' => 30,
            ])
            ->assertSessionHasErrors('start_time');

        $this->assertCount(1, DoctorAvailability::all());
    }

    public function test_slot_generation_respects_boundaries_and_is_repeatable(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 11, 8, 0, 0, config('app.timezone')));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-SLOTS',
            'consultation_fee' => 50,
        ]);
        DoctorAvailability::create([
            'doctor_profile_id' => $profile->id,
            'day_of_week' => 0,
            'start_time' => '09:00',
            'end_time' => '10:30',
            'slot_duration_minutes' => 60,
            'is_active' => true,
        ]);

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), ['days' => 1])
            ->assertRedirect();

        $slots = AppointmentSlot::all();
        $this->assertCount(9, $slots);
        $this->assertSame('09:00:00', $slots->first()->starts_at->format('H:i:s'));
        $this->assertSame('09:10:00', $slots->first()->ends_at->format('H:i:s'));

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), ['days' => 1])
            ->assertRedirect();

        $this->assertCount(9, AppointmentSlot::all());
    }

    public function test_slot_generation_preserves_booked_and_blocked_slots(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 11, 8, 0, 0, config('app.timezone')));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-PRESERVE',
            'consultation_fee' => 50,
        ]);
        DoctorAvailability::create([
            'doctor_profile_id' => $profile->id,
            'day_of_week' => 0,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'slot_duration_minutes' => 60,
        ]);

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), ['days' => 1]);
        $slots = AppointmentSlot::query()->orderBy('starts_at')->get();
        $slots[0]->update(['status' => 'booked']);
        $slots[1]->update(['status' => 'blocked']);

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), ['days' => 1]);

        $this->assertSame('booked', $slots[0]->fresh()->status);
        $this->assertSame('blocked', $slots[1]->fresh()->status);
        $this->travelBack();
    }

    public function test_new_availability_is_normalized_to_ten_minute_slots(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-TEN',
            'consultation_fee' => 50,
        ]);

        $this->actingAs($doctor)
            ->post(route('doctor.availability.store'), [
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'slot_duration_minutes' => 30,
            ])
            ->assertRedirect();

        $this->assertSame(10, $profile->availabilities()->firstOrFail()->slot_duration_minutes);
    }

    public function test_doctor_can_create_a_manual_appointment_for_a_registered_patient(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = User::factory()->create(['role' => 'patient']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-MANUAL',
            'consultation_fee' => 50,
        ]);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHour()->addMinutes(10),
            'status' => 'available',
        ]);

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.store'), [
                'patient_id' => $patient->id,
                'slot_id' => $slot->id,
                'consultation_type' => 'video',
                'provider_notes' => 'Follow-up consultation',
            ])
            ->assertRedirect(route('doctor.schedule'));

        $this->assertDatabaseHas('appointments', [
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => 'booked',
            'provider_notes' => 'Follow-up consultation',
        ]);
        $this->assertSame('booked', $slot->fresh()->status);
    }

    public function test_schedule_returns_only_the_doctors_selected_calendar_range(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 8, 0, 0, config('app.timezone')));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-SCHEDULE',
            'consultation_fee' => 50,
        ]);
        $patient = User::factory()->create(['role' => 'patient', 'name' => 'Calendar Patient']);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 09:10:00',
            'status' => 'booked',
        ]);
        Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => 'booked',
            'consultation_type' => 'video',
        ]);
        $otherDoctor = $this->makeDoctor();
        $otherPatient = User::factory()->create(['role' => 'patient', 'name' => 'Other Doctor Patient']);
        $otherSlot = AppointmentSlot::create([
            'doctor_profile_id' => $otherDoctor->doctorProfile->id,
            'starts_at' => '2026-11-02 10:00:00',
            'ends_at' => '2026-11-02 10:10:00',
            'status' => 'booked',
        ]);
        Appointment::create([
            'appointment_slot_id' => $otherSlot->id,
            'patient_id' => $otherPatient->id,
            'status' => 'booked',
            'consultation_type' => 'physical',
        ]);

        $response = $this->actingAs($doctor)->get(route('doctor.schedule', [
            'date' => '2026-11-02',
            'view' => 'day',
        ]));

        $response->assertOk()
            ->assertSee('Calendar Patient')
            ->assertSee('data-event-type="appointment"', false)
            ->assertSee('9:00 AM')
            ->assertSee('Total slots');
        $response->assertDontSee('Other Doctor Patient');
        $this->travelBack();
    }

    public function test_schedule_uses_monday_to_sunday_week_and_month_ranges(): void
    {
        $doctor = $this->makeDoctor();
        $mondaySlot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 09:10:00',
            'status' => 'available',
        ]);
        $sundaySlot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => '2026-11-08 09:00:00',
            'ends_at' => '2026-11-08 09:10:00',
            'status' => 'available',
        ]);

        $this->actingAs($doctor)
            ->get(route('doctor.schedule', ['date' => '2026-11-04', 'view' => 'week']))
            ->assertOk()
            ->assertSee('data-event-date="2026-11-02"', false)
            ->assertSee('data-event-date="2026-11-08"', false);

        $this->actingAs($doctor)
            ->get(route('doctor.schedule', ['date' => '2026-11-15', 'view' => 'month']))
            ->assertOk()
            ->assertSee('data-calendar-date="2026-11-08"', false);
    }

    public function test_schedule_status_filters_limit_calendar_events_without_changing_summary_counts(): void
    {
        $doctor = $this->makeDoctor();
        $patient = User::factory()->create(['role' => 'patient', 'name' => 'Pending Patient']);
        $bookedSlot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 09:10:00',
            'status' => 'booked',
        ]);
        Appointment::create([
            'appointment_slot_id' => $bookedSlot->id,
            'patient_id' => $patient->id,
            'status' => 'booked',
            'consultation_type' => 'physical',
        ]);
        AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => '2026-11-02 10:00:00',
            'ends_at' => '2026-11-02 10:10:00',
            'status' => 'available',
        ]);

        $this->actingAs($doctor)
            ->get(route('doctor.schedule', [
                'date' => '2026-11-02',
                'view' => 'day',
                'filters' => ['available'],
            ]))
            ->assertOk()
            ->assertDontSee('data-event-type="appointment"', false)
            ->assertSee('data-event-type="available"', false);
    }

    public function test_doctor_can_reschedule_without_replacing_the_appointment(): void
    {
        Queue::fake();
        $doctor = $this->makeDoctor();
        $patient = User::factory()->create(['role' => 'patient']);
        $oldSlot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHour()->addMinutes(10),
            'status' => 'booked',
        ]);
        $newSlot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(2)->addMinutes(10),
            'status' => 'available',
        ]);
        $appointment = Appointment::create([
            'appointment_slot_id' => $oldSlot->id,
            'patient_id' => $patient->id,
            'status' => 'confirmed',
            'consultation_type' => 'video',
            'provider_notes' => 'Keep this note',
        ]);

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.reschedule.store', $appointment), [
                'slot_id' => $newSlot->id,
                'reason' => 'Patient requested a later time.',
            ])
            ->assertRedirect(route('doctor.appointments.show', $appointment));

        $this->assertSame($appointment->id, $appointment->fresh()->id);
        $this->assertSame($patient->id, $appointment->fresh()->patient_id);
        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->assertSame('available', $oldSlot->fresh()->status);
        $this->assertSame('booked', $newSlot->fresh()->status);
        $this->assertDatabaseHas('appointment_reschedule_histories', [
            'appointment_id' => $appointment->id,
            'previous_slot_id' => $oldSlot->id,
            'new_slot_id' => $newSlot->id,
            'performed_by' => $doctor->id,
        ]);
    }

    public function test_only_confirmed_appointments_can_be_completed_by_the_owning_doctor(): void
    {
        $doctor = $this->makeDoctor();
        $patient = User::factory()->create(['role' => 'patient']);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHour()->addMinutes(10),
            'status' => 'booked',
        ]);
        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => 'booked',
            'consultation_type' => 'physical',
        ]);

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.complete', $appointment))
            ->assertSessionHasErrors('status');
        $this->assertSame('booked', $appointment->fresh()->status);

        $appointment->update(['status' => 'confirmed']);
        $this->actingAs($doctor)
            ->post(route('doctor.appointments.complete', $appointment))
            ->assertRedirect();
        $this->assertSame('completed', $appointment->fresh()->status);
    }

    private function makeDoctor(): User
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
