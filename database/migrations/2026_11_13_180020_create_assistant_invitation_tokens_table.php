<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hashed, expiring invitation tokens for Doctor Assistant accounts (the "assistant_invitations" password broker).
 * Same structure as doctor_invitation_tokens but a separate table, so doctor and assistant invitations can never be
 * used for each other and existing doctor invitation links are untouched. Raw tokens are never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_invitation_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    /**
     * Invitation tokens are short-lived and re-issuable, so dropping them only invalidates outstanding links.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_invitation_tokens');
    }
};
