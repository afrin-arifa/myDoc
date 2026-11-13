<?php

namespace App\Services;

use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DoctorSlotGenerator
{
    /**
     * Number of rows sent to the database in each bulk insert.
     */
    private const INSERT_CHUNK_SIZE = 500;

    public function __construct(private readonly DoctorWorkspaceEventService $events) {}

    /**
     * Create missing future slots. Existing slots (including booked and blocked ones) are never modified,
     * because rows that collide with the unique (doctor_profile_id, starts_at) index are ignored.
     *
     * When any slots were created, one "slots_generated" audit event is recorded in the same transaction, with the
     * actor (null for system-initiated generation) and the number of slots created.
     *
     * @param  list<int>  $weekdays  Explicitly selected ISO weekdays, where Monday is 1 and Sunday is 7.
     *
     * @throws ValidationException When the weekday selection is empty, invalid or contains duplicates.
     */
    public function generateRange(
        DoctorProfile $profile,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        array $weekdays,
        ?User $actor = null,
    ): int {
        if ($actor === null && auth()->check()) {
            throw new \LogicException('Slot generation by a signed-in user must pass that user as the actor.');
        }

        $weekdays = $this->validatedWeekdays($weekdays);
        $created = 0;
        $rows = [];
        $timestamp = now();

        DB::transaction(function () use ($profile, $startDate, $endDate, $weekdays, $timestamp, $actor, &$rows, &$created): void {
            $this->forEachSlot($profile, $startDate, $endDate, $weekdays, function (CarbonImmutable $slotStart, CarbonImmutable $slotEnd) use ($profile, $timestamp, &$rows, &$created): void {
                $rows[] = [
                    'doctor_profile_id' => $profile->id,
                    'starts_at' => $this->storageFormat($slotStart),
                    'ends_at' => $this->storageFormat($slotEnd),
                    'status' => 'available',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (count($rows) >= self::INSERT_CHUNK_SIZE) {
                    $created += DB::table('appointment_slots')->insertOrIgnore($rows);
                    $rows = [];
                }
            }, true);

            if ($rows !== []) {
                $created += DB::table('appointment_slots')->insertOrIgnore($rows);
            }

            if ($created > 0) {
                $context = [
                    'slot_count' => $created,
                    'range_start' => $startDate->toDateString(),
                    'range_end' => $endDate->toDateString(),
                    'weekdays' => array_values($weekdays),
                ];
                $actor === null
                    ? $this->events->recordSystem($profile, DoctorWorkspaceEvent::ACTION_SLOTS_GENERATED, context: $context)
                    : $this->events->record($actor, $profile, DoctorWorkspaceEvent::ACTION_SLOTS_GENERATED, context: $context);
            }
        });

        return $created;
    }

    /**
     * Describe the slots a generation would cover without writing anything.
     *
     * @param  list<int>  $weekdays  Explicitly selected ISO weekdays, where Monday is 1 and Sunday is 7.
     * @return array{slots: list<array{date: string, starts_at: string, ends_at: string, existing: bool, past: bool}>, total: int, existing: int, past: int}
     *
     * @throws ValidationException When the weekday selection is empty, invalid or contains duplicates.
     */
    public function preview(
        DoctorProfile $profile,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        array $weekdays
    ): array {
        $weekdays = $this->validatedWeekdays($weekdays);
        $existingStarts = $this->existingSlotStarts($profile, $startDate, $endDate);
        $now = CarbonImmutable::now();
        $slots = [];
        $existingCount = 0;
        $pastCount = 0;

        $this->forEachSlot($profile, $startDate, $endDate, $weekdays, function (CarbonImmutable $slotStart, CarbonImmutable $slotEnd) use ($existingStarts, $now, &$slots, &$existingCount, &$pastCount): void {
            $existing = isset($existingStarts[$this->storageFormat($slotStart)]);
            $past = $slotStart->lte($now);
            $existingCount += (int) $existing;
            $pastCount += (int) $past;
            $slots[] = [
                'date' => $slotStart->toDateString(),
                'starts_at' => $slotStart->toIso8601String(),
                'ends_at' => $slotEnd->toIso8601String(),
                'existing' => $existing,
                'past' => $past,
            ];
        }, false);

        return [
            'slots' => $slots,
            'total' => count($slots),
            'existing' => $existingCount,
            'past' => $pastCount,
        ];
    }

    /**
     * Fetch the start times of the doctor's existing slots in the range with a single query.
     *
     * @return array<string, true> Keyed by the stored UTC start time.
     */
    private function existingSlotStarts(DoctorProfile $profile, CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $timezone = config('app.timezone', 'UTC');
        $rangeStart = $startDate->setTimezone($timezone)->startOfDay();
        $rangeEnd = $endDate->setTimezone($timezone)->startOfDay()->addDay();

        return DB::table('appointment_slots')
            ->where('doctor_profile_id', $profile->id)
            ->where('starts_at', '>=', $this->storageFormat($rangeStart))
            ->where('starts_at', '<', $this->storageFormat($rangeEnd))
            ->pluck('starts_at')
            ->mapWithKeys(fn (string $startsAt): array => [CarbonImmutable::parse($startsAt, 'UTC')->format('Y-m-d H:i:s') => true])
            ->all();
    }

    private function storageFormat(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d H:i:s');
    }

    /**
     * Guard the service contract independently of HTTP validation: the selection must be a non-empty list of
     * distinct integers from 1 to 7. Nothing is ever defaulted to every weekday.
     *
     * @param  array<mixed>  $weekdays
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function validatedWeekdays(array $weekdays): array
    {
        if ($weekdays === []) {
            throw ValidationException::withMessages(['weekdays' => 'Select at least one weekday.']);
        }

        foreach ($weekdays as $weekday) {
            if (! is_int($weekday) || $weekday < 1 || $weekday > 7) {
                throw ValidationException::withMessages(['weekdays' => 'Weekdays must be whole numbers from 1 (Monday) to 7 (Sunday).']);
            }
        }

        if (count(array_unique($weekdays)) !== count($weekdays)) {
            throw ValidationException::withMessages(['weekdays' => 'Each weekday can only be selected once.']);
        }

        return array_values($weekdays);
    }

    /**
     * @param  list<int>  $weekdays
     * @param  callable(CarbonImmutable, CarbonImmutable): void  $callback
     */
    private function forEachSlot(
        DoctorProfile $profile,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        array $weekdays,
        callable $callback,
        bool $skipPast
    ): void {
        $timezone = config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $periodStart = $startDate->setTimezone($timezone)->startOfDay();
        $periodEnd = $endDate->setTimezone($timezone)->startOfDay()->addDay();
        $duration = DoctorAvailability::SLOT_DURATION_MINUTES;
        $profile->availabilities()
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->each(function (DoctorAvailability $availability) use (
                $periodStart,
                $periodEnd,
                $timezone,
                $weekdays,
                $now,
                $duration,
                $callback,
                $skipPast
            ): void {
                for (
                    $date = $periodStart;
                    $date->lt($periodEnd);
                    $date = $date->addDay()
                ) {
                    if (! in_array((int) $date->dayOfWeekIso, $weekdays, true)
                        || (int) $date->dayOfWeekIso !== ($availability->day_of_week === 0 ? 7 : $availability->day_of_week)
                    ) {
                        continue;
                    }

                    $start = CarbonImmutable::parse(
                        $date->toDateString().' '.$availability->start_time,
                        $timezone
                    );

                    $end = CarbonImmutable::parse(
                        $date->toDateString().' '.$availability->end_time,
                        $timezone
                    );

                    for (
                        $slotStart = $start;
                        $slotStart->addMinutes($duration)->lte($end);
                        $slotStart = $slotStart->addMinutes($duration)
                    ) {
                        $slotEnd = $slotStart->addMinutes($duration);

                        if ($skipPast && $slotStart->lte($now)) {
                            continue;
                        }
                        $callback($slotStart, $slotEnd);
                    }
                }
            });
    }
}
