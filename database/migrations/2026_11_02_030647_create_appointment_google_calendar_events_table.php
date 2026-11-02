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
        Schema::create('appointment_google_calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('google_calendar_connection_id')->nullable();
            $table->foreign(
                'google_calendar_connection_id',
                'appointment_gcal_connection_fk'
            )->references('id')->on('google_calendar_connections')->nullOnDelete();
            $table->string('google_event_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_google_calendar_events');
    }
};
