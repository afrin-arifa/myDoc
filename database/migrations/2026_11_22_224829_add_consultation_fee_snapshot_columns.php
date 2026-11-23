<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: consultation fee snapshots. Additive and reversible; every existing row gets NULL.
 *
 *  - doctor_profiles.follow_up_fee: optional fee for follow-up appointments (NULL = the regular consultation fee).
 *  - appointments.consultation_fee / fee_type: the fee that applied when the appointment was created ("standard" or
 *    "follow_up"). It is never recalculated, so later fee changes do not rewrite appointment history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->decimal('follow_up_fee', 10, 2)->nullable()->after('consultation_fee');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->decimal('consultation_fee', 10, 2)->nullable()->after('consultation_type');
            $table->string('fee_type', 16)->nullable()->after('consultation_fee');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['consultation_fee', 'fee_type']);
        });

        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropColumn('follow_up_fee');
        });
    }
};
