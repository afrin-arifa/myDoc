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
use App\Services\GoogleCalendarAuthorizationException;
use App\Services\GoogleCalendarService;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use Tests\Concerns\ProvisionsAssistants;
use Tests\TestCase;

/**
 * Phase 5F: token refresh, lost-authorization handling, status accuracy, reconnection clean-up, reconciliation and
 * event privacy. Google is never contacted: HTTP goes through Guzzle's MockHandler, or the Calendar is a mock.
 */
class GoogleCalendarHardeningTest extends TestCase
{
    use ProvisionsAssistants;
    use RefreshDatabase;

    /**
     * @var list<array{request: RequestInterface}>
     */
    private array $requests = [];

    public function test_a_valid_access_token_is_used_without_refreshing_it(): void
    {
        [, $connection] = $this->confirmedAppointment(tokenExpiresAt: now()->addMinutes(30));
        $service = $this->serviceWithHttp([]);

        $client = $this->authenticatedClient($service, $connection);

        $this->assertSame('access-token', $client->getAccessToken()['access_token']);
        $this->assertSame([], $this->requests, 'No token refresh request is made while the token is valid.');
    }

    public function test_an_expired_token_is_refreshed_and_stored_keeping_the_refresh_token(): void
    {
        [, $connection] = $this->confirmedAppointment(tokenExpiresAt: now()->subMinute());
        $service = $this->serviceWithHttp([new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'new-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer']))]);

        $this->authenticatedClient($service, $connection);

        $connection->refresh();
        $this->assertCount(1, $this->requests);
        $this->assertSame('new-access-token', $connection->access_token);
        $this->assertSame('refresh-token', $connection->refresh_token, 'Google omits the refresh token on refresh; the stored one is kept.');
        $this->assertEqualsWithDelta(now()->addHour()->getTimestamp(), $connection->token_expires_at->getTimestamp(), 5);
        $this->assertTrue($connection->is_connected);
    }

    public function test_a_revoked_refresh_token_marks_the_calendar_for_reconnection_and_stops_retrying(): void
    {
        [$appointment, $connection] = $this->confirmedAppointment(tokenExpiresAt: now()->subMinute());
        $service = $this->serviceWithHttp([new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']))]);

        (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);

        $this->assertFalse($connection->fresh()->is_connected);
        $this->assertDatabaseHas('appointment_google_calendar_events', [
            'appointment_id' => $appointment->id,
            'status' => 'failed',
            'last_error' => SyncAppointmentToGoogleCalendar::RECONNECT_ERROR,
        ]);
        // The connection is kept so the doctor sees it needs reconnecting.
        $this->assertDatabaseHas('google_calendar_connections', ['id' => $connection->id]);

        // Later jobs do nothing until the doctor reconnects: no request reaches Google.
        $this->requests = [];
        (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);
        $this->assertSame([], $this->requests);
    }

    public function test_a_missing_refresh_token_or_a_401_is_treated_as_lost_authorization(): void
    {
        [, $withoutRefreshToken] = $this->confirmedAppointment(tokenExpiresAt: now()->subMinute(), refreshToken: null);
        $this->expectAuthorizationException(fn () => $this->authenticatedClient($this->serviceWithHttp([]), $withoutRefreshToken));

        [$appointment, $connection] = $this->confirmedAppointment();
        $events = \Mockery::mock();
        $events->shouldReceive('insert')->once()->andThrow(new GoogleServiceException('unauthorized', 401));
        $calendar = \Mockery::mock(Calendar::class);
        $calendar->events = $events;

        (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle(new GoogleCalendarService($calendar));

        $this->assertFalse($connection->fresh()->is_connected);
        $this->assertSame(SyncAppointmentToGoogleCalendar::RECONNECT_ERROR, $appointment->googleCalendarEvent()->sole()->last_error);
    }

    public function test_temporary_refresh_failures_are_retried_and_keep_the_connection(): void
    {
        [$appointment, $connection] = $this->confirmedAppointment(tokenExpiresAt: now()->subMinute());
        $service = $this->serviceWithHttp([new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => 'internal_failure']))]);

        try {
            (new SyncAppointmentToGoogleCalendar($appointment->id, 'sync'))->handle($service);
            $this->fail('A temporary failure must be rethrown so the queue retries it.');
        } catch (RuntimeException $exception) {
            $this->assertNotInstanceOf(GoogleCalendarAuthorizationException::class, $exception);
        }

        $this->assertTrue($connection->fresh()->is_connected);
        $this->assertSame('Google Calendar synchronization failed.', $appointment->googleCalendarEvent()->sole()->last_error);
    }

    public function test_events_cancelled_while_disconnected_are_kept_for_removal_and_removed_after_reconnecting(): void
    {
        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        [$appointment, $connection] = $this->confirmedAppointment();
        $event = $this->event($appointment, $connection, 'synced');
        $connection->update(['is_connected' => false]);

        $this->travelTo($appointment->slot->starts_at->copy()->subDays(2));
        app(AppointmentBookingService::class)->cancel($appointment->patient, $appointment->id);
        $noGoogle = $this->mock(GoogleCalendarService::class);
        $noGoogle->shouldNotReceive('deleteEvent');
        (new SyncAppointmentToGoogleCalendar($appointment->id, 'delete'))->handle($noGoogle);

        $this->assertSame([SyncAppointmentToGoogleCalendar::STATUS_DELETE_PENDING, 'google-event-id'], [$event->fresh()->status, $event->fresh()->google_event_id], 'Not marked deleted: the event is still in Google.');

        $this->reconnect($appointment->slot->doctorProfile->user);
        Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, fn ($job) => $job->appointmentId === $appointment->id && $job->operation === 'delete');
        $this->assertTrue($connection->fresh()->is_connected);

        $google = $this->mock(GoogleCalendarService::class);
        $google->shouldReceive('deleteEvent')->once()->withArgs(fn ($usedConnection, string $eventId) => $usedConnection->id === $connection->id && $eventId === 'google-event-id');
        (new SyncAppointmentToGoogleCalendar($appointment->id, 'delete'))->handle($google);
        $this->assertSame('deleted', $event->fresh()->status);

        (new SyncAppointmentToGoogleCalendar($appointment->id, 'delete'))->handle($google);
    }

    public function test_events_cancelled_after_disconnecting_are_not_reported_as_deleted(): void
    {
        [$appointment, $connection] = $this->confirmedAppointment();
        $event = $this->event($appointment, $connection, 'synced');
        $this->mock(GoogleCalendarService::class)->shouldReceive('revoke')->once();
        $this->actingAs($appointment->slot->doctorProfile->user)->delete(route('doctor.google-calendar.disconnect'))->assertSessionHas('status');

        $appointment->update(['status' => 'cancelled']);
        (new SyncAppointmentToGoogleCalendar($appointment->id, 'delete'))->handle(app(GoogleCalendarService::class));

        $this->assertSame([SyncAppointmentToGoogleCalendar::STATUS_DELETE_PENDING, null], [$event->fresh()->status, $event->fresh()->google_calendar_connection_id]);
    }

    public function test_reconnecting_keeps_deleted_events_deleted(): void
    {
        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        [$appointment, $connection] = $this->confirmedAppointment();
        $appointment->update(['status' => 'cancelled']);
        $event = $this->event($appointment, $connection, 'deleted');

        $this->reconnect($appointment->slot->doctorProfile->user);

        $this->assertSame('deleted', $event->fresh()->status);
        Queue::assertNotPushed(SyncAppointmentToGoogleCalendar::class);
    }

    public function test_reconciliation_resyncs_unsettled_and_removes_stale_events_for_upcoming_appointments_only(): void
    {
        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        $doctor = $this->doctor();
        [$missingEvent, $connection] = $this->confirmedAppointment($doctor);
        [$failedLongAgo] = $this->confirmedAppointment($doctor, withConnection: false);
        [$failedJustNow] = $this->confirmedAppointment($doctor, withConnection: false);
        [$alreadySynced] = $this->confirmedAppointment($doctor, withConnection: false);
        [$cancelledWithEvent] = $this->confirmedAppointment($doctor, withConnection: false);
        [$cancelledDeleted] = $this->confirmedAppointment($doctor, withConnection: false);
        [$past] = $this->confirmedAppointment($doctor, withConnection: false, startsAt: now()->subDay());
        [$otherDoctorDisconnected, $otherConnection] = $this->confirmedAppointment();
        $otherConnection->update(['is_connected' => false]);

        $this->travel(-2)->hours();
        $this->event($failedLongAgo, $connection, 'failed');
        $this->event($alreadySynced, $connection, 'synced');
        $this->event($cancelledWithEvent, $connection, 'synced');
        $this->event($cancelledDeleted, $connection, 'deleted');
        $this->travelBack();
        $this->event($failedJustNow, $connection, 'failed');
        $cancelledWithEvent->update(['status' => 'cancelled']);
        $cancelledDeleted->update(['status' => 'cancelled']);

        $this->artisan('mydoc:reconcile-google-calendar')
            ->expectsOutputToContain('queued 2 sync(s) and 1 removal(s)')
            ->assertSuccessful();

        $pushed = collect(Queue::pushed(SyncAppointmentToGoogleCalendar::class))->map(fn ($job) => [$job->appointmentId, $job->operation]);
        $this->assertEqualsCanonicalizing([[$missingEvent->id, 'sync'], [$failedLongAgo->id, 'sync'], [$cancelledWithEvent->id, 'delete']], $pushed->all());
        $this->assertNotContains($past->id, $pushed->pluck(0));
        $this->assertNotContains($otherDoctorDisconnected->id, $pushed->pluck(0));
    }

    public function test_reconciliation_runs_hourly_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'mydoc:reconcile-google-calendar'));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_settings_show_the_real_connection_state(): void
    {
        $doctor = $this->doctor();
        $this->actingAs($doctor)->get(route('doctor.settings'))->assertOk()
            ->assertSeeInOrder(['data-google-calendar-state', 'Not connected'], false)
            ->assertSee('Connect Google Calendar');

        [$appointment, $connection] = $this->confirmedAppointment($doctor);
        $connection->update(['last_synced_at' => now(), 'google_account_email' => 'doctor@example.com']);
        $this->actingAs($doctor)->get(route('doctor.settings'))->assertOk()
            ->assertSeeInOrder(['data-google-calendar-state', 'Connected'], false)
            ->assertSee('Last synced')
            ->assertDontSee('Reconnect Google Calendar');

        $connection->update(['is_connected' => false]);
        $this->event($appointment, $connection, 'failed');
        $this->actingAs($doctor)->get(route('doctor.settings'))->assertOk()
            ->assertSeeInOrder(['data-google-calendar-state', 'Reconnection needed'], false)
            ->assertSee('Reconnect Google Calendar')
            ->assertSee(route('doctor.google-calendar.connect'), false)
            ->assertSee('1 appointment could not be updated in Google Calendar yet');
    }

    public function test_assistants_cannot_connect_or_disconnect_a_doctors_google_calendar(): void
    {
        $assistant = $this->activeAssistant();
        [$appointment, $connection] = $this->confirmedAppointment();

        $this->actingAs($assistant)->get(route('doctor.google-calendar.connect'))->assertForbidden();
        $this->actingAs($assistant)->withSession(['google_calendar_oauth_state' => 'state'])
            ->get(route('doctor.google-calendar.callback', ['state' => 'state', 'code' => 'code']))->assertForbidden();
        $this->actingAs($assistant)->delete(route('doctor.google-calendar.disconnect'))->assertForbidden();

        $this->assertTrue($connection->fresh()->is_connected);
    }

    public function test_connection_failures_are_shown_to_the_doctor(): void
    {
        $doctor = $this->doctor();

        $this->actingAs($doctor)->withSession(['google_calendar_oauth_state' => 'state'])
            ->get(route('doctor.google-calendar.callback', ['state' => 'state', 'error' => 'access_denied']))
            ->assertRedirect(route('doctor.dashboard'))
            ->assertSessionHas('warning', 'Google Calendar connection was cancelled.');

        $this->mock(GoogleCalendarService::class)->shouldReceive('exchangeCode')->once()->andThrow(new RuntimeException('Google authorization was not completed.'));
        $this->actingAs($doctor)->withSession(['google_calendar_oauth_state' => 'state'])
            ->get(route('doctor.google-calendar.callback', ['state' => 'state', 'code' => 'code']))
            ->assertSessionHas('warning', 'Google Calendar could not be connected. Please try again.');
        $this->actingAs($doctor)->get(route('doctor.dashboard'))->assertOk();
        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_events_carry_only_a_generic_title_and_the_consultation_type(): void
    {
        [$appointment, $connection] = $this->confirmedAppointment();
        $appointment->update(['patient_notes' => 'Private symptom description', 'provider_notes' => 'Staff note']);
        $captured = null;
        $events = \Mockery::mock();
        $events->shouldReceive('insert')->once()->withArgs(function (string $calendarId, Event $event) use (&$captured): bool {
            $captured = $event;

            return $calendarId === 'primary';
        });
        $calendar = \Mockery::mock(Calendar::class);
        $calendar->events = $events;

        (new GoogleCalendarService($calendar))->createEvent($connection, $appointment->fresh());

        $this->assertSame('myDoc Appointment', $captured->getSummary());
        $this->assertSame('Consultation: Physical visit', $captured->getDescription());
        $this->assertEmpty($captured->getAttendees());
        $this->assertNull($captured->getLocation());
        $serialized = json_encode($captured->toSimpleObject());
        foreach ([$appointment->patient->name, $appointment->patient->email, 'Private symptom description', 'Staff note'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }

    private function expectAuthorizationException(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected lost Google authorization.');
        } catch (GoogleCalendarAuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param  list<Response>  $responses
     */
    private function serviceWithHttp(array $responses): GoogleCalendarService
    {
        $this->requests = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));

        return new GoogleCalendarService(httpClient: new HttpClient(['handler' => $stack, 'http_errors' => false, 'base_uri' => 'https://www.googleapis.com']));
    }

    private function authenticatedClient(GoogleCalendarService $service, GoogleCalendarConnection $connection): Client
    {
        return (new ReflectionMethod($service, 'authenticatedClient'))->invoke($service, $connection);
    }

    private function reconnect(User $doctor): void
    {
        $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('exchangeCode')->once()->andReturn(['access_token' => 'new-access-token', 'refresh_token' => 'new-refresh-token']);
            $mock->shouldReceive('connectionData')->once()->andReturn(['calendar_id' => 'primary', 'google_account_email' => null, 'token_expires_at' => now()->addHour()]);
        });

        $this->actingAs($doctor)->withSession(['google_calendar_oauth_state' => 'state'])
            ->get(route('doctor.google-calendar.callback', ['state' => 'state', 'code' => 'code']))
            ->assertRedirect(route('doctor.dashboard'));
    }

    private function event(Appointment $appointment, GoogleCalendarConnection $connection, string $status): AppointmentGoogleCalendarEvent
    {
        return AppointmentGoogleCalendarEvent::create([
            'appointment_id' => $appointment->id,
            'google_calendar_connection_id' => $connection->id,
            'google_event_id' => $status === 'pending' ? null : 'google-event-id',
            'status' => $status,
        ]);
    }

    /**
     * @return array{0: Appointment, 1: GoogleCalendarConnection|null}
     */
    private function confirmedAppointment(?User $doctor = null, bool $withConnection = true, ?\DateTimeInterface $startsAt = null, ?\DateTimeInterface $tokenExpiresAt = null, ?string $refreshToken = 'refresh-token'): array
    {
        static $offset = 0;
        $doctor ??= $this->doctor();
        $profile = $doctor->doctorProfile;
        $connection = $withConnection
            ? GoogleCalendarConnection::create([
                'doctor_profile_id' => $profile->id,
                'access_token' => 'access-token',
                'refresh_token' => $refreshToken,
                'token_expires_at' => $tokenExpiresAt ?? now()->addHour(),
            ])
            : $profile->googleCalendarConnection()->first();
        $start = ($startsAt ? now()->setTimestamp($startsAt->getTimestamp()) : now()->addDays(3))->addMinutes(10 * $offset++);
        $slot = AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(10), 'status' => 'booked']);
        $appointment = Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => User::factory()->create(['role' => 'patient'])->id,
            'status' => 'confirmed',
            'consultation_type' => 'physical',
        ]);

        return [$appointment->load(['slot.doctorProfile.user', 'patient']), $connection];
    }

    private function doctor(array $attributes = []): User
    {
        $doctor = User::factory()->create(['role' => 'doctor', ...$attributes]);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'Physician', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 50]);

        return $doctor->load('doctorProfile');
    }
}
