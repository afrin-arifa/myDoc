<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Doctor Assistant role (stored value "assistant") to users.role.
 *
 * The new value is appended, so every existing value, the default ("patient") and the role index are kept and no
 * existing row changes. MySQL alters the ENUM in place (appending a member is a metadata change in MySQL 8.0);
 * SQLite stores the enum as a CHECK constraint, so Laravel rebuilds the table there, copying every row.
 */
return new class extends Migration
{
    public const ROLES = ['patient', 'doctor', 'admin', 'assistant'];

    public const PREVIOUS_ROLES = ['patient', 'doctor', 'admin'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', self::ROLES)->default('patient')->change();
        });
    }

    /**
     * Rolling back would make assistant rows invalid, so it is refused while any exist rather than silently
     * deleting or rewriting accounts. Reassign or remove them deliberately first.
     */
    public function down(): void
    {
        $assistants = DB::table('users')->where('role', 'assistant')->count();

        if ($assistants > 0) {
            throw new RuntimeException("Cannot remove the assistant role: {$assistants} user(s) still have it.");
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', self::PREVIOUS_ROLES)->default('patient')->change();
        });
    }
};
