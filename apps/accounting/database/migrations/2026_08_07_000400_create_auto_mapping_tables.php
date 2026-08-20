<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auto_mapping_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('source_type', 80);
            $table->string('name', 160);
            $table->string('structure_hash', 64);
            $table->json('mapping');
            $table->boolean('is_active')->default(true);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['entity_id', 'source_type', 'structure_hash']);
            $table->index(['entity_id', 'source_type', 'is_active']);
        });

        Schema::create('auto_mapping_raw_data', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('source_type', 80);
            $table->string('structure_hash', 64);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 160)->nullable();
            $table->ulid('mapping_rule_id')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->ulid('received_by')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('mapping_rule_id')->references('id')->on('auto_mapping_rules')->nullOnDelete();
            $table->foreign('journal_id')->references('id')->on('journals')->nullOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['entity_id', 'source_type', 'idempotency_key']);
            $table->index(['entity_id', 'status', 'created_at']);
            $table->index(['entity_id', 'source_type', 'structure_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_mapping_raw_data');
        Schema::dropIfExists('auto_mapping_rules');
    }
};
