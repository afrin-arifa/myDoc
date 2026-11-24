<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in patients: patient records made by a doctor's staff for people without an account. They have a name and a
 * mobile number but no email, so nobody can sign in with them. Existing rows keep their email and stay unflagged; the
 * unique index on email is kept (it allows several NULLs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $this->keepRoleRule($table);
            $table->boolean('is_walk_in')->default(false)->after('role');
        });
    }

    /**
     * Refused while walk-in patients exist: they have no email to fall back to, and their appointments must be kept.
     */
    public function down(): void
    {
        $walkIns = DB::table('users')->whereNull('email')->count();

        if ($walkIns > 0) {
            throw new RuntimeException("Cannot remove walk-in patients: {$walkIns} user(s) have no email.");
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_walk_in');
            $table->string('email')->nullable(false)->change();
            $this->keepRoleRule($table);
        });
    }

    /**
     * SQLite rebuilds the table for a column change and would lose the CHECK constraint of the role enum, so the same
     * definition is declared again. Nothing changes on MySQL.
     */
    private function keepRoleRule(Blueprint $table): void
    {
        $table->enum('role', ['patient', 'doctor', 'admin', 'assistant'])->default('patient')->change();
    }
};
