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
use Google\Service\Calendar;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctors_can_start_google_calendar_connection(): void
    {
        $doctor = $this->doctor();
        $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('authorizationUrl')->once()->andReturn('https://accounts.google.com/oauth');
        });

        $response = $this->actingAs($doctor)->get(route('doctor.google-calendar.connect'));

        $response->assertRedirect('https://accounts.google.com/oauth');
        $this->assertNotEmpty(session('google_calendar_oauth_state'));
    }

    public function test_non_doctors_cannot_start_google_calendar_connection(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $this->actingAs($patient)
            ->get(route('doctor.google-calendar.connect'))
            ->assertForbidden();
    }

    public function test_callback_rejects_an_invalid_oauth_state(): void
    {
        $doctor = $this->doctor();

        $this->actingAs($doctor)
            ->withSession(['google_calendar_oauth_state' => 'expected-state'])
            ->get(route('doctor.google-calendar.callback', [
                'state' => 'wrong-state',
                'code' => 'code',
            ]))
            ->assertStatus(419);
    }

    public function test_successful_callback_stores_encrypted_credentials(): void
    {
        $doctor = $this->doctor();
        $expiresAt = now()->addHour();
        $this->mock(GoogleCalendarService::class, function ($mock) use ($expiresAt): void {
            $mock->shouldReceive('exchangeCode')->once()->with('code')->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
            ]);
            $mock->shouldReceive('connectionData')->once()->andReturn([
                'calendar_id' => 'primary',
                'google_account_email' => 'doctor@example.com',
                'token_expires_at' => $expiresAt,
            ]);
        });

        $response = $this->actingAs($doctor)
            ->withSession(['google_calendar_oauth_state' => 'expected-state'])
            ->get(route('doctor.google-calendar.callback', [
                'state' => 'expected-state',
                'code' => 'code',
            ]));

        $response->assertRedirect(route('doctor.dashboard'));
        $this->assertDatabaseHas('google_calendar_connections', [
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'google_account_email' => 'doctor@example.com',
            'is_connected' => 1,
        ]);
        $this->assertNotSame(
            'access-token',
            GoogleCalendarConnection::query()->firstOrFail()->getRawOriginal('access_token')
        );
    }

    public function test_confirmed_appointment_creates_a_deterministic_event(): void
    {
        [$appointment, $connection] = $this->confirmedAppointment();
        $secondAppointment = $this->confirmedAppointment()[0];
        $service = app(GoogleCalendarService::class);
        $eventId = $service->eventId($appointment);

        $this->assertMatchesRegularExpression('/^[a-v0-9]+$/', $eventId);
        $this->assertSame($eventId, $service->eventId($appointment));
        $this->assertNotSame($eventId, $service->eventId($secondAppointment));

    }

    public function test_sync_job_creates_only_one_event_for_an_appointment(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $connection = GoogleCalendarConnection::create([
            'doctor_profile_id' => $profile->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
        ]);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => 'booked',
        ]);
        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => User::factory()->create(['role' => 'patient'])->id,
            'status' => 'confirmed',
            'consultation_type' => 'physical',
        ]);
        $service = $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('createEvent')->once()->andReturn('google-event-id');
            $mock->shouldReceive('updateEvent')->once();
        });

        (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);
        (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);

        $this->assertDatabaseCount('appointment_google_calendar_events', 1);
        $this->assertDatabaseHas('appointment_google_calendar_events', [
            'appointment_id' => $appointment->id,
            'google_calendar_connection_id' => $connection->id,
            'google_event_id' => 'google-event-id',
            'status' => 'synced',
        ]);
    }

    public function test_missing_google_event_is_recreated_with_its_deterministic_id(): void
    {
        [$appointment, $connection] = $this->confirmedAppointment();
        $events = \Mockery::mock();
        $events->shouldReceive('update')->andThrow(new GoogleServiceException('missing', 404));
        $events->shouldReceive('insert')->atLeast()->once();
        $calendar = \Mockery::mock(Calendar::class);
        $calendar->events = $events;
        $service = new GoogleCalendarService($calendar);
        $service->updateEvent($connection, $appointment, $service->eventId($appointment));
        $service->updateEvent($connection, $appointment, $service->eventId($appointment));
    }

    public function test_cancelled_and_rejected_appointments_cannot_be_recreated_by_sync_jobs(): void
    {
        foreach (['cancelled', 'rejected'] as $status) {
            [$appointment] = $this->confirmedAppointment();
            $appointment->update(['status' => $status]);
            $service = $this->mock(GoogleCalendarService::class);
            $service->shouldNotReceive('createEvent');
            $service->shouldNotReceive('updateEvent');

            (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);

            $this->assertDatabaseMissing('appointment_google_calendar_events', [
                'appointment_id' => $appointment->id,
                'status' => 'synced',
            ]);
        }
    }

    public function test_sync_and_delete_jobs_are_safe_when_executed_out_of_order(): void
    {
        [$appointment] = $this->confirmedAppointment();
        $appointment->update(['status' => 'cancelled']);
        $service = $this->mock(GoogleCalendarService::class);
        $service->shouldNotReceive('createEvent');
        $service->shouldNotReceive('updateEvent');

        (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);
        (new SyncAppointmentToGoogleCalendar($appointment->id, 'delete'))->handle($service);

        $this->assertDatabaseMissing('appointment_google_calendar_events', [
            'appointment_id' => $appointment->id,
        ]);
    }

    public function test_reconnection_reassociates_existing_event_records(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $oldConnection = GoogleCalendarConnection::create([
            'doctor_profile_id' => $profile->id,
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
        ]);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => 'booked',
        ]);
        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => User::factory()->create(['role' => 'patient'])->id,
            'status' => 'confirmed',
            'consultation_type' => 'physical',
        ]);
        $event = AppointmentGoogleCalendarEvent::create([
            'appointment_id' => $appointment->id,
            'google_calendar_connection_id' => $oldConnection->id,
            'google_event_id' => 'old-event-id',
            'status' => 'synced',
        ]);
        $oldConnection->delete();
        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('exchangeCode')->once()->andReturn([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
            ]);
            $mock->shouldReceive('connectionData')->once()->andReturn([
                'calendar_id' => 'primary',
                'google_account_email' => 'new@example.com',
                'token_expires_at' => now()->addHour(),
            ]);
        });

        $this->actingAs($doctor)
            ->withSession(['google_calendar_oauth_state' => 'state'])
            ->get(route('doctor.google-calendar.callback', ['state' => 'state', 'code' => 'code']))
            ->assertRedirect(route('doctor.dashboard'));

        $newConnection = GoogleCalendarConnection::query()->firstOrFail();
        $this->assertSame($newConnection->id, $event->fresh()->google_calendar_connection_id);
        $this->assertNull($event->fresh()->google_event_id);
        $this->assertSame('pending', $event->fresh()->status);
        Queue::assertPushed(
            SyncAppointmentToGoogleCalendar::class,
            fn ($job) => $job->appointmentId === $appointment->id && $job->operation === 'sync'
        );
    }

    public function test_confirmation_and_cancellation_dispatch_calendar_jobs_after_local_changes(): void
    {
        Queue::fake();
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
            'status' => 'booked',
        ]);
        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => User::factory()->create(['role' => 'patient'])->id,
            'status' => 'booked',
            'consultation_type' => 'physical',
        ]);

        app(DoctorAppointmentService::class)->transition($doctor, $appointment, 'confirmed');
        Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, fn ($job) => $job->operation === 'sync');

        app(AppointmentBookingService::class)->cancel(
            User::query()->findOrFail($appointment->patient_id),
            $appointment->id
        );
        Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, fn ($job) => $job->operation === 'delete');
    }

    public function test_rejection_dispatches_a_delete_job(): void
    {
        Queue::fake();
        $doctor = $this->doctor();
        [$appointment] = $this->confirmedAppointment($doctor);
        $appointment->update(['status' => 'booked']);

        app(DoctorAppointmentService::class)->transition($doctor, $appointment, 'rejected');

        Queue::assertPushed(
            SyncAppointmentToGoogleCalendar::class,
            fn ($job) => $job->appointmentId === $appointment->id && $job->operation === 'delete'
        );
    }

    public function test_disconnect_removes_local_connection_but_preserves_event_history(): void
    {
        $doctor = $this->doctor();
        [$appointment, $connection] = $this->confirmedAppointment($doctor);
        $event = AppointmentGoogleCalendarEvent::create([
            'appointment_id' => $appointment->id,
            'google_calendar_connection_id' => $connection->id,
            'google_event_id' => 'google-event-id',
            'status' => 'synced',
        ]);
        $service = $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('revoke')->once();
        });

        $this->actingAs($doctor)
            ->delete(route('doctor.google-calendar.disconnect'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('google_calendar_connections', ['id' => $connection->id]);
        $this->assertDatabaseHas('appointment_google_calendar_events', [
            'id' => $event->id,
            'google_event_id' => 'google-event-id',
            'google_calendar_connection_id' => null,
        ]);
    }

    public function test_google_api_errors_are_retried_and_recorded_safely(): void
    {
        [$appointment] = $this->confirmedAppointment();
        $service = $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('createEvent')->once()->andThrow(new \RuntimeException('temporary failure'));
        });

        try {
            (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);
            $this->fail('The synchronization exception was not rethrown.');
        } catch (\RuntimeException) {
            // The queue worker will retry the job using its configured backoff.
        }

        $this->assertDatabaseHas('appointment_google_calendar_events', [
            'appointment_id' => $appointment->id,
            'status' => 'failed',
            'last_error' => 'Google Calendar synchronization failed.',
        ]);
    }

    private function confirmedAppointment(?User $doctor = null): array
    {
        $doctor ??= $this->doctor();
        $profile = $doctor->doctorProfile;
        $connection = GoogleCalendarConnection::create([
            'doctor_profile_id' => $profile->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
        ]);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => 'booked',
        ]);
        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => User::factory()->create(['role' => 'patient'])->id,
            'status' => 'confirmed',
            'consultation_type' => 'physical',
        ]);

        return [$appointment, $connection];
    }

    private function doctor(): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);

        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'General Practitioner',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);

        return $doctor->fresh('doctorProfile');
    }
}
