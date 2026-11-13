<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which assistants work for which doctors (many to many). A row exists only while the assignment is active:
 * removing an assignment deletes its row, so access can be checked against the current rows on every request.
 * The history of assignments lives in assistant_assignment_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_assistant', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['doctor_profile_id', 'assistant_id']);
            $table->index(['assistant_id', 'doctor_profile_id']);
        });
    }

    /**
     * Refuses to drop the table while it holds data, so a rollback can never silently destroy assistant assignments.
     */
    public function down(): void
    {
        $rows = Schema::hasTable('doctor_assistant') ? DB::table('doctor_assistant')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException("Cannot drop doctor_assistant: {$rows} assistant assignment(s) would be lost.");
        }

        Schema::dropIfExists('doctor_assistant');
    }
};
