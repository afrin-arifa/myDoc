<?php

namespace App\Services;

use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DoctorSlotGenerator
{
    public function generate(DoctorProfile $profile, int $days): int
    {
        $timezone = config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);

        return $this->generateRange(
            $profile,
            $now->startOfDay(),
            $now->startOfDay()->addDays($days)->subDay(),
            null
        );
    }

    /**
     * @param  list<int>|null  $weekdays  ISO weekdays, where Monday is 1 and Sunday is 7.
     */
    public function generateRange(
        DoctorProfile $profile,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?array $weekdays = null
    ): int {
        $created = 0;
        $this->forEachSlot($profile, $startDate, $endDate, $weekdays, function (CarbonImmutable $slotStart, CarbonImmutable $slotEnd) use ($profile, &$created): void {
            $created += DB::table('appointment_slots')->insertOrIgnore([
                'doctor_profile_id' => $profile->id,
                'starts_at' => $slotStart->utc()->format('Y-m-d H:i:s'),
                'ends_at' => $slotEnd->utc()->format('Y-m-d H:i:s'),
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, true);

        return $created;
    }

    /**
     * @param  list<int>|null  $weekdays  ISO weekdays, where Monday is 1 and Sunday is 7.
     * @return array{slots: list<array{date: string, starts_at: string, ends_at: string, existing: bool, past: bool}>, total: int, existing: int, past: int}
     */
    public function preview(
        DoctorProfile $profile,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?array $weekdays = null
    ): array {
        $slots = [];
        $this->forEachSlot($profile, $startDate, $endDate, $weekdays, function (CarbonImmutable $slotStart, CarbonImmutable $slotEnd) use ($profile, &$slots): void {
            $existing = DB::table('appointment_slots')
                ->where('doctor_profile_id', $profile->id)
                ->where('starts_at', $slotStart->utc()->format('Y-m-d H:i:s'))
                ->exists();
            $slots[] = [
                'date' => $slotStart->toDateString(),
                'starts_at' => $slotStart->toIso8601String(),
                'ends_at' => $slotEnd->toIso8601String(),
                'existing' => $existing,
                'past' => $slotStart->lte(CarbonImmutable::now($slotStart->getTimezone())),
            ];
        }, false);

        return [
            'slots' => $slots,
            'total' => count($slots),
            'existing' => count(array_filter($slots, fn (array $slot): bool => $slot['existing'])),
            'past' => count(array_filter($slots, fn (array $slot): bool => $slot['past'])),
        ];
    }

    /**
     * @param  list<int>|null  $weekdays
     * @param  callable(CarbonImmutable, CarbonImmutable): void  $callback
     */
    private function forEachSlot(
        DoctorProfile $profile,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        ?array $weekdays,
        callable $callback,
        bool $skipPast
    ): void {
        $timezone = config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $selectedWeekdays = $weekdays === null ? range(1, 7) : $weekdays;
        $periodStart = $startDate->setTimezone($timezone)->startOfDay();
        $periodEnd = $endDate->setTimezone($timezone)->startOfDay()->addDay();
        $profile->availabilities()
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->each(function (DoctorAvailability $availability) use (
                $periodStart,
                $periodEnd,
                $timezone,
                $selectedWeekdays,
                $now,
                $callback,
                $skipPast
            ): void {
                for (
                    $date = $periodStart;
                    $date->lt($periodEnd);
                    $date = $date->addDay()
                ) {
                    if (! in_array((int) $date->dayOfWeekIso, $selectedWeekdays, true)
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

                    $duration = 10;

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
