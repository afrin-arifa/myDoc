<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The approved Phase 6 backfill: one "Main chamber" per existing doctor, linking only the location column of their
 * existing windows and slots, without touching any other column.
 */
class DoctorLocationBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_creates_one_main_chamber_per_doctor_and_links_only_the_location_column(): void
    {
        $withAddress = $this->profile('House 16, Road 2, Dhanmondi');
        $withoutAddress = $this->profile('   ');
        $alreadyHasLocation = $this->profile('Somewhere');
        $existingLocationId = DB::table('doctor_locations')->insertGetId(['doctor_profile_id' => $alreadyHasLocation->id, 'name' => 'Own chamber', 'address' => 'Road 9', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $stamp = '2026-10-01 10:00:00';
        $windowId = DB::table('doctor_availabilities')->insertGetId(['doctor_profile_id' => $withAddress->id, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'slot_duration_minutes' => 10, 'is_active' => true, 'created_at' => $stamp, 'updated_at' => $stamp]);
        $slotIds = collect(['available', 'booked', 'blocked'])->map(fn (string $status, int $index): int => DB::table('appointment_slots')->insertGetId([
            'doctor_profile_id' => $withAddress->id, 'starts_at' => '2026-11-09 09:'.($index * 10 ?: '00').':00', 'ends_at' => '2026-11-09 09:'.(($index + 1) * 10).':00', 'status' => $status, 'created_at' => $stamp, 'updated_at' => $stamp,
        ]));
        $otherSlotId = DB::table('appointment_slots')->insertGetId(['doctor_profile_id' => $withoutAddress->id, 'starts_at' => '2026-11-10 09:00:00', 'ends_at' => '2026-11-10 09:10:00', 'status' => 'available', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $slotsBefore = DB::table('appointment_slots')->orderBy('id')->get()->map(fn ($slot) => collect((array) $slot)->except('doctor_location_id')->all());

        $this->backfill()->up();

        $locations = DB::table('doctor_locations')->orderBy('id')->get();
        $this->assertCount(3, $locations, 'Two new Main chambers; the doctor who already had a location gets none.');
        $main = $locations->where('name', 'Main chamber')->keyBy('doctor_profile_id');
        $this->assertSame('House 16, Road 2, Dhanmondi', $main[$withAddress->id]->address);
        $this->assertNull($main[$withoutAddress->id]->address, 'A blank account address leaves the location address empty.');
        $this->assertSame($existingLocationId, $locations->firstWhere('doctor_profile_id', $alreadyHasLocation->id)->id);

        $this->assertSame($main[$withAddress->id]->id, (int) DB::table('doctor_availabilities')->where('id', $windowId)->value('doctor_location_id'));
        $this->assertSame([$main[$withAddress->id]->id], DB::table('appointment_slots')->whereIn('id', $slotIds)->pluck('doctor_location_id')->unique()->map(fn ($id) => (int) $id)->values()->all());
        $this->assertSame($main[$withoutAddress->id]->id, (int) DB::table('appointment_slots')->where('id', $otherSlotId)->value('doctor_location_id'));
        $this->assertEquals($slotsBefore, DB::table('appointment_slots')->orderBy('id')->get()->map(fn ($slot) => collect((array) $slot)->except('doctor_location_id')->all()), 'Times, statuses and timestamps are unchanged.');
        $this->assertSame($stamp, (string) DB::table('doctor_availabilities')->where('id', $windowId)->value('updated_at'));

        $this->backfill()->up();
        $this->assertSame(3, DB::table('doctor_locations')->count(), 'Running it again changes nothing.');

        $this->backfill()->down();
        $this->assertSame(['Own chamber'], DB::table('doctor_locations')->pluck('name')->all());
        $this->assertSame(0, DB::table('appointment_slots')->whereNotNull('doctor_location_id')->count());
        $this->assertSame(4, DB::table('appointment_slots')->count(), 'Nothing is deleted except the created locations.');
    }

    private function backfill(): Migration
    {
        return require database_path('migrations/2026_11_22_220319_backfill_main_chamber_doctor_locations.php');
    }

    private function profile(?string $address): DoctorProfile
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'address' => $address]);

        return DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'Physician', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 50]);
    }
}
