<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the appointment notification ledger hold SMS as well as email.
 *
 * Every existing row is an email, so the new channel column defaults to "email" and all P1.2 rows keep their
 * meaning. The identity becomes (appointment, channel, type, transition) so an email and an SMS for the same
 * event are independent. The new unique index is created before the old one is dropped: both start with
 * appointment_id, so MySQL's foreign key on appointment_id always has a supporting index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_notifications', function (Blueprint $table): void {
            $table->string('channel', 8)->default('email')->after('appointment_id');
            $table->timestamp('provider_requested_at')->nullable()->after('sent_at');
            $table->string('provider_reference', 64)->nullable()->after('provider_requested_at');
        });

        Schema::table('appointment_notifications', function (Blueprint $table): void {
            $table->unique(['appointment_id', 'channel', 'type', 'transition_key'], 'appointment_notifications_channel_identity_unique');
        });

        Schema::table('appointment_notifications', function (Blueprint $table): void {
            $table->dropUnique('appointment_notifications_identity_unique');
        });
    }

    /**
     * Rolling back removes SMS ledger rows (they cannot be represented without a channel); email rows are kept.
     */
    public function down(): void
    {
        DB::table('appointment_notifications')->where('channel', '!=', 'email')->delete();

        Schema::table('appointment_notifications', function (Blueprint $table): void {
            $table->unique(['appointment_id', 'type', 'transition_key'], 'appointment_notifications_identity_unique');
        });

        Schema::table('appointment_notifications', function (Blueprint $table): void {
            $table->dropUnique('appointment_notifications_channel_identity_unique');
            $table->dropColumn(['channel', 'provider_requested_at', 'provider_reference']);
        });
    }
};
