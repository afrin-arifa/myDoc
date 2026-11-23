<?php

use App\Services\TimezoneConversionPlan;
use Illuminate\Database\Migrations\Migration;

/**
 * One-off data conversion for the switch from a UTC application to Asia/Dhaka (Release 1, Phase 2).
 *
 * Moves every UTC system timestamp written by Laravel (created_at, booked_at, sent_at, ...) forward by six hours so it
 * becomes a Dhaka wall-clock value. Slot times, availability times and reschedule copies of slot times are already
 * Dhaka wall-clock values and are never touched; Unix timestamps and JSON context are never touched either. The
 * exact column list is TimezoneConversionPlan::UTC_TIMESTAMP_COLUMNS; preview it with
 * `php artisan mydoc:timezone-report --preview`.
 *
 * Safety:
 *  - This file lives outside database/migrations, so `php artisan migrate` and the test suite never run it. It runs
 *    only with `php artisan migrate --path=database/migrations/timezone`, and the migrations table then records it,
 *    so running that command again does nothing.
 *  - It refuses to run if any of these columns already holds a value later than the current UTC time, i.e. a value
 *    written in Dhaka time after the configuration switch, which would otherwise be shifted twice.
 *  - All updates run in one transaction with the MySQL session pinned to +06:00. On non-MySQL databases it does
 *    nothing.
 *
 * Rollback: `php artisan migrate:rollback --path=database/migrations/timezone` moves the same columns back six hours.
 * That is only correct immediately after the conversion; once new rows have been written in Dhaka time, rolling back
 * would shift them too, so restore the backup taken before the conversion instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(TimezoneConversionPlan::class)->apply();
    }

    public function down(): void
    {
        app(TimezoneConversionPlan::class)->revert();
    }
};
