<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for medical document uploads, downloads and deletions. Rows outlive the document (the document
     * reference is nulled on delete) and never contain file contents, descriptions or storage paths.
     */
    public function up(): void
    {
        Schema::create('medical_document_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medical_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 16);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['patient_id', 'created_at']);
            $table->index(['medical_document_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_document_events');
    }
};
