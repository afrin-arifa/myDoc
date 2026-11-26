<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient accounts a doctor or their assistant creates at the clinic. They start with a one-time password the
 * patient must replace at first sign-in, and stay linked to the doctor whose staff created them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('password_change_required')->default(false)->after('password');
            $table->timestamp('temporary_password_expires_at')->nullable()->after('password_change_required');
            $table->foreignId('registered_by_doctor_profile_id')->nullable()->after('is_walk_in')->constrained('doctor_profiles')->nullOnDelete();
            $table->foreignId('registered_by')->nullable()->after('registered_by_doctor_profile_id')->constrained('users')->nullOnDelete();
        });

        // SQLite rebuilds the table to add the foreign keys, which drops the role CHECK constraint; restore it.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('role', ['patient', 'doctor', 'admin', 'assistant'])->default('patient')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_by');
            $table->dropConstrainedForeignId('registered_by_doctor_profile_id');
            $table->dropColumn(['password_change_required', 'temporary_password_expires_at']);
        });
    }
};
