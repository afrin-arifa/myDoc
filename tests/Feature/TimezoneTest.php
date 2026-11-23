<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\DoctorSlotGenerator;
use App\Services\GoogleCalendarService;
use App\Services\TimezoneConversionPlan;
use Carbon\CarbonImmutable;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithAppointmentSms;
use Tests\TestCase;

/**
 * Release 1, Phase 2: the application runs in Asia/Dhaka. Doctors' wall-clock times are stored and shown unchanged,
 * every "now" comparison uses Dhaka time, and the prepared UTC-to-Dhaka data conversion never touches slot or
 * availability times.
 */
class TimezoneTest extends TestCase
{
    use InteractsWithAppointmentSms;
    use RefreshDatabase;

    private const DHAKA = 'Asia/Dhaka';

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr. Dhaka Clock']);
        DoctorProfile::create([
            'user_id' => $this->doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$this->doctor->id,
            'consultation_fee' => 50,
            'is_accepting_patients' => true,
        ]);
        $this->doctor->load('doctorProfile');
    }

    public function test_the_application_php_and_mysql_connection_use_dhaka_time(): void
    {
        $this->assertSame(self::DHAKA, config('app.timezone'));
        $this->assertSame(self::DHAKA, date_default_timezone_get());
        $this->assertSame('+06:00', now()->format('P'));
        $this->assertSame('+06:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+06:00', TimezoneConversionPlan::SESSION_TIME_ZONE);
    }

    public function test_generated_slots_and_availability_keep_the_doctors_local_times(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', self::DHAKA));
        $availability = DoctorAvailability::create(['doctor_profile_id' => $this->profile()->id, 'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '09:30', 'slot_duration_minutes' => 10, 'is_active' => true]);

        app(DoctorSlotGenerator::class)->generateRange($this->profile(), CarbonImmutable::parse('2026-11-17', self::DHAKA), CarbonImmutable::parse('2026-11-17', self::DHAKA), [2]);

        $this->assertSame(['2026-11-17 09:00:00', '2026-11-17 09:10:00', '2026-11-17 09:20:00'], DB::table('appointment_slots')->orderBy('starts_at')->pluck('starts_at')->all());
        $times = DB::table('doctor_availabilities')->where('id', $availability->id)->first(['start_time', 'end_time']);
        $this->assertSame(['09:00', '09:30'], [substr((string) $times->start_time, 0, 5), substr((string) $times->end_time, 0, 5)], 'Availability times are stored exactly as entered.');

        $slot = AppointmentSlot::query()->orderBy('starts_at')->first();
        $this->assertSame(self::DHAKA, $slot->starts_at->getTimezone()->getName());
        $this->assertSame('2026-11-17T03:00:00+00:00', $slot->starts_at->copy()->utc()->toIso8601String(), 'Dhaka 09:00 is the instant 03:00 UTC.');

        $this->actingAs(User::factory()->create(['role' => 'patient']))->get(route('doctors.show', $this->profile()))->assertOk()
            ->assertSee('Tuesday, Nov 17, 2026')
            ->assertSee('aria-label="Book Tue, Nov 17, 2026 at 9:00 AM"', false);
    }

    public function test_system_timestamps_are_written_in_dhaka_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
        $slot = $this->slot('2026-11-18 10:00');
        $patient = User::factory()->create(['role' => 'patient']);

        $appointment = app(AppointmentBookingService::class)->book($patient, $slot->id, 'physical');

        $stored = DB::table('appointments')->where('id', $appointment->id)->first(['booked_at', 'created_at']);
        $this->assertSame('2026-11-17 09:00:00', (string) $stored->booked_at, 'Booked at 03:00 UTC is stored as 09:00 Dhaka.');
        $this->assertSame('2026-11-17 09:00:00', (string) $stored->created_at);
        $this->assertSame('2026-11-18 10:00:00', (string) DB::table('appointment_slots')->where('id', $slot->id)->value('starts_at'), 'The slot time is unchanged.');
    }

    public function test_booking_and_slot_generation_cut_off_at_the_current_dhaka_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', self::DHAKA));
        $patient = User::factory()->create(['role' => 'patient']);
        $booking = app(AppointmentBookingService::class);

        try {
            $booking->book($patient, $this->slot('2026-11-17 09:50')->id, 'physical');
            $this->fail('A slot that started ten minutes ago in Dhaka must not be bookable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slot', $exception->errors());
        }
        $this->assertSame('booked', $booking->book($patient, $this->slot('2026-11-17 10:10')->id, 'physical')->status);

        DoctorAvailability::create(['doctor_profile_id' => $this->profile()->id, 'day_of_week' => 2, 'start_time' => '09:30', 'end_time' => '10:30', 'slot_duration_minutes' => 10, 'is_active' => true]);
        app(DoctorSlotGenerator::class)->generateRange($this->profile(), CarbonImmutable::parse('2026-11-17', self::DHAKA), CarbonImmutable::parse('2026-11-17', self::DHAKA), [2]);
        $this->assertSame(['2026-11-17 09:50:00', '2026-11-17 10:10:00', '2026-11-17 10:20:00'], DB::table('appointment_slots')->orderBy('starts_at')->pluck('starts_at')->all(), 'Only future Dhaka times are generated; existing slots are kept.');
    }

    public function test_patient_cancellation_deadline_is_24_hours_before_the_dhaka_start(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', self::DHAKA));
        $patient = User::factory()->create(['role' => 'patient']);
        $inTime = $this->appointment('2026-11-18 10:30', $patient);
        $tooLate = $this->appointment('2026-11-18 09:30', $patient);

        $this->assertSame('2026-11-17 10:30', Appointment::patientCancellationDeadline($inTime->slot->starts_at)->format('Y-m-d H:i'));
        $this->assertTrue($inTime->isCancellableByPatient());
        $this->assertFalse($tooLate->isCancellableByPatient());

        $this->actingAs($patient)->post(route('appointments.cancel', $tooLate))->assertSessionHasErrors('appointment');
        $this->actingAs($patient)->post(route('appointments.cancel', $inTime))->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'booked'], [$inTime->fresh()->status, $tooLate->fresh()->status]);
    }

    public function test_reminders_are_queued_for_the_dhaka_start_time(): void
    {
        $this->fakeSms();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 08:05', self::DHAKA));
        $patient = $this->smsPatient();
        $doctor = $this->smsDoctor();
        $soon = $this->smsAppointment($doctor, $patient, CarbonImmutable::parse('2026-11-17 10:00', self::DHAKA), 'confirmed');
        $evening = $this->smsAppointment($doctor, $patient, CarbonImmutable::parse('2026-11-17 16:00', self::DHAKA), 'confirmed');
        foreach ([$soon, $evening] as $appointment) {
            $appointment->forceFill(['created_at' => now()->subDays(3)])->save();
        }

        $this->artisan('mydoc:queue-appointment-reminders')->assertSuccessful();

        $reminders = AppointmentNotification::query()->where('type', 'reminder')->get();
        $this->assertSame([$soon->id], $reminders->pluck('appointment_id')->all(), 'Only the 10:00 appointment is two hours away; 16:00 is not, although 16:00 Dhaka minus six hours would be.');
        $this->assertSame(CarbonImmutable::parse('2026-11-17 04:00', 'UTC')->getTimestamp(), (int) $reminders->sole()->context['starts_at']);
        $this->assertStringContainsString('10:00 AM', $this->sms->sent[0]['message']);
    }

    public function test_rescheduling_keeps_the_new_local_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00', self::DHAKA));
        $appointment = $this->appointment('2026-11-18 10:00', User::factory()->create(['role' => 'patient']), 'confirmed');
        $newSlot = $this->slot('2026-11-19 15:30');

        $this->actingAs($this->doctor)->post(route('doctor.appointments.reschedule.store', $appointment), ['slot_id' => $newSlot->id])
            ->assertSessionHasNoErrors();

        $history = DB::table('appointment_reschedule_histories')->where('appointment_id', $appointment->id)->first();
        $this->assertSame(['2026-11-18 10:00:00', '2026-11-19 15:30:00'], [(string) $history->previous_starts_at, (string) $history->new_starts_at]);
        $this->actingAs($this->doctor)->get(route('doctor.appointments.show', $appointment))->assertOk()->assertSee('3:30 PM');
    }

    public function test_google_calendar_receives_the_dhaka_time_and_timezone(): void
    {
        $appointment = $this->appointment('2026-11-17 09:00', User::factory()->create(['role' => 'patient']), 'confirmed');
        $connection = GoogleCalendarConnection::create(['doctor_profile_id' => $this->profile()->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour()]);
        $captured = null;
        $events = \Mockery::mock();
        $events->shouldReceive('insert')->once()->withArgs(function (string $calendarId, Event $event) use (&$captured): bool {
            $captured = $event;

            return true;
        });
        $calendar = \Mockery::mock(Calendar::class);
        $calendar->events = $events;

        (new GoogleCalendarService($calendar))->createEvent($connection, $appointment);

        $this->assertSame('2026-11-17T09:00:00+06:00', $captured->getStart()->getDateTime());
        $this->assertSame('2026-11-17T09:10:00+06:00', $captured->getEnd()->getDateTime());
        $this->assertSame(self::DHAKA, $captured->getStart()->getTimeZone());
    }

    public function test_today_and_date_filters_follow_the_dhaka_calendar_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 00:05', self::DHAKA));
        $justAfterMidnight = $this->slot('2026-11-18 00:30');
        $lateYesterday = $this->slot('2026-11-17 23:50');
        $tomorrow = $this->slot('2026-11-19 00:10');
        $patient = User::factory()->create(['role' => 'patient']);

        $this->assertTrue(today()->isSameDay(CarbonImmutable::parse('2026-11-18', self::DHAKA)), 'It is already the 18th in Dhaka (still the 17th in UTC).');
        $this->actingAs($patient)->get(route('doctors.index', ['filter' => 'today']))->assertOk()->assertSee('Dr. Dhaka Clock');

        $created = $this->actingAs($this->doctor)->get(route('doctor.appointments.create'))->assertOk()->viewData('slots');
        $this->assertSame([$justAfterMidnight->id], $created->modelKeys());

        $day = $this->actingAs($this->doctor)->get(route('doctor.schedule', ['view' => 'day', 'date' => '2026-11-18']))->assertOk();
        $this->assertSame([$justAfterMidnight->id], $day->viewData('slots')->modelKeys(), 'The day view holds only the 18th in Dhaka.');
        $this->assertSame(['2026-11-18'], $day->viewData('slotsByDate')->keys()->all());
        $this->assertNotContains($lateYesterday->id, $day->viewData('slots')->modelKeys());
        $this->assertNotContains($tomorrow->id, $day->viewData('slots')->modelKeys());
    }

    public function test_the_prepared_conversion_never_touches_wall_clock_values_and_is_not_run_automatically(): void
    {
        $utcColumns = app(TimezoneConversionPlan::class)->utcColumns();
        foreach (TimezoneConversionPlan::WALL_CLOCK_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertNotContains("{$table}.{$column}", $utcColumns);
            }
        }

        $defaultMigrations = array_keys(app('migrator')->getMigrationFiles([database_path('migrations')]));
        $this->assertNotContains(TimezoneConversionPlan::MIGRATION, $defaultMigrations, 'php artisan migrate never picks up the conversion.');
        $this->assertFileExists(base_path(TimezoneConversionPlan::MIGRATION_PATH.'/'.TimezoneConversionPlan::MIGRATION.'.php'));
        $this->assertFalse(app(TimezoneConversionPlan::class)->alreadyConverted());
    }

    public function test_the_report_is_read_only_and_flags_values_written_after_the_switch(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', self::DHAKA));
        $appointment = $this->appointment('2026-11-18 10:00', User::factory()->create(['role' => 'patient']), 'confirmed');
        DoctorAvailability::create(['doctor_profile_id' => $this->profile()->id, 'day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '12:00', 'slot_duration_minutes' => 10, 'is_active' => true]);
        $before = $this->snapshot();

        $this->artisan('mydoc:timezone-report', ['--preview' => true])->assertSuccessful();
        $this->assertSame([], app(TimezoneConversionPlan::class)->apply(), 'The conversion only ever runs on MySQL.');

        $this->assertSame($before, $this->snapshot(), 'The report and the non-MySQL conversion change nothing.');
        $this->assertArrayHasKey('appointments.created_at', app(TimezoneConversionPlan::class)->valuesWrittenAfterSwitch(), 'A row written now (10:00 Dhaka) is later than 04:00 UTC, so a conversion would refuse to shift it twice.');

        DB::table('appointments')->where('id', $appointment->id)->update(['created_at' => '2026-11-17 03:00:00', 'updated_at' => '2026-11-17 03:00:00', 'booked_at' => '2026-11-17 03:00:00']);
        $this->assertArrayNotHasKey('appointments.created_at', app(TimezoneConversionPlan::class)->valuesWrittenAfterSwitch(), 'A UTC value from before the switch is never later than UTC now.');
    }

    public function test_the_report_preview_shows_the_proposed_conversion_before_it_has_run(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', self::DHAKA));
        $appointment = $this->appointment('2026-11-18 10:00', User::factory()->create(['role' => 'patient']));
        DB::table('appointments')->where('id', $appointment->id)->update(['booked_at' => '2026-11-17 03:00:00']);

        $output = $this->reportOutput(['--preview' => true]);

        $this->assertStringContainsString('B  UTC system timestamp: +6h on conversion', $output);
        $this->assertStringContainsString('with the proposed conversion', $output);
        $this->assertStringContainsString('2026-11-17 09:00:00 Dhaka', $output, 'The stored 03:00 UTC value is previewed as 09:00 Dhaka.');
        $this->assertStringContainsString('(would block the conversion)', $output);
        $this->assertStringNotContainsString('Converted (Asia/Dhaka)', $output);
        $this->assertStringNotContainsString('already been applied', $output);
        $this->assertStringNotContainsString('0 values pending', $output);
    }

    public function test_the_report_shows_converted_values_and_no_further_preview_once_the_conversion_has_run(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', self::DHAKA));
        $appointment = $this->appointment('2026-11-18 10:00', User::factory()->create(['role' => 'patient']));
        DB::table('appointments')->where('id', $appointment->id)->update(['booked_at' => '2026-11-17 09:00:00']);
        DB::table('migrations')->insert(['migration' => TimezoneConversionPlan::MIGRATION, 'batch' => 99]);
        $this->assertTrue(app(TimezoneConversionPlan::class)->alreadyConverted());
        $before = $this->snapshot();

        foreach ([['--preview' => true], []] as $options) {
            $output = $this->reportOutput($options);

            $this->assertStringContainsString('B  Converted (Asia/Dhaka)', $output);
            $this->assertStringContainsString('0 values pending conversion', $output);
            $this->assertStringContainsString('already converted', $output);
            $this->assertStringNotContainsString('+6h on conversion', $output);
            $this->assertStringNotContainsString('would move forward', $output);
            $this->assertStringNotContainsString('After conversion', $output);
            $this->assertStringNotContainsString('2026-11-17 15:00:00', $output, 'No hypothetical second +6 hour value is shown.');
            $this->assertStringNotContainsString('migrate --path', $output);
            $options === []
                ? $this->assertStringNotContainsString('No further conversion is available', $output)
                : $this->assertStringContainsString('No further conversion is available', $output);
        }

        $this->assertSame($before, $this->snapshot(), 'The report changes nothing.');
    }

    private function profile(): DoctorProfile
    {
        return $this->doctor->doctorProfile;
    }

    private function slot(string $dhakaStart, string $status = 'available'): AppointmentSlot
    {
        $startsAt = CarbonImmutable::parse($dhakaStart, self::DHAKA);

        return AppointmentSlot::create(['doctor_profile_id' => $this->profile()->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(10), 'status' => $status]);
    }

    private function appointment(string $dhakaStart, User $patient, string $status = 'booked'): Appointment
    {
        return Appointment::create([
            'appointment_slot_id' => $this->slot($dhakaStart, 'booked')->id,
            'patient_id' => $patient->id,
            'status' => $status,
            'consultation_type' => 'physical',
            'booked_at' => now(),
        ])->load('slot');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function reportOutput(array $options): string
    {
        $this->assertSame(0, Artisan::call('mydoc:timezone-report', $options));

        return Artisan::output();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        return collect(['appointment_slots', 'appointments', 'doctor_availabilities', 'users'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
            ->all();
    }
}
