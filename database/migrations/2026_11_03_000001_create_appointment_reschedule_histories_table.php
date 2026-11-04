<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_reschedule_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_slot_id')->constrained('appointment_slots')->restrictOnDelete();
            $table->foreignId('new_slot_id')->constrained('appointment_slots')->restrictOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('previous_starts_at');
            $table->dateTime('new_starts_at');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['appointment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reschedule_histories');
    }
};
