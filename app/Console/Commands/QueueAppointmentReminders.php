<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\AppointmentNotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues reminder SMS (by default 24 and 2 hours before an appointment) for booked or confirmed appointments.
 *
 * Runs every few minutes from the scheduler. A reminder becomes due when the appointment is within its offset and
 * stays due for a catch-up window, so a short scheduler outage does not lose it. Appointments booked after the
 * reminder time are skipped (their booking confirmation is recent). Each reminder's ledger key contains the slot
 * and start time, so re-running never duplicates a reminder and a rescheduled appointment gets fresh reminders;
 * the delivery job re-checks status, slot and start time before sending.
 */
class QueueAppointmentReminders extends Command
{
    protected $signature = 'mydoc:queue-appointment-reminders';

    protected $description = 'Queue SMS reminders for upcoming booked or confirmed appointments';

    public function handle(AppointmentNotificationService $notifications): int
    {
        $windowMinutes = max(1, (int) config('sms.reminders.window_minutes', 60));
        $queued = 0;

        foreach ((array) config('sms.reminders.hours', [24, 2]) as $hours) {
            $hours = (int) $hours;
            $latestStart = now()->addHours($hours);
            $earliestStart = $latestStart->copy()->subMinutes($windowMinutes);

            Appointment::query()
                ->whereIn('appointments.status', Appointment::ACTIVE_STATUSES)
                ->whereHas('slot', fn ($slots) => $slots
                    ->where('starts_at', '>', max($earliestStart, now()))
                    ->where('starts_at', '<=', $latestStart))
                ->whereHas('patient', fn ($patients) => $patients
                    ->where('sms_notifications_enabled', true)
                    ->whereNull('deactivated_at'))
                ->with(['slot.doctorProfile.user', 'patient'])
                ->chunkById(100, function ($appointments) use ($notifications, $hours, &$queued): void {
                    foreach ($appointments as $appointment) {
                        if ($appointment->created_at->gt($appointment->slot->starts_at->copy()->subHours($hours))) {
                            continue;
                        }

                        try {
                            DB::transaction(fn () => $notifications->recordReminder($appointment, $hours));
                            $queued++;
                        } catch (UniqueConstraintViolationException) {
                            // Another run recorded the same reminder at the same moment; it will be delivered once.
                        }
                    }
                });
        }

        $this->info("Checked appointment reminders ({$queued} considered).");

        return self::SUCCESS;
    }
}
