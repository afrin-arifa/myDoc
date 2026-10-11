<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_slot_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', [
                'booked',
                'confirmed',
                'completed',
                'cancelled',
                'rejected',
            ])->default('booked');
            $table->text('patient_notes')->nullable();
            $table->text('provider_notes')->nullable();
            $table->timestamp('booked_at')->useCurrent();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unsignedBigInteger('active_slot_id')
                ->nullable()
                ->virtualAs(
                    "CASE WHEN status IN ('cancelled', 'rejected') THEN NULL ELSE appointment_slot_id END"
                );
            $table->unique('active_slot_id');
            $table->index(['patient_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
