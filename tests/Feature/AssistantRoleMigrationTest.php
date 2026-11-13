<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs the Phase 1 migrations backwards and forwards on the SQLite test database with real rows in place. SQLite
 * rebuilds the users table to change the role CHECK constraint, so these tests prove that rows, indexes and every
 * foreign key that points at users survive that rebuild.
 */
class AssistantRoleMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const PHASE_ONE_TABLES = ['doctor_assistant', 'assistant_assignment_events'];

    /**
     * The Phase 1 migrations, rolled back by path so later migrations do not change what is rolled back.
     */
    private const PHASE_ONE_MIGRATIONS = [
        'database/migrations/2026_11_13_172857_add_assistant_to_users_role.php',
        'database/migrations/2026_11_13_172859_create_doctor_assistant_table.php',
        'database/migrations/2026_11_13_172900_create_assistant_assignment_events_table.php',
    ];

    public function test_rollback_and_reapply_preserve_existing_users_and_everything_that_references_them(): void
    {
        $this->rollbackPhaseOne();
        $this->assertFalse(Schema::hasTable('doctor_assistant'));
        $this->assertFalse(Schema::hasTable('assistant_assignment_events'));
        $this->assertRoleRejected('assistant', 'Before Phase 1 the assistant role does not exist.');

        $ids = $this->seedExistingData();
        $before = $this->snapshot();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame($before, $this->snapshot(), 'Every existing user, profile, slot, appointment and history row is unchanged.');
        $this->assertSame(['patient', 'doctor', 'admin'], DB::table('users')->orderBy('id')->pluck('role')->all());
        foreach (self::PHASE_ONE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'), 'No foreign key points at a missing row.');
        $this->assertContains('users_role_index', $this->indexes('users'));
        $this->assertContains('users_email_unique', $this->indexes('users'));
        $this->assertContains('users_deactivated_at_index', $this->indexes('users'));

        $assistantId = DB::table('users')->insertGetId(['name' => 'Assistant', 'email' => 'assistant@example.com', 'password' => 'x', 'role' => 'assistant']);
        $this->assertSame('assistant', User::find($assistantId)->role);
        $this->assertSame('patient', DB::table('users')->where('id', DB::table('users')->insertGetId(['name' => 'D', 'email' => 'd@example.com', 'password' => 'x']))->value('role'));
        $this->assertRoleRejected('guest', 'Unknown roles are still rejected.');
        // DatabaseMigrations rolls everything back after the test, which (correctly) refuses while assistants exist.
        DB::table('users')->where('id', $assistantId)->delete();

        $this->expectException(QueryException::class);
        DB::table('appointments')->insert(['appointment_slot_id' => $ids['slot'], 'patient_id' => 999999, 'status' => 'booked', 'consultation_type' => 'physical']);
    }

    public function test_foreign_keys_into_users_still_restrict_and_cascade_after_the_rebuild(): void
    {
        $ids = $this->seedExistingData();

        try {
            DB::table('users')->where('id', $ids['doctor'])->delete();
            $this->fail('A doctor with reschedule history cannot be deleted.');
        } catch (QueryException) {
        }
        $this->assertSame(1, DB::table('doctor_profiles')->count());

        DB::table('appointment_reschedule_histories')->delete();
        DB::table('appointments')->delete();
        DB::table('users')->where('id', $ids['doctor'])->delete();
        $this->assertSame(0, DB::table('doctor_profiles')->count(), 'Deleting a doctor user still cascades to the profile.');
    }

    public function test_rollback_refuses_while_assistants_or_assignment_data_exist(): void
    {
        $ids = $this->seedExistingData();
        $assistantId = DB::table('users')->insertGetId(['name' => 'Assistant', 'email' => 'assistant@example.com', 'password' => 'x', 'role' => 'assistant']);
        DB::table('doctor_assistant')->insert(['doctor_profile_id' => $ids['profile'], 'assistant_id' => $assistantId]);
        DB::table('assistant_assignment_events')->insert(['doctor_profile_id' => $ids['profile'], 'assistant_id' => $assistantId, 'action' => 'assigned', 'actor_id' => $ids['admin']]);

        foreach (['assistant_assignment_events', 'doctor_assistant'] as $table) {
            $this->assertRollbackRefused("Cannot drop {$table}");
            $this->assertSame(1, DB::table($table)->count(), "{$table} keeps its data.");
            DB::table($table)->delete();
        }

        $this->assertRollbackRefused('Cannot remove the assistant role: 1 user(s) still have it.');
        $this->assertSame('assistant', DB::table('users')->where('id', $assistantId)->value('role'));

        DB::table('users')->where('id', $assistantId)->delete();
        $this->rollbackPhaseOne();
        $this->assertSame(['patient', 'doctor', 'admin'], DB::table('users')->orderBy('id')->pluck('role')->all());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    private function rollbackPhaseOne(): void
    {
        $this->assertSame(0, Artisan::call('migrate:rollback', $this->phaseOneRollback()));
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseOneRollback(): array
    {
        return ['--path' => array_map('base_path', self::PHASE_ONE_MIGRATIONS), '--realpath' => true, '--force' => true];
    }

    private function assertRollbackRefused(string $message): void
    {
        try {
            Artisan::call('migrate:rollback', $this->phaseOneRollback());
            $this->fail('Rollback must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function assertRoleRejected(string $role, string $message): void
    {
        try {
            DB::table('users')->insert(['name' => 'X', 'email' => uniqid().'@example.com', 'password' => 'x', 'role' => $role]);
            $this->fail($message);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return array<string, int>
     */
    private function seedExistingData(): array
    {
        $now = now();
        $patient = DB::table('users')->insertGetId(['name' => 'Patient', 'email' => 'patient@example.com', 'password' => 'x', 'role' => 'patient', 'phone' => '8801712345678', 'sms_notifications_enabled' => true, 'created_at' => $now]);
        $doctor = DB::table('users')->insertGetId(['name' => 'Doctor', 'email' => 'doctor@example.com', 'password' => 'x', 'role' => 'doctor', 'created_at' => $now]);
        $admin = DB::table('users')->insertGetId(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role' => 'admin', 'deactivated_at' => $now, 'created_at' => $now]);
        $profile = DB::table('doctor_profiles')->insertGetId(['user_id' => $doctor, 'professional_title' => 'GP', 'license_number' => 'LIC-1', 'consultation_fee' => 10]);
        $slot = DB::table('appointment_slots')->insertGetId(['doctor_profile_id' => $profile, 'starts_at' => $now->copy()->addDay(), 'ends_at' => $now->copy()->addDay()->addMinutes(10), 'status' => 'booked']);
        $newSlot = DB::table('appointment_slots')->insertGetId(['doctor_profile_id' => $profile, 'starts_at' => $now->copy()->addDays(2), 'ends_at' => $now->copy()->addDays(2)->addMinutes(10), 'status' => 'booked']);
        $appointment = DB::table('appointments')->insertGetId(['appointment_slot_id' => $newSlot, 'patient_id' => $patient, 'status' => 'confirmed', 'consultation_type' => 'physical']);
        DB::table('appointment_reschedule_histories')->insert(['appointment_id' => $appointment, 'previous_slot_id' => $slot, 'new_slot_id' => $newSlot, 'performed_by' => $doctor, 'previous_starts_at' => $now, 'new_starts_at' => $now]);

        return compact('patient', 'doctor', 'admin', 'profile', 'slot', 'appointment');
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'doctor_profiles', 'appointment_slots', 'appointments', 'appointment_reschedule_histories'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    /**
     * @return list<string>
     */
    private function indexes(string $table): array
    {
        return array_map(fn ($index) => $index->name, DB::select("PRAGMA index_list('{$table}')"));
    }
}
