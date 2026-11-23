<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9: the reschedule form loads one day of open slots, not every future slot of the doctor.
 */
class RescheduleFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reschedule_form_shows_one_day_of_open_slots_starting_with_the_first_open_day(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 8, 0, 0, config('app.timezone')));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-RS', 'consultation_fee' => 50]);
        $current = $this->slot($profile, '2026-11-03 09:00', 'booked');
        $appointment = Appointment::create(['appointment_slot_id' => $current->id, 'patient_id' => User::factory()->create(['role' => 'patient'])->id, 'status' => 'confirmed', 'consultation_type' => 'physical']);
        $firstDay = [$this->slot($profile, '2026-11-05 09:00'), $this->slot($profile, '2026-11-05 09:10')];
        $later = $this->slot($profile, '2026-12-20 10:00');
        $this->slot($profile, '2026-11-01 09:00');
        foreach (range(0, 199) as $offset) {
            $this->slot($profile, CarbonImmutable::parse('2026-11-10 08:00', config('app.timezone'))->addMinutes(10 * $offset)->format('Y-m-d H:i'));
        }

        $default = $this->actingAs($doctor)->get(route('doctor.appointments.reschedule', $appointment))->assertOk();
        $this->assertSame(collect($firstDay)->pluck('id')->all(), $default->viewData('slots')->pluck('id')->all());
        $default->assertSee('value="2026-11-05"', false);

        $chosen = $this->actingAs($doctor)->get(route('doctor.appointments.reschedule', [$appointment, 'date' => '2026-12-20']))->assertOk();
        $this->assertSame([$later->id], $chosen->viewData('slots')->pluck('id')->all());

        $this->actingAs($doctor)->get(route('doctor.appointments.reschedule', [$appointment, 'date' => '2026-11-06']))->assertOk()
            ->assertSee('No free slots on Fri, Nov 6, 2026');
    }

    private function slot(DoctorProfile $profile, string $startsAt, string $status = 'available'): AppointmentSlot
    {
        $start = CarbonImmutable::parse($startsAt, config('app.timezone'));

        return AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(10), 'status' => $status]);
    }
}
