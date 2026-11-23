<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentNotification;
use App\Jobs\SyncAppointmentToGoogleCalendar;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\DevCommands;
use Tests\TestCase;

/**
 * Phase 5G: scheduler locks, timeout ordering and the local development processes.
 */
class OperationalReadinessTest extends TestCase
{
    /**
     * Command => [cron expression, overlap lock expiry in minutes].
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const TASKS = [
        'mydoc:expire-pending-appointments' => ['* * * * *', 10],
        'mydoc:queue-appointment-reminders' => ['*/5 * * * *', 15],
        'mydoc:requeue-pending-notifications' => ['*/15 * * * *', 30],
        'mydoc:reconcile-google-calendar' => ['0 * * * *', 55],
        'mydoc:process-waitlist' => ['* * * * *', 10],
    ];

    public function test_every_task_runs_on_one_server_without_overlapping_and_its_lock_expires_quickly(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'mydoc:'))
            ->keyBy(fn (Event $event): string => collect(self::TASKS)->keys()->first(fn (string $command): bool => str_contains((string) $event->command, $command)));

        $this->assertEqualsCanonicalizing(array_keys(self::TASKS), $events->keys()->all(), 'Each myDoc task is scheduled exactly once.');

        foreach (self::TASKS as $command => [$expression, $lockMinutes]) {
            $event = $events[$command];
            $this->assertSame($expression, $event->expression, $command);
            $this->assertTrue($event->withoutOverlapping, "{$command} never overlaps itself.");
            $this->assertTrue($event->onOneServer, "{$command} runs on one server only.");
            $this->assertSame($lockMinutes, $event->expiresAt, "{$command} does not keep Laravel's 24-hour default lock.");
            $this->assertLessThan(1440, $event->expiresAt);
        }
    }

    public function test_timeouts_keep_their_required_order(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $emailJob = new SendAppointmentNotification(1);
        $calendarJob = new SyncAppointmentToGoogleCalendar(1, 'sync');

        $this->assertLessThan($emailJob->timeout, (int) config('mail.mailers.smtp.timeout'), 'SMTP timeout < email job timeout.');
        $this->assertLessThan($emailJob->timeout, (int) config('sms.timeout') + (int) config('sms.connect_timeout'), 'SMS request timeout < job timeout.');
        $this->assertLessThan($calendarJob->timeout, 3 * ((float) config('services.google.calendar.timeout') + (float) config('services.google.calendar.connect_timeout')) + 10, 'Three Google requests plus the lock wait fit in the job timeout.');
        $this->assertLessThan($retryAfter, $emailJob->timeout, 'Job timeout < retry_after, so a running job is never handed to a second worker.');
        $this->assertLessThan($retryAfter, $calendarJob->timeout);
        $this->assertLessThan(SendAppointmentNotification::STALE_CLAIM_MINUTES * 60, $retryAfter);
    }

    public function test_the_example_environment_ships_production_safe_defaults(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        foreach ([
            'QUEUE_CONNECTION=database' => 'delivery runs in the background, never "sync"',
            'CACHE_STORE=database' => 'scheduler and calendar locks use a shared store',
            'DB_TIMEZONE=+06:00' => 'the MySQL session time zone matches Asia/Dhaka',
            'DB_QUEUE_RETRY_AFTER=90' => 'retry_after stays above the job timeouts',
            'MAIL_TIMEOUT=20' => 'the SMTP timeout stays below the email job timeout',
            'SMS_DRIVER=none' => 'no SMS is sent until a provider is configured',
        ] as $line => $reason) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($line, '/').'$/m', $example, $reason);
        }

        foreach (['APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'BULKSMSBD_API_KEY', 'GOOGLE_CALENDAR_CLIENT_SECRET'] as $secret) {
            $this->assertMatchesRegularExpression('/^'.$secret.'=(null)?$/m', $example, "{$secret} is left empty in the example.");
        }
    }

    public function test_composer_run_dev_also_starts_the_scheduler(): void
    {
        $commands = collect(DevCommands::commands())->keyBy('name');

        $this->assertSame('php artisan schedule:work', $commands['scheduler']['command'] ?? null);
        $this->assertArrayHasKey('queue', $commands->all());
        $this->assertArrayHasKey('server', $commands->all());
    }
}
