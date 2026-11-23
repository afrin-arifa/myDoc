<?php

namespace App\Services;

use App\Jobs\OfferSlotToWaitlist;
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

    /**
     * A conflicting existing slot starts at exactly the same time.
     */
    private const CONFLICT_SAME_START = 'same_start';

    /**
     * A conflicting existing slot overlaps the time but starts at a different moment.
     */
    private const CONFLICT_OVERLAP = 'overlap';

    public function __construct(private readonly DoctorWorkspaceEventService $events) {}

    /**
     * Create missing future slots. Existing slots (including booked and blocked ones) are never modified, and a new
     * slot is skipped when it would overlap any existing slot of the doctor, whatever that slot's start time or status
     * (for example after a weekly window moved from 09:00 to 09:05). New slots never overlap each other either.
     *
     * The doctor's schedule lock (DoctorProfile::lockForScheduling) is taken first, so concurrent generations and
     * availability changes for the same doctor run one after another and each sees the slots the previous one
     * created. The unique (doctor_profile_id, starts_at) index remains a last line of defence (insertOrIgnore).
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
            DoctorProfile::lockForScheduling($profile->id);
            $occupied = $this->occupiedIntervals($profile, $startDate, $endDate);

            $this->forEachSlot($profile, $startDate, $endDate, $weekdays, function (CarbonImmutable $slotStart, CarbonImmutable $slotEnd, DoctorAvailability $window) use ($profile, $timestamp, &$rows, &$created, &$occupied): void {
                if ($this->conflict($occupied, $slotStart, $slotEnd) !== null) {
                    return;
                }

                $this->occupy($occupied, $slotStart, $slotEnd);
                // The slot keeps its window's location for good (never taken from the request).
                $rows[] = [
                    'doctor_profile_id' => $profile->id,
                    'doctor_location_id' => $window->doctor_location_id,
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

        if ($created > 0) {
            // New times may be what waiting-list patients are waiting for.
            OfferSlotToWaitlist::dispatch(doctorProfileId: (int) $profile->id);
        }

        return $created;
    }

    /**
     * Describe the slots a generation would cover without writing anything. "existing" marks a time that already has
     * a slot with the same start; "overlapping" marks a time that would overlap a different existing slot (or an
     * earlier time in this same preview) and will therefore be skipped. Past times are skipped as well.
     *
     * @param  list<int>  $weekdays  Explicitly selected ISO weekdays, where Monday is 1 and Sunday is 7.
     * @return array{slots: list<array{date: string, starts_at: string, ends_at: string, existing: bool, overlapping: bool, past: bool, location: string|null}>, total: int, existing: int, overlapping: int, past: int}
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
        $occupied = $this->occupiedIntervals($profile, $startDate, $endDate);
        $now = CarbonImmutable::now();
        $slots = [];
        $existingCount = 0;
        $overlappingCount = 0;
        $pastCount = 0;

        $this->forEachSlot($profile, $startDate, $endDate, $weekdays, function (CarbonImmutable $slotStart, CarbonImmutable $slotEnd, DoctorAvailability $window) use (&$occupied, $now, &$slots, &$existingCount, &$overlappingCount, &$pastCount): void {
            $conflict = $this->conflict($occupied, $slotStart, $slotEnd);
            $past = $slotStart->lte($now);
            $existing = $conflict === self::CONFLICT_SAME_START;
            $overlapping = $conflict === self::CONFLICT_OVERLAP;
            $existingCount += (int) $existing;
            $overlappingCount += (int) $overlapping;
            $pastCount += (int) $past;

            // Mirror generation: a time that would be created occupies its interval for later times in this run.
            if ($conflict === null && ! $past) {
                $this->occupy($occupied, $slotStart, $slotEnd);
            }

            $slots[] = [
                'date' => $slotStart->toDateString(),
                'starts_at' => $slotStart->toIso8601String(),
                'ends_at' => $slotEnd->toIso8601String(),
                'existing' => $existing,
                'overlapping' => $overlapping,
                'past' => $past,
                'location' => $window->location?->name,
            ];
        }, false);

        return [
            'slots' => $slots,
            'total' => count($slots),
            'existing' => $existingCount,
            'overlapping' => $overlappingCount,
            'past' => $pastCount,
        ];
    }

    /**
     * The doctor's existing slots that touch the range (any status), fetched with a single query and grouped by
     * calendar date for quick overlap checks. Intervals are [start, end) Unix timestamps.
     *
     * @return array<string, list<array{0: int, 1: int}>>
     */
    private function occupiedIntervals(DoctorProfile $profile, CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $timezone = config('app.timezone', 'UTC');
        $rangeStart = $startDate->setTimezone($timezone)->startOfDay();
        $rangeEnd = $endDate->setTimezone($timezone)->startOfDay()->addDay();
        $occupied = [];

        DB::table('appointment_slots')
            ->where('doctor_profile_id', $profile->id)
            ->where('starts_at', '<', $this->storageFormat($rangeEnd))
            ->where('ends_at', '>', $this->storageFormat($rangeStart))
            ->get(['starts_at', 'ends_at'])
            ->each(function (object $slot) use ($timezone, &$occupied): void {
                $this->occupy($occupied, CarbonImmutable::parse($slot->starts_at, $timezone), CarbonImmutable::parse($slot->ends_at, $timezone));
            });

        return $occupied;
    }

    /**
     * @param  array<string, list<array{0: int, 1: int}>>  $occupied
     */
    private function occupy(array &$occupied, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $interval = [$start->getTimestamp(), $end->getTimestamp()];

        // Registered under every date the interval touches, so a lookup by the candidate's start date is complete.
        foreach (array_unique([$start->toDateString(), $end->subSecond()->toDateString()]) as $date) {
            $occupied[$date][] = $interval;
        }
    }

    /**
     * Whether [start, end) collides with an occupied interval: the same start, another overlap, or none (null).
     * Back-to-back slots (one ends exactly when the next starts) do not overlap.
     *
     * @param  array<string, list<array{0: int, 1: int}>>  $occupied
     */
    private function conflict(array $occupied, CarbonImmutable $start, CarbonImmutable $end): ?string
    {
        $startTimestamp = $start->getTimestamp();
        $endTimestamp = $end->getTimestamp();
        $conflict = null;

        foreach ($occupied[$start->toDateString()] ?? [] as [$occupiedStart, $occupiedEnd]) {
            if ($occupiedStart === $startTimestamp) {
                return self::CONFLICT_SAME_START;
            }

            if ($occupiedStart < $endTimestamp && $occupiedEnd > $startTimestamp) {
                $conflict = self::CONFLICT_OVERLAP;
            }
        }

        return $conflict;
    }

    /**
     * Slots are stored as wall-clock times in the application timezone (Asia/Dhaka), never converted to UTC, so a
     * doctor's "09:00" is stored and shown as 09:00.
     */
    private function storageFormat(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
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
            ->with('location')
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
                        $callback($slotStart, $slotEnd, $availability);
                    }
                }
            });
    }
}
