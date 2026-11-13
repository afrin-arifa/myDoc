<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of doctor workspace operations: appointments (booked, confirmed, rejected, rescheduled,
 * cancelled, started, completed), slots (blocked, unblocked, generated) and weekly availability (created, updated,
 * deleted).
 *
 * Each row records the actor (the real signed-in user, or null with actor_role "system") separately from the doctor
 * context (doctor_profile_id). Deleting a doctor profile with history is blocked; deleting an appointment or user
 * keeps the event (the reference becomes null). subject_type/subject_id identify the affected record without a
 * foreign key, so events about deleted slots or availability rules survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_workspace_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 16);
            $table->string('action', 32);
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['doctor_profile_id', 'created_at']);
            $table->index(['appointment_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Refuses to drop the table while it holds audit history, so a rollback never silently destroys it.
     */
    public function down(): void
    {
        $rows = Schema::hasTable('doctor_workspace_events') ? DB::table('doctor_workspace_events')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException("Cannot drop doctor_workspace_events: {$rows} audit event(s) would be lost.");
        }

        Schema::dropIfExists('doctor_workspace_events');
    }
};
