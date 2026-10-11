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
        $periodStart = CarbonImmutable::now($timezone)->startOfDay();
        $periodEnd = $periodStart->addDays($days);
        $created = 0;

        $profile->availabilities()
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->each(function (DoctorAvailability $availability) use (
                $profile,
                $periodStart,
                $periodEnd,
                $timezone,
                &$created
            ): void {
                for ($date = $periodStart; $date->lt($periodEnd); $date = $date->addDay()) {
                    if ((int) $date->dayOfWeek !== $availability->day_of_week) {
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
                    $duration = $availability->slot_duration_minutes;

                    for ($slotStart = $start; $slotStart->addMinutes($duration)->lte($end); $slotStart = $slotStart->addMinutes($duration)) {
                        $slotEnd = $slotStart->addMinutes($duration);

                        $created += DB::table('appointment_slots')->insertOrIgnore([
                            'doctor_profile_id' => $profile->id,
                            'starts_at' => $slotStart->utc()->format('Y-m-d H:i:s'),
                            'ends_at' => $slotEnd->utc()->format('Y-m-d H:i:s'),
                            'status' => 'available',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

        return $created;
    }
}
