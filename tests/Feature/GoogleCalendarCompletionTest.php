<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentGoogleCalendarEvent;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\DoctorAppointmentService;
use App\Services\GoogleCalendarService;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleCalendarCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_an_appointment_does_not_dispatch_a_calendar_job(): void
    {
        Queue::fake();
        [$doctor, $appointment] = $this->syncedConfirmedAppointment();

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.complete', $appointment))
            ->assertRedirect();

        $this->assertSame('completed', $appointment->fresh()->status);
        Queue::assertNotPushed(SyncAppointmentToGoogleCalendar::class);
    }

    public function test_sync_and_delete_jobs_for_a_completed_appointment_keep_its_existing_event(): void
    {
        [$doctor, $appointment] = $this->syncedConfirmedAppointment();
        app(DoctorAppointmentService::class)->complete($doctor, $appointment);
        $service = $this->mock(GoogleCalendarService::class);
        $service->shouldNotReceive('createEvent');
        $service->shouldNotReceive('updateEvent');
        $service->shouldNotReceive('deleteEvent');

        (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);

        $this->assertDatabaseCount('appointment_google_calendar_events', 1);
        $this->assertDatabaseHas('appointment_google_calendar_events', [
            'appointment_id' => $appointment->id,
            'google_event_id' => 'existing-event-id',
            'status' => 'synced',
        ]);
    }

    public function test_confirmation_synchronizes_a_new_event(): void
    {
        [$doctor, $appointment] = $this->syncedConfirmedAppointment();
        $appointment->googleCalendarEvent()->delete();
        $service = $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('createEvent')->once()->andReturn('new-event-id');
        });

        (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);

        $this->assertDatabaseHas('appointment_google_calendar_events', [
            'appointment_id' => $appointment->id,
            'google_event_id' => 'new-event-id',
            'status' => 'synced',
        ]);
    }

    public function test_rescheduling_a_confirmed_appointment_updates_the_existing_event(): void
    {
        Queue::fake();
        [$doctor, $appointment] = $this->syncedConfirmedAppointment();
        $newSlot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => now()->addDays(3)->startOfMinute(),
            'ends_at' => now()->addDays(3)->startOfMinute()->addMinutes(10),
            'status' => 'available',
        ]);

        app(DoctorAppointmentService::class)->reschedule($doctor, $appointment, $newSlot->id);

        Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, fn ($job) => $job->appointmentId === $appointment->id && $job->operation === 'sync');
        $service = $this->mock(GoogleCalendarService::class, function ($mock) use ($newSlot): void {
            $mock->shouldReceive('updateEvent')->once()->withArgs(
                fn ($connection, Appointment $synced, string $eventId) => $eventId === 'existing-event-id' && $synced->appointment_slot_id === $newSlot->id
            );
            $mock->shouldNotReceive('createEvent');
        });

        (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);

        $this->assertDatabaseCount('appointment_google_calendar_events', 1);
    }

    public function test_cancellation_and_rejection_delete_the_event(): void
    {
        Queue::fake();
        [, $cancelled] = $this->syncedConfirmedAppointment(daysAhead: 3);
        app(AppointmentBookingService::class)->cancel($cancelled->patient, $cancelled->id);

        [$doctor, $rejected] = $this->syncedConfirmedAppointment();
        $rejected->update(['status' => 'booked']);
        app(DoctorAppointmentService::class)->transition($doctor, $rejected, 'rejected');

        foreach ([$cancelled, $rejected] as $appointment) {
            Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, fn ($job) => $job->appointmentId === $appointment->id && $job->operation === 'delete');
            $service = $this->mock(GoogleCalendarService::class, function ($mock): void {
                $mock->shouldReceive('deleteEvent')->once()->withArgs(fn ($connection, string $eventId) => $eventId === 'existing-event-id');
            });

            (new SyncAppointmentToGoogleCalendar($appointment->id, 'delete'))->handle($service);

            $this->assertSame('deleted', $appointment->googleCalendarEvent()->first()->status);
        }
    }

    public function test_failed_deletion_is_recorded_without_leaking_details_and_rethrown_for_retry(): void
    {
        [, $appointment] = $this->syncedConfirmedAppointment();
        $appointment->update(['status' => 'cancelled']);
        $service = $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('deleteEvent')->once()->andThrow(new GoogleServiceException('secret upstream detail', 500));
        });

        try {
            (new SyncAppointmentToGoogleCalendar($appointment->id, 'delete'))->handle($service);
            $this->fail('The job should rethrow so the queue can retry it.');
        } catch (GoogleServiceException) {
        }

        $this->assertDatabaseHas('appointment_google_calendar_events', [
            'appointment_id' => $appointment->id,
            'status' => 'failed',
            'last_error' => 'Google Calendar deletion failed.',
        ]);
    }

    /**
     * @return array{User, Appointment}
     */
    private function syncedConfirmedAppointment(int $daysAhead = 1): array
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);
        $connection = GoogleCalendarConnection::create([
            'doctor_profile_id' => $profile->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
        ]);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => now()->addDays($daysAhead)->startOfMinute(),
            'ends_at' => now()->addDays($daysAhead)->startOfMinute()->addMinutes(10),
            'status' => 'booked',
        ]);
        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => User::factory()->create(['role' => 'patient'])->id,
            'status' => 'confirmed',
            'consultation_type' => 'physical',
        ]);
        AppointmentGoogleCalendarEvent::create([
            'appointment_id' => $appointment->id,
            'google_calendar_connection_id' => $connection->id,
            'google_event_id' => 'existing-event-id',
            'status' => 'synced',
            'synced_at' => now(),
        ]);

        return [$doctor->load('doctorProfile'), $appointment];
    }
}
