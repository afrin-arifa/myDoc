<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the mandatory reason a doctor or Doctor Assistant gives when cancelling an appointment. There was no field
 * for it (patient_notes and provider_notes have other meanings), and the reason must not go into the append-only
 * audit context. It is shown to staff only, never on patient pages. Nullable: patient cancellations and existing
 * rows have no reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('cancellation_reason');
        });
    }
};
