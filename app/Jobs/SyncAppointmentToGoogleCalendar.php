<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\AppointmentGoogleCalendarEvent;
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

class SyncAppointmentToGoogleCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

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
        if (! $event?->google_event_id || ! $event->connection?->is_connected) {
            $event?->update([
                'status' => 'deleted',
                'last_error' => null,
                'synced_at' => now(),
            ]);

            return;
        }

        try {
            $service->deleteEvent($event->connection, $event->google_event_id);
            $event->update([
                'status' => 'deleted',
                'last_error' => null,
                'synced_at' => now(),
            ]);
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
}
