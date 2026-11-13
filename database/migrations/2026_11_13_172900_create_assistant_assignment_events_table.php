<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of assistant assignments ("assigned" / "removed").
 *
 * There is deliberately no reference to doctor_assistant: a removed assignment's row is deleted, and its history
 * must survive that. Deleting an assistant or doctor profile that has history is restricted instead of cascading,
 * so records are never silently destroyed (the application deactivates accounts rather than deleting them). The
 * actor becomes null if that account is ever deleted, keeping the event itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_assignment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assistant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('doctor_profile_id')->constrained()->restrictOnDelete();
            $table->string('action', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['assistant_id', 'created_at']);
            $table->index(['doctor_profile_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    /**
     * Refuses to drop the table while it holds data, so a rollback can never silently destroy assistant assignment history rows.
     */
    public function down(): void
    {
        $rows = Schema::hasTable('assistant_assignment_events') ? DB::table('assistant_assignment_events')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException("Cannot drop assistant_assignment_events: {$rows} assistant assignment history row(s) would be lost.");
        }

        Schema::dropIfExists('assistant_assignment_events');
    }
};
