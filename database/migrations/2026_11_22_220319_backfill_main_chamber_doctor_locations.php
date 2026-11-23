<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 data backfill (approved): every existing doctor profile gets one location named "Main chamber", with the
 * address copied from the doctor's account address when one is stored, and that doctor's existing weekly windows and
 * slots that have no location yet are linked to it.
 *
 * Only doctor_location_id is written on existing rows; updated_at and every other column (including slot times and
 * statuses) are left untouched, and nothing is deleted. Doctors who already have a location are skipped, so running it
 * again changes nothing. down() unlinks and deletes only the "Main chamber" rows this migration created.
 */
return new class extends Migration
{
    private const NAME = 'Main chamber';

    public function up(): void
    {
        DB::transaction(function (): void {
            $profiles = DB::table('doctor_profiles')
                ->join('users', 'users.id', '=', 'doctor_profiles.user_id')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('doctor_locations')->whereColumn('doctor_locations.doctor_profile_id', 'doctor_profiles.id'))
                ->orderBy('doctor_profiles.id')
                ->get(['doctor_profiles.id', 'users.address']);

            foreach ($profiles as $profile) {
                $address = trim((string) $profile->address);
                $locationId = DB::table('doctor_locations')->insertGetId([
                    'doctor_profile_id' => $profile->id,
                    'name' => self::NAME,
                    'address' => $address === '' ? null : mb_substr($address, 0, 255),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach (['doctor_availabilities', 'appointment_slots'] as $table) {
                    DB::table($table)
                        ->where('doctor_profile_id', $profile->id)
                        ->whereNull('doctor_location_id')
                        ->update(['doctor_location_id' => $locationId]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $locationIds = DB::table('doctor_locations')->where('name', self::NAME)->pluck('id');

            foreach (['doctor_availabilities', 'appointment_slots'] as $table) {
                DB::table($table)->whereIn('doctor_location_id', $locationIds)->update(['doctor_location_id' => null]);
            }

            DB::table('doctor_locations')->whereIn('id', $locationIds)->delete();
        });
    }
};
