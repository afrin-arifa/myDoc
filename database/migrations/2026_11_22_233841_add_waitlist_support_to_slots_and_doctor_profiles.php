<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Waiting list support on existing tables. No existing value changes:
 *  - appointment_slots.status gains "held": a slot offered to one waiting patient, which nobody else can book or
 *    block until the offer is accepted, declined, withdrawn or expires;
 *  - doctor_profiles.waitlist_enabled (default true): the doctor's waiting list switch.
 *
 * MySQL/MariaDB: one ALTER TABLE for the enum (atomic DDL). SQLite (tests): the schema builder rebuilds the column.
 */
return new class extends Migration
{
    private const STATUSES_BEFORE = ['available', 'booked', 'blocked'];

    private const STATUSES_AFTER = ['available', 'booked', 'blocked', 'held'];

    public function up(): void
    {
        $this->slotStatuses(self::STATUSES_AFTER);

        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->boolean('waitlist_enabled')->default(true)->after('is_accepting_patients');
        });
    }

    /**
     * Only possible while no slot is held.
     */
    public function down(): void
    {
        if (DB::table('appointment_slots')->where('status', 'held')->exists()) {
            throw new RuntimeException('Cannot remove the "held" slot status while slots are held for waiting list offers.');
        }

        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropColumn('waitlist_enabled');
        });

        $this->slotStatuses(self::STATUSES_BEFORE);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function slotStatuses(array $statuses): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $list = implode(', ', array_map(fn (string $status): string => "'{$status}'", $statuses));
            DB::statement("ALTER TABLE appointment_slots MODIFY status ENUM({$list}) NOT NULL DEFAULT 'available'");

            return;
        }

        Schema::table('appointment_slots', function (Blueprint $table) use ($statuses): void {
            $table->enum('status', $statuses)->default('available')->change();
        });
    }
};
