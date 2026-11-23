<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Waiting list offers are delivered through the same notification ledger as appointment emails and SMS (claiming,
 * retries, the "unconfirmed SMS" rule and the admin issues page), but an offer has no appointment yet. So:
 *  - appointment_id becomes nullable (every existing row keeps its value);
 *  - waitlist_offer_id (nullable) names the offer, with its own unique identity (offer, channel, type, transition).
 * Exactly one of the two is set on every row; the application enforces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('appointment_id')->nullable()->change();
            $table->foreignId('waitlist_offer_id')->nullable()->after('appointment_id')->constrained()->cascadeOnDelete();
            $table->unique(['waitlist_offer_id', 'channel', 'type', 'transition_key'], 'appointment_notifications_offer_identity_unique');
        });
    }

    /**
     * Only possible while no offer notification exists (their rows have no appointment).
     */
    public function down(): void
    {
        if (DB::table('appointment_notifications')->whereNotNull('waitlist_offer_id')->exists()) {
            throw new RuntimeException('Cannot remove waiting list notifications from the ledger while offer notifications exist.');
        }

        Schema::table('appointment_notifications', function (Blueprint $table) {
            $table->dropUnique('appointment_notifications_offer_identity_unique');
            $table->dropConstrainedForeignId('waitlist_offer_id');
        });
        Schema::table('appointment_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('appointment_id')->nullable(false)->change();
        });
    }
};
