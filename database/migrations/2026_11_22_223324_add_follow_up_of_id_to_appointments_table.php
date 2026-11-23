<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: a follow-up appointment points to the completed appointment it follows up (same patient, same doctor; the
 * application enforces both). The column is nullable, so every existing appointment stays as it is (NULL). The link
 * is never changed by rescheduling, cancellation or expiry; appointments are never deleted, and restrict keeps an
 * original from being removed while a follow-up references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('follow_up_of_id')
                ->nullable()
                ->after('patient_id')
                ->constrained('appointments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('follow_up_of_id');
        });
    }
};
