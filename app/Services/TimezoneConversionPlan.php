<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one-off move from a UTC application to Asia/Dhaka, classified column by column.
 *
 * Until Release 1 Phase 2 the application ran in UTC while the clinic works in Dhaka, so two kinds of values exist:
 *  A. Wall-clock values typed by doctors (slot times, availability times, reschedule copies of slot times). They
 *     already mean Dhaka local time and must never change.
 *  B. System timestamps written by Laravel with now() (created_at, booked_at, sent_at, ...). They hold UTC wall-clock
 *     values and need +6 hours to become Dhaka wall-clock values.
 *  C. Unix timestamps (sessions, cache, queue). They are timezone-free and never change.
 *  D. Values inside JSON (audit and notification context). They are reported, never converted.
 *
 * Every column in B is a MySQL TIMESTAMP. MySQL converts TIMESTAMP values with the session time zone on every read
 * and write, so the conversion runs with the session pinned to +06:00 (the same offset the application connection
 * uses, and the server's SYSTEM zone when the data was written): the stored instant then moves by exactly six hours
 * and reads back as the Dhaka wall-clock time. Bangladesh has no daylight saving, so a fixed offset is exact.
 */
class TimezoneConversionPlan
{
    public const FROM_TIMEZONE = 'UTC';

    public const TO_TIMEZONE = 'Asia/Dhaka';

    public const OFFSET_HOURS = 6;

    /**
     * MySQL session time zone used for the conversion; matches config('database.connections.mysql.timezone').
     */
    public const SESSION_TIME_ZONE = '+06:00';

    /**
     * Name of the prepared migration that applies the conversion (kept outside the default migration path).
     */
    public const MIGRATION = '2026_11_17_210707_convert_utc_system_timestamps_to_asia_dhaka';

    public const MIGRATION_PATH = 'database/migrations/timezone';

    /**
     * Category A: Dhaka wall-clock values. Never converted.
     *
     * @var array<string, list<string>>
     */
    public const WALL_CLOCK_COLUMNS = [
        'appointment_slots' => ['starts_at', 'ends_at'],
        'doctor_availabilities' => ['start_time', 'end_time'],
        'appointment_reschedule_histories' => ['previous_starts_at', 'new_starts_at'],
    ];

    /**
     * Category B: UTC system timestamps written by Laravel. Converted by +6 hours.
     *
     * @var array<string, list<string>>
     */
    public const UTC_TIMESTAMP_COLUMNS = [
        'users' => ['email_verified_at', 'deactivated_at', 'created_at', 'updated_at'],
        'password_reset_tokens' => ['created_at'],
        'specialties' => ['created_at', 'updated_at'],
        'doctor_profiles' => ['created_at', 'updated_at'],
        'doctor_availabilities' => ['created_at', 'updated_at'],
        'appointment_slots' => ['created_at', 'updated_at'],
        'appointments' => ['booked_at', 'cancelled_at', 'created_at', 'updated_at'],
        'appointment_reschedule_histories' => ['created_at', 'updated_at'],
        'appointment_notifications' => ['sent_at', 'provider_requested_at', 'created_at', 'updated_at'],
        'google_calendar_connections' => ['token_expires_at', 'last_synced_at', 'created_at', 'updated_at'],
        'appointment_google_calendar_events' => ['synced_at', 'created_at', 'updated_at'],
        'doctor_invitation_tokens' => ['created_at'],
        'assistant_invitation_tokens' => ['created_at'],
        'medical_documents' => ['created_at', 'updated_at'],
        'medical_document_events' => ['created_at'],
        'doctor_assistant' => ['created_at', 'updated_at'],
        'assistant_assignment_events' => ['created_at'],
        'doctor_workspace_events' => ['created_at'],
        'failed_jobs' => ['failed_at'],
    ];

    /**
     * Category B columns that legitimately hold future values, so they are left out of the "written after the
     * switch" check (a token expiry is written together with its row's updated_at, which is checked).
     *
     * @var list<string>
     */
    public const FUTURE_VALUED_COLUMNS = ['google_calendar_connections.token_expires_at'];

    /**
     * Category C: Unix timestamps. Never converted.
     *
     * @var array<string, list<string>>
     */
    public const UNIX_TIMESTAMP_COLUMNS = [
        'sessions' => ['last_activity'],
        'cache' => ['expiration'],
        'cache_locks' => ['expiration'],
        'jobs' => ['reserved_at', 'available_at', 'created_at'],
        'job_batches' => ['cancelled_at', 'created_at', 'finished_at'],
    ];

    /**
     * Category D: times inside JSON or derived keys. Reported, never converted.
     *
     * @var array<string, string>
     */
    public const JSON_CONTEXT_COLUMNS = [
        'doctor_workspace_events.context' => 'ISO-8601 strings with an explicit offset (self-describing); slot times recorded before the switch carry +00:00 although they meant Dhaka time',
        'appointment_notifications.context' => 'reminder starts_at is a Unix timestamp of the slot start as interpreted when queued; reminders recorded before the switch no longer match and are skipped as outdated',
        'appointment_notifications.transition_key' => 'reminder keys embed the same Unix timestamp',
    ];

    /**
     * Minutes of clock skew tolerated before a value counts as "written after the switch".
     */
    public const SKEW_MINUTES = 5;

    public function connection(): Connection
    {
        return DB::connection();
    }

    public function supportsConversion(): bool
    {
        return in_array($this->connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Whether the prepared conversion migration has already been run on this database.
     */
    public function alreadyConverted(): bool
    {
        $schema = $this->connection()->getSchemaBuilder();

        return $schema->hasTable('migrations')
            && $this->connection()->table('migrations')->where('migration', self::MIGRATION)->exists();
    }

    /**
     * Category B columns that exist in this database, as "table.column".
     *
     * @return list<string>
     */
    public function utcColumns(): array
    {
        $schema = $this->connection()->getSchemaBuilder();
        $columns = [];

        foreach (self::UTC_TIMESTAMP_COLUMNS as $table => $tableColumns) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            foreach ($tableColumns as $column) {
                if ($schema->hasColumn($table, $column)) {
                    $columns[] = "{$table}.{$column}";
                }
            }
        }

        return $columns;
    }

    /**
     * Non-null values per category B column.
     *
     * @return array<string, int>
     */
    public function affectedCounts(): array
    {
        $counts = [];
        foreach ($this->utcColumns() as $qualified) {
            [$table, $column] = explode('.', $qualified);
            $counts[$qualified] = $this->connection()->table($table)->whereNotNull($column)->count();
        }

        return $counts;
    }

    /**
     * Category B values later than the current UTC time (plus a little skew). A UTC system timestamp can never be in
     * the future, so such values were written in Dhaka time after the switch and must not be shifted again.
     *
     * @return array<string, int>
     */
    public function valuesWrittenAfterSwitch(): array
    {
        $bound = now(self::FROM_TIMEZONE)->addMinutes(self::SKEW_MINUTES)->format('Y-m-d H:i:s');
        $found = [];

        foreach ($this->utcColumns() as $qualified) {
            if (in_array($qualified, self::FUTURE_VALUED_COLUMNS, true)) {
                continue;
            }
            [$table, $column] = explode('.', $qualified);
            $count = $this->connection()->table($table)->where($column, '>', $bound)->count();
            if ($count > 0) {
                $found[$qualified] = $count;
            }
        }

        return $found;
    }

    /**
     * Apply the conversion: every category B value moves forward by six hours, in one transaction. Category A, C and
     * D values are untouched. Refuses to run when values written after the switch are present.
     *
     * @return array<string, int> Rows updated per column.
     *
     * @throws RuntimeException
     */
    public function apply(): array
    {
        return $this->shift(self::OFFSET_HOURS);
    }

    /**
     * Undo apply() by moving the same columns back six hours. Only correct if nothing was written after apply();
     * otherwise restore the backup taken before the conversion.
     *
     * @return array<string, int>
     */
    public function revert(): array
    {
        return $this->shift(-self::OFFSET_HOURS);
    }

    /**
     * @return array<string, int>
     */
    private function shift(int $hours): array
    {
        if (! $this->supportsConversion()) {
            return [];
        }

        $connection = $this->connection();
        $connection->statement("SET time_zone = '".self::SESSION_TIME_ZONE."'");

        if ($hours > 0 && ($late = $this->valuesWrittenAfterSwitch()) !== []) {
            throw new RuntimeException('Timezone conversion refused: these columns already hold values written in Dhaka time after the switch, which would be shifted twice: '.json_encode($late).'. Run php artisan mydoc:timezone-report and restore from backup or convert manually.');
        }

        return $connection->transaction(function () use ($connection, $hours): array {
            $updated = [];
            foreach ($this->utcColumns() as $qualified) {
                [$table, $column] = explode('.', $qualified);
                $updated[$qualified] = $connection->table($table)
                    ->whereNotNull($column)
                    ->update([$column => $connection->raw(sprintf('DATE_ADD(`%s`, INTERVAL %d HOUR)', $column, $hours))]);
            }

            return $updated;
        });
    }
}
