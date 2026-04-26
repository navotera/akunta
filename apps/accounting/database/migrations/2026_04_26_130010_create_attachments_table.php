<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Polymorphic — currently used for Journal, but extensible to JournalEntry,
            // Account, Partner, etc. without schema change.
            $table->string('attachable_type', 191);
            $table->ulid('attachable_id');
            // Denormalized entity scope for fast tenant-scoped queries + RLS readiness.
            $table->ulid('entity_id');
            $table->string('filename');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('checksum_sha256', 64)->nullable();
            $table->text('description')->nullable();
            $table->ulid('uploaded_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index(['entity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
