<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notices a doctor or their assistant posts for that doctor's own patients (closures, changed hours, news), and
 * which patients have dismissed each one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            $table->text('body');
            $table->boolean('is_important')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['doctor_profile_id', 'expires_at']);
        });

        Schema::create('doctor_announcement_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['doctor_announcement_id', 'user_id'], 'announcement_dismissals_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_announcement_dismissals');
        Schema::dropIfExists('doctor_announcements');
    }
};
