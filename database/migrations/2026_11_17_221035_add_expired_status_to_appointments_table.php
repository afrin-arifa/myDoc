<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the "expired" appointment status: a pending request whose slot started before the doctor approved it.
 *
 * The generated active_slot_id column (unique) is what guarantees at most one active appointment per slot. It must
 * treat "expired" like "cancelled" and "rejected" (no longer holding the slot), so its expression is updated in the
 * same step. No rows change: existing statuses keep their meaning and no existing appointment is expired.
 *
 * MySQL/MariaDB: one ALTER TABLE statement (atomic DDL), so the unique protection is never absent. The unique index on
 * active_slot_id is kept and rebuilt by MySQL.
 * SQLite (tests): the column and index are recreated with the schema builder.
 */
return new class extends Migration
{
    private const STATUSES_BEFORE = ['booked', 'confirmed', 'completed', 'cancelled', 'rejected'];

    private const STATUSES_AFTER = ['booked', 'confirmed', 'completed', 'cancelled', 'rejected', 'expired'];

    public function up(): void
    {
        $this->rebuild(self::STATUSES_AFTER, ['cancelled', 'rejected', 'expired']);
    }

    /**
     * Only possible while no appointment is expired; expired appointments are never rewritten into another status.
     */
    public function down(): void
    {
        if (DB::table('appointments')->where('status', 'expired')->exists()) {
            throw new RuntimeException('Cannot remove the "expired" status while expired appointments exist; they are kept as history.');
        }

        $this->rebuild(self::STATUSES_BEFORE, ['cancelled', 'rejected']);
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $releasingStatuses  Statuses that no longer hold their slot.
     */
    private function rebuild(array $statuses, array $releasingStatuses): void
    {
        $quote = fn (array $values): string => implode(', ', array_map(fn (string $value): string => "'{$value}'", $values));
        $expression = 'CASE WHEN status IN ('.$quote($releasingStatuses).') THEN NULL ELSE appointment_slot_id END';

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE appointments '
                .'MODIFY status ENUM('.$quote($statuses).") NOT NULL DEFAULT 'booked', "
                ."MODIFY active_slot_id BIGINT UNSIGNED GENERATED ALWAYS AS ({$expression}) VIRTUAL NULL"
            );

            return;
        }

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique(['active_slot_id']);
        });
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('active_slot_id');
        });
        Schema::table('appointments', function (Blueprint $table) use ($statuses): void {
            $table->enum('status', $statuses)->default('booked')->change();
        });
        Schema::table('appointments', function (Blueprint $table) use ($expression): void {
            $table->unsignedBigInteger('active_slot_id')->nullable()->virtualAs($expression);
            $table->unique('active_slot_id');
        });
    }
};
