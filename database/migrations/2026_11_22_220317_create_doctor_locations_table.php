<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: a doctor's practice locations (chambers). Purely additive: no existing table or row is changed.
 *
 * The address is nullable only so the backfill can create a "Main chamber" for doctors without a stored address;
 * the application requires it whenever a location is created or edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('address', 255)->nullable();
            $table->string('area', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('directions', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['doctor_profile_id', 'name']);
            $table->index(['doctor_profile_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_locations');
    }
};
