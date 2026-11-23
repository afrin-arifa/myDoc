<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\AppointmentGoogleCalendarEvent;
use App\Models\GoogleCalendarConnection;
use App\Services\GoogleCalendarAuthorizationException;
use App\Services\GoogleCalendarService;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Mirrors one appointment into its doctor's own Google Calendar: confirmed appointments have an event, other statuses
 * do not (completed appointments keep theirs as history). A per-appointment lock serializes jobs, and the job always
 * acts on the appointment's current state, so jobs running out of order still converge.
 *
 * Event record statuses: pending, synced, failed (retried, then picked up by mydoc:reconcile-google-calendar),
 * deleted, and delete_pending (the event still exists in Google but the calendar was disconnected; it is removed
 * after the doctor reconnects). When Google authorization is lost, the connection is marked as needing reconnection
 * and the job stops instead of retrying.
 */
class SyncAppointmentToGoogleCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const STATUS_DELETE_PENDING = 'delete_pending';

    public const RECONNECT_ERROR = 'Google Calendar needs to be reconnected.';

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    /**
     * Up to three Google requests (token refresh, insert, update on conflict) at the configured request timeout,
     * plus the lock wait. Kept below the database queue's retry_after (90 s).
     */
    public int $timeout = 60;

    public function __construct(
        public int $appointmentId,
        public string $operation
    ) {}

    public function handle(GoogleCalendarService $service): mixed
    {
        $lock = Cache::lock('google-calendar-appointment:'.$this->appointmentId, 120);

        return $lock->block(10, function () use ($service) {
            $this->synchronize($service);
        });
    }

    private function synchronize(GoogleCalendarService $service): void
    {
        $appointment = Appointment::query()
            ->with(['slot.doctorProfile.googleCalendarConnection', 'googleCalendarEvent'])
            ->find($this->appointmentId);

        if (! $appointment) {
            return;
        }

        $event = $appointment->googleCalendarEvent;

        if ($this->operation === 'delete') {
            $this->deleteEvent($event, $service);

            return;
        }

        if ($appointment->status === 'completed') {
            // Completed appointments keep their existing event as calendar history; nothing is created or removed.
            return;
        }

        if ($appointment->status !== 'confirmed') {
            $this->deleteEvent($event, $service);

            return;
        }

        $connection = $appointment->slot->doctorProfile->googleCalendarConnection;

        if (! $connection?->is_connected) {
            return;
        }

        if ($event && $event->google_calendar_connection_id !== $connection->id) {
            $event->update([
                'google_calendar_connection_id' => $connection->id,
                'google_event_id' => null,
                'status' => 'pending',
                'last_error' => null,
                'synced_at' => null,
            ]);
            $event->refresh();
        }

        $event ??= AppointmentGoogleCalendarEvent::query()->create([
            'appointment_id' => $appointment->id,
            'google_calendar_connection_id' => $connection->id,
            'status' => 'pending',
        ]);

        try {
            if ($event->google_event_id) {
                $service->updateEvent($connection, $appointment, $event->google_event_id);
            } else {
                $event->update(['google_event_id' => $service->createEvent($connection, $appointment)]);
            }

            $event->update(['status' => 'synced', 'last_error' => null, 'synced_at' => now()]);
            $connection->update(['last_synced_at' => now()]);
        } catch (GoogleCalendarAuthorizationException) {
            $event->update(['status' => 'failed', 'last_error' => self::RECONNECT_ERROR]);
            $this->markNeedsReconnection($connection);
        } catch (GoogleServiceException|RuntimeException $exception) {
            $event->update(['status' => 'failed', 'last_error' => 'Google Calendar synchronization failed.']);

            Log::warning('Google Calendar appointment synchronization failed.', [
                'appointment_id' => $appointment->id,
                'operation' => $this->operation,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    private function deleteEvent(
        ?AppointmentGoogleCalendarEvent $event,
        GoogleCalendarService $service
    ): void {
        if (! $event?->google_event_id) {
            // Never created in Google, so there is nothing to remove.
            $event?->update([
                'status' => 'deleted',
                'last_error' => null,
                'synced_at' => now(),
            ]);

            return;
        }

        if ($event->status === 'deleted') {
            return;
        }

        $connection = $event->connection;

        if (! $connection?->is_connected) {
            // The event is still in the doctor's Google Calendar; remove it once they reconnect.
            $event->update(['status' => self::STATUS_DELETE_PENDING, 'last_error' => self::RECONNECT_ERROR]);

            return;
        }

        try {
            $service->deleteEvent($connection, $event->google_event_id);
            $event->update([
                'status' => 'deleted',
                'last_error' => null,
                'synced_at' => now(),
            ]);
        } catch (GoogleCalendarAuthorizationException) {
            $event->update(['status' => self::STATUS_DELETE_PENDING, 'last_error' => self::RECONNECT_ERROR]);
            $this->markNeedsReconnection($connection);
        } catch (GoogleServiceException|RuntimeException $exception) {
            $event->update([
                'status' => 'failed',
                'last_error' => 'Google Calendar deletion failed.',
            ]);

            Log::warning('Google Calendar appointment deletion failed.', [
                'appointment_id' => $event->appointment_id,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    /**
     * Keep the connection (so the doctor sees that it needs reconnecting) but stop using it. Logged once, without
     * tokens or the Google account address.
     */
    private function markNeedsReconnection(GoogleCalendarConnection $connection): void
    {
        $changed = GoogleCalendarConnection::query()
            ->whereKey($connection->id)
            ->where('is_connected', true)
            ->update(['is_connected' => false, 'updated_at' => now()]);

        if ($changed === 1) {
            Log::warning('Google Calendar authorization was lost; the doctor must reconnect.', [
                'doctor_profile_id' => $connection->doctor_profile_id,
                'appointment_id' => $this->appointmentId,
            ]);
        }
    }
}
