<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token store for the "doctor_invitations" password broker. The schema mirrors Laravel's
     * password_reset_tokens table so the framework's DatabaseTokenRepository manages it: only a bcrypt hash
     * of each token is stored, there is at most one active invitation per email, and created_at drives expiry.
     */
    public function up(): void
    {
        Schema::create('doctor_invitation_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_invitation_tokens');
    }
};
