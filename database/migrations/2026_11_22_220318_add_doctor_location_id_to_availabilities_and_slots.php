<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: weekly windows and slots name the location they belong to. The columns are nullable, so every existing
 * row stays valid (shown as "Location not specified" until it is linked); new windows require a location in the
 * application. A slot copies its window's location when it is generated and keeps it, so a booked appointment always
 * shows where it was booked. Locations still used by a window or slot cannot be deleted (restrict), only archived.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = ['doctor_availabilities', 'appointment_slots'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('doctor_location_id')
                    ->nullable()
                    ->after('doctor_profile_id')
                    ->constrained('doctor_locations')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('doctor_location_id');
            });
        }
    }
};
