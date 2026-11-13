<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorPatientListTest extends TestCase
{
    use RefreshDatabase;

    private int $slotOffset = 0;

    public function test_doctor_sees_each_own_patient_once_with_counts_scoped_to_their_practice(): void
    {
        $doctor = $this->doctor('A');
        $otherDoctor = $this->doctor('B');
        $shared = User::factory()->create(['role' => 'patient', 'name' => 'Shared Patient', 'phone' => '0170-SHARED']);
        $ownOnly = User::factory()->create(['role' => 'patient', 'name' => 'Own Patient']);
        $othersOnly = User::factory()->create(['role' => 'patient', 'name' => 'Elsewhere Patient']);

        $this->appointment($doctor, $shared, 'completed', -10);
        $this->appointment($doctor, $shared, 'cancelled', -3);
        $this->appointment($doctor, $shared, 'confirmed', 4);
        $this->appointment($otherDoctor, $shared, 'completed', -1);
        $this->appointment($otherDoctor, $shared, 'booked', 6);
        $this->appointment($doctor, $ownOnly, 'booked', 2);
        $this->appointment($otherDoctor, $othersOnly, 'booked', 3);

        $response = $this->actingAs($doctor)->get(route('doctor.patients.index'))->assertOk();
        $patients = $response->viewData('patients');

        $this->assertSame(['Own Patient', 'Shared Patient'], $patients->pluck('name')->all(), 'Each patient appears once.');
        $sharedRow = $patients->firstWhere('id', $shared->id);
        $this->assertSame(3, $sharedRow->appointment_count, "Only this doctor's appointments are counted.");
        $this->assertSame(1, $sharedRow->upcoming_count);
        $this->assertSame(now()->startOfHour()->subDays(3)->toDateString(), $sharedRow->last_appointment_at->toDateString(), "The other doctor's more recent visit is not revealed.");
        $response->assertSee('0170-SHARED')->assertDontSee('Elsewhere Patient');
        $response->assertSee(e(route('doctor.appointments.index', ['tab' => 'all', 'search' => $shared->email])), false);

        $history = $this->actingAs($doctor)->get(route('doctor.appointments.index', ['tab' => 'all', 'search' => $shared->email]))->assertOk()->viewData('appointments');
        $this->assertSame(3, $history->total(), "The history link shows this patient's appointments with this doctor only.");
        $this->assertTrue($history->every(fn (Appointment $appointment) => $appointment->patient_id === $shared->id && $appointment->slot->doctor_profile_id === $doctor->doctorProfile->id));

        $this->actingAs($otherDoctor)->get(route('doctor.patients.index'))->assertOk()
            ->assertSee('Elsewhere Patient')->assertSee('Shared Patient')->assertDontSee('Own Patient');
    }

    public function test_search_only_matches_the_doctors_own_patients(): void
    {
        $doctor = $this->doctor('A');
        $otherDoctor = $this->doctor('B');
        $own = User::factory()->create(['role' => 'patient', 'name' => 'Jamie Own', 'email' => 'jamie.own@example.com', 'phone' => '555-0001']);
        $foreign = User::factory()->create(['role' => 'patient', 'name' => 'Jamie Foreign', 'email' => 'jamie.foreign@example.com']);
        User::factory()->create(['role' => 'patient', 'name' => 'Jamie Stranger']);
        $this->appointment($doctor, $own, 'booked', 2);
        $this->appointment($otherDoctor, $foreign, 'booked', 2);

        foreach (['Jamie', 'jamie.', '555-0001'] as $term) {
            $patients = $this->actingAs($doctor)->get(route('doctor.patients.index', ['search' => $term]))->assertOk()->viewData('patients');
            $this->assertSame([$own->id], $patients->pluck('id')->all(), "Search '{$term}' must stay within this doctor's patients.");
        }

        $this->actingAs($doctor)->get(route('doctor.patients.index', ['search' => $foreign->email]))->assertOk()->assertSee('No matching patients')->assertDontSee('Jamie Foreign');
        $this->actingAs($doctor)->get(route('doctor.patients.index', ['search' => str_repeat('a', 101)]))->assertSessionHasErrors('search');
    }

    public function test_list_is_paginated_and_preserves_the_search(): void
    {
        $doctor = $this->doctor('A');
        foreach (range(1, 17) as $index) {
            $this->appointment($doctor, User::factory()->create(['role' => 'patient', 'name' => sprintf('Paged Patient %02d', $index)]), 'booked', 2);
        }

        $first = $this->actingAs($doctor)->get(route('doctor.patients.index', ['search' => 'Paged']))->assertOk();
        $this->assertCount(15, $first->viewData('patients'));
        $this->assertSame(17, $first->viewData('patients')->total());
        $first->assertSee('search=Paged', false);

        $second = $this->actingAs($doctor)->get(route('doctor.patients.index', ['search' => 'Paged', 'page' => 2]))->assertOk();
        $this->assertSame(['Paged Patient 16', 'Paged Patient 17'], $second->viewData('patients')->pluck('name')->all());
    }

    public function test_url_parameters_cannot_reveal_another_doctors_patient_list(): void
    {
        $doctor = $this->doctor('A');
        $otherDoctor = $this->doctor('B');
        $this->appointment($otherDoctor, User::factory()->create(['role' => 'patient', 'name' => 'Private Patient']), 'booked', 2);

        foreach ([['doctor_profile_id' => $otherDoctor->doctorProfile->id], ['doctor' => $otherDoctor->id], ['user_id' => $otherDoctor->id]] as $params) {
            $this->actingAs($doctor)->get(route('doctor.patients.index', $params))->assertOk()->assertDontSee('Private Patient')->assertSee('No patients yet');
        }
    }

    public function test_patients_guests_and_deactivated_doctors_cannot_open_the_page(): void
    {
        $doctor = $this->doctor('A');
        $this->appointment($doctor, User::factory()->create(['role' => 'patient']), 'booked', 2);

        $this->get(route('doctor.patients.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'patient']))->get(route('doctor.patients.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('doctor.patients.index'))->assertForbidden();

        $doctor->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($doctor->fresh())->get(route('doctor.patients.index'))->assertForbidden();
    }

    public function test_sidebar_links_to_the_patient_list(): void
    {
        $doctor = $this->doctor('A');

        $this->actingAs($doctor)->get(route('doctor.appointments.index'))->assertOk()->assertSee(route('doctor.patients.index'), false);
        $this->actingAs($doctor)->get(route('doctor.patients.index'))->assertOk()->assertSee('My patients')->assertSee('No patients yet');
    }

    private function appointment(User $doctor, User $patient, string $status, int $daysFromNow): Appointment
    {
        $startsAt = now()->startOfHour()->addDays($daysFromNow)->addMinutes(10 * $this->slotOffset++);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => in_array($status, ['cancelled', 'rejected'], true) ? 'available' : 'booked',
        ]);

        return Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => $status,
            'consultation_type' => 'physical',
        ]);
    }

    private function doctor(string $label): User
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Doctor '.$label]);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$label, 'consultation_fee' => 10]);

        return $doctor->load('doctorProfile');
    }
}
