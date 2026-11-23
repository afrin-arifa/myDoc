<?php

namespace App\Console\Commands;

use App\Services\TimezoneConversionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Read-only report on timezone configuration and stored date/time values. It only runs SELECT queries (and the
 * connection's own session time zone setting); it never changes data. With --preview it shows what the prepared
 * conversion migration would do to the UTC system timestamps, without doing it. Once the conversion has run, the
 * category B values are reported as converted and --preview only says that no further conversion is available.
 */
class TimezoneReport extends Command
{
    protected $signature = 'mydoc:timezone-report
        {--preview : Show the proposed +6 hour conversion of UTC system timestamps (nothing is changed)}
        {--samples=3 : Representative rows to show per group}';

    protected $description = 'Report timezone settings and how stored date/time values are interpreted (read-only)';

    public function handle(TimezoneConversionPlan $plan): int
    {
        $connection = $plan->connection();
        $isMysql = $plan->supportsConversion();
        $samples = max(1, (int) $this->option('samples'));
        $converted = $plan->alreadyConverted();
        $preview = (bool) $this->option('preview') && ! $converted;

        if ($converted && $this->option('preview')) {
            $this->components->warn('The UTC-to-Asia/Dhaka conversion has already been applied to this database. No further conversion is available, so nothing is previewed.');
        }

        $this->components->info('Timezone configuration');
        $settings = [
            ['Application timezone (config app.timezone)', (string) config('app.timezone')],
            ['PHP default timezone', date_default_timezone_get()],
            ['Database driver', $connection->getDriverName()],
            ['Connection time_zone setting', (string) ($connection->getConfig('timezone') ?? '(server default)')],
        ];
        if ($isMysql) {
            $server = $connection->selectOne('SELECT @@session.time_zone AS session_tz, @@global.time_zone AS global_tz, @@system_time_zone AS system_tz, NOW() AS session_now, UTC_TIMESTAMP() AS utc_now');
            $settings[] = ['MySQL session time zone', (string) $server->session_tz];
            $settings[] = ['MySQL global time zone', (string) $server->global_tz];
            $settings[] = ['Database server (system) time zone', (string) $server->system_tz];
            $settings[] = ['MySQL NOW() in this session', (string) $server->session_now];
            $settings[] = ['MySQL UTC_TIMESTAMP()', (string) $server->utc_now];
        }
        $settings[] = ['Application now()', now()->toDateTimeString().' ('.now()->format('P').')'];
        $this->table(['Setting', 'Value'], $settings);

        if ($isMysql) {
            $this->components->info('Column types (TIMESTAMP columns are converted by MySQL using the session time zone)');
            $this->table(['Category', 'Column', 'Type', 'Default', 'Session-zone conversion'], $this->columnTypes($plan));
        }

        $this->components->info('Classification');
        $counts = $plan->affectedCounts();
        $rows = [];
        foreach (TimezoneConversionPlan::WALL_CLOCK_COLUMNS as $table => $columns) {
            $rows[] = ['A  Dhaka wall-clock: never converted', $table.'.'.implode(', ', $columns), $this->rowCount($table)];
        }
        foreach ($counts as $qualified => $count) {
            $rows[] = [$converted ? 'B  Converted (Asia/Dhaka)' : 'B  UTC system timestamp: +6h on conversion', $qualified, $count];
        }
        foreach (TimezoneConversionPlan::UNIX_TIMESTAMP_COLUMNS as $table => $columns) {
            $rows[] = ['C  Unix timestamp: never converted', $table.'.'.implode(', ', $columns), $this->rowCount($table)];
        }
        foreach (TimezoneConversionPlan::JSON_CONTEXT_COLUMNS as $qualified => $meaning) {
            [$table] = explode('.', $qualified);
            $rows[] = ['D  Inside JSON/keys: reported only', $qualified, $this->rowCount($table)];
        }
        $this->table(['Category', 'Column(s)', 'Rows / non-null values'], $rows);
        foreach (TimezoneConversionPlan::JSON_CONTEXT_COLUMNS as $qualified => $meaning) {
            $this->line("  <comment>{$qualified}</comment>: {$meaning}");
        }

        $this->components->info('Representative appointment slots (category A, shown as stored)');
        $this->table(['Slot id', 'starts_at', 'ends_at', 'status'], $connection->table('appointment_slots')
            ->orderByDesc('id')->limit($samples)->get(['id', 'starts_at', 'ends_at', 'status'])
            ->map(fn (object $slot): array => [$slot->id, $slot->starts_at, $slot->ends_at, $slot->status])->all());

        $this->components->info('Representative availability windows (category A, shown as stored)');
        $this->table(['Availability id', 'Doctor profile', 'Weekday', 'start_time', 'end_time'], $connection->table('doctor_availabilities')
            ->orderByDesc('id')->limit($samples)->get(['id', 'doctor_profile_id', 'day_of_week', 'start_time', 'end_time'])
            ->map(fn (object $row): array => [$row->id, $row->doctor_profile_id, $row->day_of_week, $row->start_time, $row->end_time])->all());

        $this->components->info('Representative system timestamps (category B)'.($converted ? ', already converted' : ($preview ? ' with the proposed conversion' : '')));
        $this->table(['Column', 'Row id', 'Stored value', $converted ? 'Status' : ($preview ? 'After conversion' : 'Read by the app as')], $this->systemSamples($plan, $samples, $converted, $preview));

        if ($converted) {
            $this->components->info('Values already written in Dhaka time');
            $this->line('  Not applicable: the conversion has already been applied, so every category B value is a Dhaka time.');
        } else {
            $late = $plan->valuesWrittenAfterSwitch();
            $this->components->info('Values already written in Dhaka time (would block the conversion)');
            $late === []
                ? $this->line('  None: every UTC system timestamp is earlier than the current UTC time.')
                : $this->table(['Column', 'Values later than UTC now'], collect($late)->map(fn (int $count, string $column): array => [$column, $count])->values()->all());
        }

        $this->components->info('Conversion status');
        if ($converted) {
            $this->line('  Converted (Asia/Dhaka): the conversion migration has already been run on this database. 0 values pending conversion.');
        } elseif (! $isMysql) {
            $this->line('  The conversion only applies to MySQL/MariaDB databases.');
        } else {
            $this->line('  Not converted. '.array_sum($counts).' UTC system timestamp values in '.count(array_filter($counts)).' columns would move forward by six hours.');
            $this->line('  Back up first, then run: php artisan migrate --path='.TimezoneConversionPlan::MIGRATION_PATH);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<list<string>>
     */
    private function columnTypes(TimezoneConversionPlan $plan): array
    {
        $categories = [];
        foreach (TimezoneConversionPlan::WALL_CLOCK_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $categories["{$table}.{$column}"] = 'A';
            }
        }
        foreach ($plan->utcColumns() as $qualified) {
            $categories[$qualified] = 'B';
        }

        $connection = $plan->connection();

        return collect($connection->select(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type, COLUMN_DEFAULT AS column_default
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND DATA_TYPE IN (\'datetime\', \'timestamp\', \'date\', \'time\') ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$connection->getDatabaseName()]
        ))->map(fn (object $column): array => [
            $categories["{$column->table_name}.{$column->column_name}"] ?? '?',
            "{$column->table_name}.{$column->column_name}",
            (string) $column->column_type,
            (string) ($column->column_default ?? ''),
            str_starts_with((string) $column->column_type, 'timestamp') ? 'yes' : 'no',
        ])->all();
    }

    /**
     * @return list<list<string|int>>
     */
    private function systemSamples(TimezoneConversionPlan $plan, int $samples, bool $converted, bool $preview): array
    {
        $rows = [];
        foreach (['appointments.booked_at', 'appointments.created_at', 'appointment_slots.created_at', 'users.created_at', 'medical_documents.created_at', 'google_calendar_connections.token_expires_at'] as $qualified) {
            if (! in_array($qualified, $plan->utcColumns(), true)) {
                continue;
            }
            [$table, $column] = explode('.', $qualified);
            $values = $plan->connection()->table($table)->whereNotNull($column)->orderByDesc('id')->limit($samples)->get(['id', $column]);
            foreach ($values as $row) {
                $stored = (string) $row->{$column};
                $rows[] = [$qualified, $row->id, $stored, match (true) {
                    $converted => 'Converted (Asia/Dhaka)',
                    $preview => CarbonImmutable::parse($stored)->addHours(TimezoneConversionPlan::OFFSET_HOURS)->format('Y-m-d H:i:s').' Dhaka',
                    default => $stored.' Dhaka (was '.$stored.' UTC)',
                }];
            }
        }

        return $rows;
    }

    private function rowCount(string $table): int|string
    {
        return $this->connectionHasTable($table) ? app(TimezoneConversionPlan::class)->connection()->table($table)->count() : 'n/a';
    }

    private function connectionHasTable(string $table): bool
    {
        return app(TimezoneConversionPlan::class)->connection()->getSchemaBuilder()->hasTable($table);
    }
}
