<?php

namespace App\Console\Commands;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentGoogleCalendarEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Brings Google Calendar back in line with myDoc for doctors whose calendar is connected, limited to appointments
 * that have not started yet:
 *  - a confirmed appointment with no event, or whose sync failed or never finished, is synced again;
 *  - an event that still exists for a cancelled, rejected or expired appointment is removed.
 *
 * Only records untouched for STALE_AFTER_MINUTES are picked up, so jobs that are still retrying are left alone. The
 * jobs re-read the appointment under a per-appointment lock and event ids are deterministic, so a duplicate job is
 * harmless. Disconnected calendars are skipped until the doctor reconnects.
 */
#[Signature('mydoc:reconcile-google-calendar')]
#[Description('Re-sync failed Google Calendar events and remove stale ones for upcoming appointments')]
class ReconcileGoogleCalendarEvents extends Command
{
    public const STALE_AFTER_MINUTES = 30;

    /**
     * Event record statuses that may still have, or still be missing, a Google event.
     *
     * @var list<string>
     */
    private const UNSETTLED_STATUSES = ['pending', 'failed', SyncAppointmentToGoogleCalendar::STATUS_DELETE_PENDING];

    public function handle(): int
    {
        $staleBefore = now()->subMinutes(self::STALE_AFTER_MINUTES);
        $upcomingOnConnectedCalendar = fn (Builder $slots) => $slots
            ->where('starts_at', '>', now())
            ->whereHas('doctorProfile.googleCalendarConnection', fn (Builder $connection) => $connection->where('is_connected', true));

        $synced = 0;
        Appointment::query()
            ->where('status', 'confirmed')
            ->whereHas('slot', $upcomingOnConnectedCalendar)
            ->where(fn (Builder $appointments) => $appointments
                ->whereDoesntHave('googleCalendarEvent', fn (Builder $event) => $event->where('updated_at', '>=', $staleBefore))
                ->whereDoesntHave('googleCalendarEvent', fn (Builder $event) => $event->where('status', 'synced')))
            ->select('appointments.id')
            ->chunkById(200, function ($appointments) use (&$synced): void {
                foreach ($appointments as $appointment) {
                    SyncAppointmentToGoogleCalendar::dispatch($appointment->id, 'sync');
                    $synced++;
                }
            });

        $removed = 0;
        AppointmentGoogleCalendarEvent::query()
            ->whereIn('status', [...self::UNSETTLED_STATUSES, 'synced'])
            ->whereNotNull('google_event_id')
            ->where('updated_at', '<', $staleBefore)
            ->whereHas('appointment', fn (Builder $appointments) => $appointments
                ->whereIn('status', Appointment::CLOSED_STATUSES)
                ->whereHas('slot', $upcomingOnConnectedCalendar))
            ->select(['id', 'appointment_id'])
            ->chunkById(200, function ($events) use (&$removed): void {
                foreach ($events as $event) {
                    SyncAppointmentToGoogleCalendar::dispatch($event->appointment_id, 'delete');
                    $removed++;
                }
            });

        $this->info("Google Calendar reconciliation queued {$synced} sync(s) and {$removed} removal(s).");

        return self::SUCCESS;
    }
}
