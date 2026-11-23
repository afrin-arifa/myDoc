<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Every myDoc task runs on one server at a time (onOneServer) and never overlaps itself (withoutOverlapping). Both
 * use cache locks, so CACHE_STORE must be shared by all servers (database or redis, never file or array).
 *
 * Each overlap lock expires after the given minutes instead of Laravel's default 24 hours: if the scheduler is killed
 * mid-run (deploy, crash, out of memory), the leftover lock only delays the task briefly rather than silently
 * stopping expiry, reminders or recovery for a day. Each expiry is longer than the task normally takes.
 */
Schedule::command('mydoc:expire-pending-appointments')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('mydoc:queue-appointment-reminders')->everyFiveMinutes()->withoutOverlapping(15)->onOneServer();
Schedule::command('mydoc:requeue-pending-notifications')->everyFifteenMinutes()->withoutOverlapping(30)->onOneServer();
Schedule::command('mydoc:reconcile-google-calendar')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('mydoc:process-waitlist')->everyMinute()->withoutOverlapping(10)->onOneServer();
