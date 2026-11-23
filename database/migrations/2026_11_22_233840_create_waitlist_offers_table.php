<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waiting list offers: a held slot offered to one waiting patient until expires_at. The generated pending_slot_id
 * column is unique, so a slot can never have two pending offers at once (the same technique as
 * appointments.active_slot_id). Purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waitlist_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_slot_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->dateTime('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('offered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unsignedBigInteger('pending_slot_id')
                ->nullable()
                ->virtualAs("CASE WHEN status = 'pending' THEN appointment_slot_id ELSE NULL END");
            $table->unique('pending_slot_id');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_offers');
    }
};
