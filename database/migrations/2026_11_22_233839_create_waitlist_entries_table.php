<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waiting list: a patient asks to be offered a time with a doctor within a date range, optionally limited to a time of
 * day, a location and a consultation type. A follow-up request keeps its link to the original appointment. Purely
 * additive: no existing table or row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_location_id')->nullable()->constrained('doctor_locations')->nullOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('time_of_day', 16)->nullable();
            $table->string('consultation_type', 16);
            $table->foreignId('follow_up_of_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->string('status', 16)->default('waiting');
            $table->unsignedTinyInteger('expired_offer_count')->default(0);
            $table->foreignId('booked_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->timestamps();

            $table->index(['doctor_profile_id', 'status', 'created_at']);
            $table->index(['patient_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
