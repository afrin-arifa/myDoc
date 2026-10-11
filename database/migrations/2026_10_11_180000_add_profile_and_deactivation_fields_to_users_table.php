<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable()->after('email');
            $table->text('address')->nullable()->after('phone');
            $table->timestamp('deactivated_at')->nullable()->after('remember_token');
            $table->index('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deactivated_at']);
            $table->dropColumn(['phone', 'address', 'deactivated_at']);
        });
    }
};
