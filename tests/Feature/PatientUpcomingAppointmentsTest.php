<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientUpcomingAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upcoming_list_and_count_include_only_future_booked_and_confirmed_appointments(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $profile = $this->doctorProfile();
        $futureBooked = $this->appointment($profile, $patient, 'booked', now()->addDays(3));
        $futureConfirmed = $this->appointment($profile, $patient, 'confirmed', now()->addDays(1));
        $excluded = [
            $this->appointment($profile, $patient, 'cancelled', now()->addDays(2)),
            $this->appointment($profile, $patient, 'rejected', now()->addDays(4)),
            $this->appointment($profile, $patient, 'completed', now()->addDays(5)),
            $this->appointment($profile, $patient, 'confirmed', now()->subHour()),
            $this->appointment($profile, $patient, 'booked', now()->subDay()),
        ];
        $otherPatientsAppointment = $this->appointment($profile, User::factory()->create(['role' => 'patient']), 'booked', now()->addDays(6));

        $this->assertSame(
            [$futureConfirmed->id, $futureBooked->id],
            Appointment::query()->whereBelongsTo($patient, 'patient')->upcoming()->orderBy('id', 'desc')->pluck('id')->all()
        );

        $response = $this->actingAs($patient)
            ->get(route('patient.dashboard'))
            ->assertOk()
            ->assertViewHas('appointmentCounts', fn (array $counts): bool => $counts['upcoming'] === 2 && $counts['total'] === 7);

        $this->assertSame(
            [$futureConfirmed->id, $futureBooked->id],
            $response->viewData('upcomingAppointments')->pluck('id')->all(),
            'Upcoming appointments are listed soonest first.'
        );
        $this->assertNotContains($otherPatientsAppointment->id, $response->viewData('upcomingAppointments')->pluck('id'));
        foreach ($excluded as $appointment) {
            $this->assertNotContains($appointment->id, $response->viewData('upcomingAppointments')->pluck('id'));
        }
    }

    public function test_admin_dashboard_upcoming_count_uses_the_same_rule(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $admin = User::factory()->create(['role' => 'admin']);
        $profile = $this->doctorProfile();
        $this->appointment($profile, $patient, 'booked', now()->addDay());
        $this->appointment($profile, $patient, 'cancelled', now()->addDays(2));
        $this->appointment($profile, $patient, 'rejected', now()->addDays(3));

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('counts', fn (array $counts): bool => $counts['upcoming'] === 1);
    }

    private function appointment(DoctorProfile $profile, User $patient, string $status, mixed $startsAt): Appointment
    {
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => $startsAt->copy()->startOfMinute(),
            'ends_at' => $startsAt->copy()->startOfMinute()->addMinutes(10),
            'status' => in_array($status, ['cancelled', 'rejected'], true) ? 'available' : 'booked',
        ]);

        return Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => $status,
            'consultation_type' => 'physical',
        ]);
    }

    private function doctorProfile(): DoctorProfile
    {
        $doctor = User::factory()->create(['role' => 'doctor']);

        return DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);
    }
}
