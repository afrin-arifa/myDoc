<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\DoctorSlotGenerator;
use ArgumentCountError;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use TypeError;

class SlotGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, 2 November 2026 at 08:00.
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 8, 0, 0, config('app.timezone')));
    }

    public function test_a_92_day_range_is_accepted_for_preview_and_generation(): void
    {
        $doctor = $this->doctorWithMondayAvailability();
        $range = ['start_date' => '2026-11-02', 'end_date' => '2027-02-01', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]];

        $this->actingAs($doctor)
            ->postJson(route('doctor.slots.preview'), $range)
            ->assertOk()
            ->assertJsonPath('total', 14 * 6);

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), $range)
            ->assertSessionHasNoErrors();

        $this->assertSame(14 * 6, AppointmentSlot::count());
    }

    public function test_ranges_longer_than_92_days_are_rejected_by_both_endpoints(): void
    {
        $doctor = $this->doctorWithMondayAvailability();
        $range = ['start_date' => '2026-11-02', 'end_date' => '2027-02-02', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]];

        $this->actingAs($doctor)
            ->postJson(route('doctor.slots.preview'), $range)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), $range)
            ->assertSessionHasErrors('end_date');

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), ['start_date' => '2026-11-02', 'end_date' => '2126-11-02', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]])
            ->assertSessionHasErrors('end_date');

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), ['days' => 93, 'weekdays' => [1, 2, 3, 4, 5, 6, 7]])
            ->assertSessionHasErrors('days');

        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_invalid_dates_and_weekdays_are_rejected(): void
    {
        $doctor = $this->doctorWithMondayAvailability();

        $invalidPayloads = [
            'end before start' => [['start_date' => '2026-11-10', 'end_date' => '2026-11-02', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]], 'end_date'],
            'malformed start' => [['start_date' => 'next monday', 'end_date' => '2026-11-10', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]], 'start_date'],
            'missing end' => [['start_date' => '2026-11-02', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]], 'end_date'],
            'empty weekdays' => [['start_date' => '2026-11-02', 'end_date' => '2026-11-10', 'weekdays' => []], 'weekdays'],
            'weekday out of range' => [['start_date' => '2026-11-02', 'end_date' => '2026-11-10', 'weekdays' => [8]], 'weekdays.0'],
            'non-numeric weekday' => [['start_date' => '2026-11-02', 'end_date' => '2026-11-10', 'weekdays' => ['monday']], 'weekdays.0'],
        ];

        foreach ($invalidPayloads as [$payload, $errorKey]) {
            $this->actingAs($doctor)
                ->postJson(route('doctor.slots.preview'), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorKey);

            $this->actingAs($doctor)
                ->post(route('doctor.slots.generate'), $payload)
                ->assertSessionHasErrors($errorKey);
        }

        $this->actingAs($doctor)
            ->postJson(route('doctor.slots.preview'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'end_date', 'weekdays']);

        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_missing_weekdays_are_rejected_and_never_treated_as_every_day(): void
    {
        $doctor = $this->doctorWithMondayAvailability();

        foreach ([['start_date' => '2026-11-02', 'end_date' => '2026-11-15'], ['days' => 14]] as $payload) {
            $this->actingAs($doctor)
                ->postJson(route('doctor.slots.preview'), [...$payload, 'start_date' => '2026-11-02', 'end_date' => '2026-11-15'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['weekdays' => 'Select at least one weekday.']);

            $this->actingAs($doctor)
                ->post(route('doctor.slots.generate'), $payload)
                ->assertSessionHasErrors(['weekdays' => 'Select at least one weekday.']);
        }

        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_empty_weekday_selections_are_rejected(): void
    {
        $doctor = $this->doctorWithMondayAvailability();

        foreach ([[], ''] as $emptySelection) {
            $payload = ['start_date' => '2026-11-02', 'end_date' => '2026-11-15', 'weekdays' => $emptySelection];

            $this->actingAs($doctor)
                ->postJson(route('doctor.slots.preview'), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('weekdays');

            $this->actingAs($doctor)
                ->post(route('doctor.slots.generate'), $payload)
                ->assertSessionHasErrors('weekdays');
        }

        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_invalid_weekday_values_are_rejected(): void
    {
        $doctor = $this->doctorWithMondayAvailability();
        $invalidSelections = [
            'zero' => [[0], 'weekdays.0'],
            'above seven' => [[1, 8], 'weekdays.1'],
            'negative' => [[-1], 'weekdays.0'],
            'word' => [['monday'], 'weekdays.0'],
            'decimal' => [['1.5'], 'weekdays.0'],
            'null entry' => [[null], 'weekdays.0'],
            'nested array' => [[[1]], 'weekdays.0'],
            'scalar instead of array' => ['1', 'weekdays'],
            'more than seven entries' => [[1, 2, 3, 4, 5, 6, 7, 1], 'weekdays'],
        ];

        foreach ($invalidSelections as [$weekdays, $errorKey]) {
            $payload = ['start_date' => '2026-11-02', 'end_date' => '2026-11-15', 'weekdays' => $weekdays];

            $this->actingAs($doctor)
                ->postJson(route('doctor.slots.preview'), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorKey);

            $this->actingAs($doctor)
                ->post(route('doctor.slots.generate'), $payload)
                ->assertSessionHasErrors($errorKey);
        }

        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_duplicate_weekdays_are_rejected(): void
    {
        $doctor = $this->doctorWithMondayAvailability();

        foreach ([[1, 1], [1, '1'], [3, 5, 3]] as $weekdays) {
            $payload = ['start_date' => '2026-11-02', 'end_date' => '2026-11-15', 'weekdays' => $weekdays];

            $this->actingAs($doctor)
                ->postJson(route('doctor.slots.preview'), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['weekdays.0' => 'Each weekday can only be selected once.']);

            $this->actingAs($doctor)
                ->post(route('doctor.slots.generate'), $payload)
                ->assertSessionHasErrors('weekdays.0');
        }

        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_service_rejects_null_or_missing_weekdays(): void
    {
        $profile = $this->doctorWithMondayAvailability()->doctorProfile;
        $generator = app(DoctorSlotGenerator::class);
        [$start, $end] = [CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-15')];

        $calls = [
            'generate with null' => fn () => $generator->generateRange($profile, $start, $end, null),
            'preview with null' => fn () => $generator->preview($profile, $start, $end, null),
            'generate without weekdays' => fn () => $generator->generateRange($profile, $start, $end),
            'preview without weekdays' => fn () => $generator->preview($profile, $start, $end),
        ];

        foreach ($calls as $description => $call) {
            try {
                $call();
                $this->fail("{$description} should be rejected.");
            } catch (TypeError|ArgumentCountError) {
                // A weekday list is a required, non-nullable argument.
            }
        }

        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_service_rejects_empty_invalid_and_duplicate_weekdays(): void
    {
        $profile = $this->doctorWithMondayAvailability()->doctorProfile;
        $generator = app(DoctorSlotGenerator::class);
        [$start, $end] = [CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-15')];
        $rejectedSelections = [
            'empty' => [[], 'Select at least one weekday.'],
            'zero' => [[0], 'Weekdays must be whole numbers from 1 (Monday) to 7 (Sunday).'],
            'eight' => [[1, 8], 'Weekdays must be whole numbers from 1 (Monday) to 7 (Sunday).'],
            'negative' => [[-1], 'Weekdays must be whole numbers from 1 (Monday) to 7 (Sunday).'],
            'numeric string' => [['1'], 'Weekdays must be whole numbers from 1 (Monday) to 7 (Sunday).'],
            'word' => [['monday'], 'Weekdays must be whole numbers from 1 (Monday) to 7 (Sunday).'],
            'float' => [[1.0], 'Weekdays must be whole numbers from 1 (Monday) to 7 (Sunday).'],
            'null entry' => [[null], 'Weekdays must be whole numbers from 1 (Monday) to 7 (Sunday).'],
            'duplicate' => [[1, 3, 1], 'Each weekday can only be selected once.'],
        ];

        foreach ($rejectedSelections as $description => [$weekdays, $message]) {
            foreach (['generateRange', 'preview'] as $method) {
                try {
                    $generator->{$method}($profile, $start, $end, $weekdays);
                    $this->fail("{$method} with {$description} weekdays should be rejected.");
                } catch (ValidationException $exception) {
                    $this->assertSame([$message], $exception->errors()['weekdays'], "{$method} with {$description} weekdays");
                }
            }
        }

        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_service_generates_and_previews_only_the_selected_weekdays(): void
    {
        $profile = $this->doctorWithMondayAvailability()->doctorProfile;
        $generator = app(DoctorSlotGenerator::class);
        [$start, $end] = [CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-15')];

        $this->assertSame(0, $generator->preview($profile, $start, $end, [2, 3])['total']);
        $this->assertSame(0, $generator->generateRange($profile, $start, $end, [2, 3]));
        $this->assertSame(12, $generator->preview($profile, $start, $end, [1])['total']);
        $this->assertSame(12, $generator->generateRange($profile, $start, $end, [7, 1]));
        $this->assertSame(0, $generator->generateRange($profile, $start, $end, [1]), 'Existing slots are not duplicated.');
        $this->assertSame(12, AppointmentSlot::count());
    }

    public function test_valid_weekday_selections_are_accepted_and_applied(): void
    {
        $doctor = $this->doctorWithMondayAvailability();
        DoctorAvailability::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'day_of_week' => 3,
            'start_time' => '09:00',
            'end_time' => '09:30',
            'slot_duration_minutes' => 10,
            'is_active' => true,
        ]);
        $range = ['start_date' => '2026-11-02', 'end_date' => '2026-11-15'];

        $expectedTotals = [
            'Monday only' => [[1], 2 * 6],
            'Wednesday only, sent as a form string' => [['3'], 2 * 3],
            'Monday and Wednesday' => [[1, 3], (2 * 6) + (2 * 3)],
            'all seven days' => [[7, 6, 5, 4, 3, 2, 1], (2 * 6) + (2 * 3)],
            'days without availability' => [[2, 4], 0],
        ];

        foreach ($expectedTotals as [$weekdays, $total]) {
            $this->actingAs($doctor)
                ->postJson(route('doctor.slots.preview'), [...$range, 'weekdays' => $weekdays])
                ->assertOk()
                ->assertJsonPath('total', $total);
        }

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), [...$range, 'weekdays' => ['3']])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, AppointmentSlot::count());
        $this->assertTrue(AppointmentSlot::all()->every(fn (AppointmentSlot $slot): bool => $slot->starts_at->dayOfWeekIso === 3));
    }

    public function test_weekday_filter_limits_generated_slots(): void
    {
        $doctor = $this->doctorWithMondayAvailability();

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), [
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-15',
                'weekdays' => [2, 3],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_preview_identifies_existing_slots_without_writing_records(): void
    {
        $doctor = $this->doctorWithMondayAvailability();
        $profile = $doctor->doctorProfile;
        AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => CarbonImmutable::parse('2026-11-09 09:10:00', config('app.timezone')),
            'ends_at' => CarbonImmutable::parse('2026-11-09 09:20:00', config('app.timezone')),
            'status' => 'booked',
        ]);

        $response = $this->actingAs($doctor)
            ->postJson(route('doctor.slots.preview'), ['start_date' => '2026-11-02', 'end_date' => '2026-11-15', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]])
            ->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonPath('existing', 1)
            ->assertJsonPath('past', 0);

        $existingSlots = collect($response->json('slots'))->where('existing', true)->values();
        $this->assertCount(1, $existingSlots);
        $this->assertSame('2026-11-09', $existingSlots[0]['date']);
        $this->assertSame(1, AppointmentSlot::count());
    }

    public function test_preview_query_count_does_not_grow_with_the_number_of_slots(): void
    {
        $doctor = $this->doctorWithMondayAvailability(startTime: '08:00', endTime: '20:00');
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->actingAs($doctor)
            ->postJson(route('doctor.slots.preview'), ['start_date' => '2026-11-02', 'end_date' => '2027-02-01', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]])
            ->assertOk()
            ->assertJsonPath('total', 14 * 72);

        $this->assertLessThan(10, $queryCount);
    }

    public function test_preview_marks_past_slots_and_generation_skips_them(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 9, 25, 0, config('app.timezone')));
        $doctor = $this->doctorWithMondayAvailability();
        $range = ['start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]];

        $this->actingAs($doctor)
            ->postJson(route('doctor.slots.preview'), $range)
            ->assertOk()
            ->assertJsonPath('total', 6)
            ->assertJsonPath('past', 3);

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), $range);

        $this->assertSame(
            ['09:30', '09:40', '09:50'],
            AppointmentSlot::query()->orderBy('starts_at')->get()->map(fn (AppointmentSlot $slot): string => $slot->starts_at->format('H:i'))->all()
        );
    }

    public function test_repeated_generation_does_not_duplicate_and_preserves_booked_and_blocked_slots(): void
    {
        $doctor = $this->doctorWithMondayAvailability();
        $range = ['start_date' => '2026-11-02', 'end_date' => '2026-11-15', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]];

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), $range);
        $slots = AppointmentSlot::query()->orderBy('starts_at')->get();
        $this->assertCount(12, $slots);
        $slots[0]->update(['status' => 'booked']);
        $slots[1]->update(['status' => 'blocked']);

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), $range)
            ->assertSessionHas('status', '0 new appointment slot(s) generated.');

        $this->assertSame(12, AppointmentSlot::count());
        $this->assertSame('booked', $slots[0]->fresh()->status);
        $this->assertSame('blocked', $slots[1]->fresh()->status);
    }

    public function test_bulk_generation_creates_every_slot_across_insert_chunks(): void
    {
        $doctor = $this->doctorWithMondayAvailability();
        foreach ([2, 3, 4, 5, 6] as $dayOfWeek) {
            DoctorAvailability::create([
                'doctor_profile_id' => $doctor->doctorProfile->id,
                'day_of_week' => $dayOfWeek,
                'start_time' => '08:00',
                'end_time' => '18:00',
                'slot_duration_minutes' => 10,
                'is_active' => true,
            ]);
        }

        $this->actingAs($doctor)
            ->post(route('doctor.slots.generate'), ['start_date' => '2026-11-02', 'end_date' => '2026-11-15', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]])
            ->assertSessionHas('status', '612 new appointment slot(s) generated.');

        $this->assertSame((2 * 6) + (2 * 5 * 60), AppointmentSlot::count());
    }

    public function test_inactive_availability_is_ignored(): void
    {
        $doctor = $this->doctorWithMondayAvailability(isActive: false);
        $range = ['start_date' => '2026-11-02', 'end_date' => '2026-11-15', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]];

        $this->actingAs($doctor)
            ->postJson(route('doctor.slots.preview'), $range)
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), $range);

        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_doctors_only_generate_and_preview_their_own_slots(): void
    {
        $owner = $this->doctorWithMondayAvailability();
        $otherDoctor = $this->doctorWithMondayAvailability(startTime: '14:00', endTime: '14:20');
        $patient = User::factory()->create(['role' => 'patient']);
        $range = ['start_date' => '2026-11-02', 'end_date' => '2026-11-08', 'doctor_profile_id' => $owner->doctorProfile->id, 'weekdays' => [1, 2, 3, 4, 5, 6, 7]];

        $this->actingAs($otherDoctor)
            ->postJson(route('doctor.slots.preview'), $range)
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->actingAs($otherDoctor)->post(route('doctor.slots.generate'), $range);

        $this->assertSame(0, AppointmentSlot::where('doctor_profile_id', $owner->doctorProfile->id)->count());
        $this->assertSame(2, AppointmentSlot::where('doctor_profile_id', $otherDoctor->doctorProfile->id)->count());

        $this->actingAs($patient)->postJson(route('doctor.slots.preview'), $range)->assertForbidden();
        $this->actingAs($patient)->post(route('doctor.slots.generate'), $range)->assertForbidden();
    }

    private function doctorWithMondayAvailability(bool $isActive = true, string $startTime = '09:00', string $endTime = '10:00'): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);
        DoctorAvailability::create([
            'doctor_profile_id' => $profile->id,
            'day_of_week' => 1,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'slot_duration_minutes' => 10,
            'is_active' => $isActive,
        ]);

        return $doctor->load('doctorProfile');
    }
}
